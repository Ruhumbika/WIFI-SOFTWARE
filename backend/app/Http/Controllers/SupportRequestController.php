<?php
namespace App\Http\Controllers;
use App\Models\{Business,Order,SupportRequest,Voucher};
use App\Services\{PhoneNormalizer,VoucherAccessService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
final class SupportRequestController extends Controller
{
    public function store(Request $r) { return $this->create($r); }
    public function orderStore(Request $r, string $uuid) { return $this->create($r,$uuid); }
    public function voucherStore(Request $r, Voucher $voucher) { return $this->create($r,null,$voucher); }
    private function create(Request $r, ?string $uuid = null, ?Voucher $voucher = null) {
        $data = $r->validate([
            'phone'=>['nullable','string','max:24'],
            'device_mac'=>['nullable','regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'connection_state'=>['nullable',Rule::in(['idle','checking','connecting','ready','active','online','connected','offline','unknown','manual','preparing','provision_failed','router_unavailable','device_mismatch','active_other_device','expired','unavailable'])],
        ]);
        $order = null;
        if ($voucher) {
            app(VoucherAccessService::class)->authorize($r,$voucher,false);
            $order = $voucher->order;
        } elseif ($uuid) {
            $order = Order::where('uuid',$uuid)->firstOrFail();
            try { $owned = hash_equals($order->uuid, Crypt::decryptString((string)$r->header('X-Order-Token'))); } catch (\Throwable) { $owned = false; }
            abort_unless($owned,403,'Verify this order to continue.');
            $voucher = $order->voucher;
        }
        $phone = $order?->customer_phone ?? $voucher?->customer_phone ?? (!empty($data['phone']) ? PhoneNormalizer::normalize($data['phone']) : null);
        if (! $phone) {
            throw ValidationException::withMessages(['phone'=>'Enter a valid Tanzania mobile number.']);
        }
        $mac = isset($data['device_mac']) ? strtoupper($data['device_mac']) : null;
        $businessId = $order?->business_id ?? Business::where('code',config('snippe.business_code'))->where('status','active')->value('id');
        // Only verified relations are linked; anonymous context never grants access to a voucher/order.
        $identity = $voucher ? 'voucher:'.$voucher->id : ($order ? 'order:'.$order->id : ($phone ? 'phone:'.$phone : ($mac ? 'device:'.$mac : 'ip:'.$r->ip())));
        $key = hash('sha256', $businessId.'|'.$identity);
        $attributes = ['uuid'=>(string)Str::uuid(),'business_id'=>$businessId,'voucher_id'=>$voucher?->id,'order_id'=>$order?->id,
            'plan_id'=>$voucher?->plan_id ?? $order?->plan_id,'payment_id'=>$order?->payments()->latest('id')->value('id'),
            'customer_phone'=>$phone,'device_mac'=>$mac,'connection_state'=>$data['connection_state'] ?? null,
            'technical_snapshot'=>['source'=>$voucher ? 'voucher' : ($order ? 'order' : 'anonymous'),'connection_state_is_reported'=>true],
            'status'=>'open','open_key'=>$key];
        $support = SupportRequest::firstOrCreate(['open_key'=>$key],$attributes);
        return response()->json(['uuid'=>$support->uuid,'status'=>$support->status,'duplicate'=>!$support->wasRecentlyCreated,
            'message'=>'Ombi la msaada limetumwa. Tutawasiliana nawe.'], $support->wasRecentlyCreated ? 201 : 200)->header('Cache-Control','no-store');
    }
    public function index(Request $r) {
        $data = $r->validate(['search'=>['nullable','string','max:100'],'status'=>['nullable',Rule::in(['all','open','contacted','resolved','cancelled'])],'page'=>['nullable','integer','min:1']]);
        return SupportRequest::with(['voucher:id,code','order:id,order_number','plan:id,name','payment:id,reference'])
            ->when(($data['status'] ?? 'open') !== 'all',fn ($q)=>$q->where('status',$data['status'] ?? 'open'))
            ->when(!empty($data['search']), function ($q) use ($data) {
                $term = '%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($data['search'])).'%';
                $q->where(fn ($s) => $s->whereRaw("customer_phone LIKE ? ESCAPE '!'",[$term])
                    ->orWhereRaw("device_mac LIKE ? ESCAPE '!'",[$term])
                    ->orWhereHas('voucher',fn ($v) => $v->whereRaw("code LIKE ? ESCAPE '!'",[$term]))
                    ->orWhereHas('order',fn ($o) => $o->whereRaw("order_number LIKE ? ESCAPE '!'",[$term]))
                    ->orWhereHas('payment',fn ($p) => $p->whereRaw("reference LIKE ? ESCAPE '!'",[$term])));
            })
            ->latest('id')->paginate(30)->withQueryString();
    }
    public function history(Request $r, SupportRequest $supportRequest) {
        $data = $r->validate(['page'=>['nullable','integer','min:1']]);
        $phone = $supportRequest->customer_phone;
        $businessId = $supportRequest->business_id;
        $orders = Order::where('business_id',$businessId)->where('customer_phone',$phone);
        $paymentsQuery = \App\Models\Payment::where('business_id',$businessId)
            ->whereHas('order',fn ($q)=>$q->where('customer_phone',$phone));
        $payments = $paymentsQuery->with('order:id,order_number,plan_id','order.plan:id,name')
            ->orderByDesc(DB::raw("CASE WHEN status = 'completed' THEN completed_at ELSE updated_at END"))->orderByDesc('id')
            ->paginate(20,['id','order_id','reference','status','amount','currency','completed_at','created_at','updated_at']);
        $voucherQuery = Voucher::where(function ($q) use ($orders,$phone,$businessId,$supportRequest) {
            $q->whereIn('order_id',(clone $orders)->select('id'));
            // Manual vouchers have no business column; only the configured business owns this shared manual inventory.
            $configured = Business::where('code',config('snippe.business_code'))->where('status','active')->value('id');
            if ((int)$configured === (int)$businessId) $q->orWhere(fn ($manual)=>$manual->whereNull('order_id')->where('customer_phone',$phone));
            if ($supportRequest->voucher_id) $q->orWhere('id',$supportRequest->voucher_id);
        });
        $vouchers = (clone $voucherQuery)->with('plan:id,name')->latest('created_at')->latest('id')
            ->paginate(20,['id','order_id','plan_id','code','status','created_at','activated_at','expires_at']);
        $paymentIds = (clone $paymentsQuery)->select('id');
        $voucherIds = (clone $voucherQuery)->select('id');
        $timeline = collect([['source'=>'support','event'=>'Support requested','time'=>$supportRequest->created_at->toISOString(),'reference'=>$supportRequest->uuid]]);
        foreach ($payments->items() as $payment) {
            $timeline->push(['source'=>'payment','event'=>'Payment initiated','time'=>$payment->created_at->toISOString(),'reference'=>$payment->reference]);
            if ($payment->completed_at) $timeline->push(['source'=>'payment','event'=>'Payment confirmed','time'=>$payment->completed_at->toISOString(),'reference'=>$payment->reference]);
        }
        foreach ($vouchers->items() as $voucher) {
            $timeline->push(['source'=>'voucher','event'=>'Voucher generated','time'=>$voucher->created_at->toISOString(),'reference'=>$voucher->code]);
            if ($voucher->activated_at) $timeline->push(['source'=>'voucher','event'=>'First login','time'=>$voucher->activated_at->toISOString(),'reference'=>$voucher->code]);
        }
        $timeline = $timeline->concat(\App\Models\PaymentEvent::whereIn('payment_id',$paymentIds)->latest('created_at')->limit(100)
            ->get(['payment_id','event_type','reference','created_at','processed_at'])->map(fn ($e)=>[
                'source'=>'payment','event'=>$e->event_type,'time'=>$e->processed_at?->toISOString() ?? $e->created_at->toISOString(),'reference'=>$e->reference,
            ]));
        $timeline = $timeline->concat(DB::table('voucher_events')->whereIn('voucher_id',$voucherIds)->orderByDesc('occurred_at')->limit(100)
            ->get(['voucher_id','event_type','occurred_at'])->map(fn ($e)=>['source'=>'voucher','event'=>$e->event_type,'time'=>\Carbon\CarbonImmutable::parse($e->occurred_at,'UTC')->toISOString(),'reference'=>'Voucher #'.$e->voucher_id]));
        $sessions = \App\Models\HotspotSession::whereIn('voucher_id',(clone $voucherQuery)->select('id'))->latest('started_at')->limit(50)->get(['voucher_id','started_at','ended_at']);
        foreach ($sessions as $session) {
            if ($session->started_at) $timeline->push(['source'=>'session','event'=>'Session started','time'=>$session->started_at->toISOString(),'reference'=>'Voucher #'.$session->voucher_id]);
            if ($session->ended_at) $timeline->push(['source'=>'session','event'=>'Session ended','time'=>$session->ended_at->toISOString(),'reference'=>'Voucher #'.$session->voucher_id]);
        }
        return response()->json(['phone'=>$phone,'timezone'=>'Africa/Dar_es_Salaam','payments'=>$payments,'vouchers'=>$vouchers,
            'timeline'=>$timeline->sortByDesc('time')->values()->take(100)->values(),
            'history_note'=>'Phone-matched history, newest first. Events show recorded activity only; raw provider payloads and credentials are excluded.'])->header('Cache-Control','no-store');
    }
    public function update(Request $r, SupportRequest $supportRequest) {
        $data = $r->validate(['status'=>['required',Rule::in(['contacted','resolved','cancelled'])]]);
        return DB::transaction(function () use ($r,$supportRequest,$data) {
            $record = SupportRequest::lockForUpdate()->findOrFail($supportRequest->id);
            abort_unless(in_array($record->status,['open','contacted'],true) || $record->status === $data['status'],409,'This request is already closed.');
            $closed = in_array($data['status'],['resolved','cancelled'],true);
            $record->forceFill(['status'=>$data['status'],'updated_by'=>$r->user()->id,
                'open_key'=>$closed ? null : $record->open_key,'resolved_at'=>$closed ? ($record->resolved_at ?? now()) : null])->save();
            return ['uuid'=>$record->uuid,'status'=>$record->status];
        });
    }
}
