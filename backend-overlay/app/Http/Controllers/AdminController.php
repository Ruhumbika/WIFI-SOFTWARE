<?php

namespace App\Http\Controllers;

use App\Models\HotspotSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\MikrotikRestClient;
use App\Services\VoucherProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class AdminController extends Controller
{
    public function dashboard()
    {
        return [
            'online'=>HotspotSession::whereNull('ended_at')->where('last_seen_at','>=',now()->subMinutes(2))->count(),
            'sales_today'=>Payment::where('status','completed')->whereDate('completed_at',today())->count(),
            'revenue_today'=>Payment::where('status','completed')->whereDate('completed_at',today())->sum('amount'),
            'active_vouchers'=>Voucher::where('status','active')->count(),
            'pending_provision'=>Voucher::where('status','provision_pending')->count(),
            'recent_payments'=>Payment::with('order.plan')->latest()->limit(10)->get(),
        ];
    }

    public function plans() { return Plan::orderBy('price')->get(); }

    public function storePlan(Request $request)
    {
        $data = $this->planData($request);
        $data['uuid']=(string)Str::uuid();
        $data['mikrotik_profile_name']=config('mikrotik.profile_prefix').Str::upper($data['code']);
        return response()->json(Plan::create($data), 201);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data=$this->planData($request, $plan->id);
        $data['mikrotik_profile_name']=config('mikrotik.profile_prefix').Str::upper($data['code']);
        $plan->update($data);
        return $plan->fresh();
    }

    public function vouchers()
    {
        $page = Voucher::with(['plan','order'])->latest()->paginate(50);
        $page->getCollection()->transform(function (Voucher $voucher) {
            $row = $voucher->toArray();
            $row['password'] = $voucher->secret;
            return $row;
        });
        return $page;
    }

    public function generateVouchers(Request $request, VoucherProvisioner $provisioner)
    {
        $data=$request->validate(['plan_id'=>['required','exists:plans,id'],'quantity'=>['required','integer','min:1','max:100']]);
        $rows=[];
        for($i=0;$i<$data['quantity'];$i++) {
            $v=$provisioner->createVoucher(['plan_id'=>$data['plan_id']]);
            $rows[]=$provisioner->provision($v);
        }
        return response()->json(collect($rows)->map(fn (Voucher $v) => array_merge($v->toArray(), ['password'=>$v->secret]))->values(), 201);
    }

    public function retryVoucher(Voucher $voucher, VoucherProvisioner $provisioner)
    {
        return $provisioner->provision($voucher);
    }

    public function payments() { return Payment::with('order.plan')->latest()->paginate(50); }
    public function orders() { return Order::with(['plan','payments','voucher'])->latest()->paginate(50); }
    public function sessions() { return HotspotSession::with('voucher.plan')->latest('last_seen_at')->paginate(100); }

    public function disconnectSession(HotspotSession $session, MikrotikRestClient $mikrotik)
    {
        $mikrotik->disconnect($session->mikrotik_id);
        $session->forceFill(['ended_at'=>now()])->save();
        return ['message'=>'Disconnected'];
    }

    public function routerHealth(MikrotikRestClient $mikrotik)
    {
        try { return ['connected'=>true,'resource'=>$mikrotik->resource()]; }
        catch(Throwable $e) { return response()->json(['connected'=>false,'message'=>$e->getMessage()],503); }
    }

    private function planData(Request $request, ?int $ignoreId=null): array
    {
        return $request->validate([
            'name'=>['required','string','max:100'],
            'code'=>['required','alpha_dash','max:30','unique:plans,code'.($ignoreId?','.$ignoreId:'')],
            'description'=>['nullable','string','max:500'],
            'price'=>['required','integer','min:500'],
            'currency'=>['sometimes','in:TZS'],
            'duration_seconds'=>['required','integer','min:60'],
            'rate_limit'=>['required','string','max:30'],
            'data_limit_bytes'=>['nullable','integer','min:1'],
            'active'=>['required','boolean'],
        ]);
    }
}
