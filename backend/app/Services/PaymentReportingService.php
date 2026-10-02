<?php
namespace App\Services;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
final class PaymentReportingService
{
    public const TIMEZONE = 'Africa/Dar_es_Salaam';
    public function bounds(string $period, ?string $from = null, ?string $to = null): array {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $start = match ($period) {
            'yesterday' => $today->subDay(), '7d' => $today->subDays(6), '30d' => $today->subDays(29),
            'custom' => CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay(), default => $today,
        };
        $end = match ($period) {
            'yesterday' => $today, 'custom' => CarbonImmutable::parse($to, self::TIMEZONE)->startOfDay()->addDay(), default => $today->addDay(),
        };
        return [$start->utc(), $end->utc()];
    }
    public function ledger(Request $r) {
        $data = $r->validate([
            'date'=>['nullable',Rule::in(['all','today','yesterday','7d','30d','custom'])],
            'from'=>['required_if:date,custom','nullable','date_format:Y-m-d'],
            'to'=>['required_if:date,custom','nullable','date_format:Y-m-d','after_or_equal:from'],
            'status'=>['nullable',Rule::in(['all','completed','pending','failed','expired','voided'])],
            'plan_id'=>['nullable','integer','exists:plans,id'], 'search'=>['nullable','string','max:100'],
            'page'=>['nullable','integer','min:1'], 'per_page'=>['nullable','integer','min:1','max:100'],
        ]);
        $q = Payment::with('order.plan')->leftJoin('orders','orders.id','=','payments.order_id')->select('payments.*');
        if (($data['status'] ?? 'all') !== 'all') $q->where('payments.status',$data['status']);
        if (!empty($data['plan_id'])) $q->whereHas('order',fn ($o)=>$o->where('plan_id',$data['plan_id']));
        if (!empty($data['search'])) {
            $term = '%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($data['search'])).'%';
            $q->where(fn ($s)=>$s->whereRaw("payments.reference LIKE ? ESCAPE '!'",[$term])
                ->orWhereRaw("payments.external_reference LIKE ? ESCAPE '!'",[$term])
                ->orWhereHas('order',fn ($o)=>$o->whereRaw("customer_phone LIKE ? ESCAPE '!'",[$term])->orWhereRaw("order_number LIKE ? ESCAPE '!'",[$term])));
        }
        if (($data['date'] ?? 'all') !== 'all') {
            [$start,$end] = $this->bounds($data['date'],$data['from'] ?? null,$data['to'] ?? null);
            $q->where(fn ($dates)=>$dates->where(fn ($paid)=>$paid->where('payments.status','completed')->where('payments.completed_at','>=',$start)->where('payments.completed_at','<',$end))
                ->orWhere(fn ($other)=>$other->where('payments.status','!=','completed')->where('payments.updated_at','>=',$start)->where('payments.updated_at','<',$end)));
        }
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->utc();
        return $q->orderByRaw("CASE WHEN payments.status = 'pending' AND payments.updated_at < ? THEN 1 ELSE 0 END",[$today])
            ->orderByRaw("COALESCE(orders.customer_phone, '')")
            ->orderByDesc(DB::raw("CASE WHEN payments.status = 'completed' THEN payments.completed_at ELSE payments.updated_at END"))
            ->orderByDesc('payments.id')->paginate($data['per_page'] ?? 50)->withQueryString();
    }
    private function paid(CarbonImmutable $start, CarbonImmutable $end): Builder {
        return Payment::where('payments.status','completed')->where('payments.completed_at','>=',$start)->where('payments.completed_at','<',$end);
    }
    private function totals(Builder $q): array {
        return $q->selectRaw('currency, COUNT(*) AS sales, SUM(amount) AS gross, SUM(CASE WHEN net_amount IS NOT NULL AND fee_amount IS NOT NULL AND settlement_currency = currency THEN net_amount ELSE NULL END) AS net, SUM(CASE WHEN net_amount IS NOT NULL AND fee_amount IS NOT NULL AND settlement_currency = currency THEN fee_amount ELSE NULL END) AS fees, SUM(CASE WHEN net_amount IS NOT NULL AND fee_amount IS NOT NULL AND settlement_currency = currency THEN 1 ELSE 0 END) AS settled_sales')->groupBy('currency')->get()->map(fn ($row)=>[
            'currency'=>$row->currency,'sales'=>(int)$row->sales,'gross'=>(int)$row->gross,
            'net'=>$row->net === null ? null : (int)$row->net,'fees'=>$row->fees === null ? null : (int)$row->fees,
            'settled_sales'=>(int)$row->settled_sales,'missing_settlement'=>(int)$row->sales-(int)$row->settled_sales,
        ])->all();
    }
    public function analytics(string $period): array {
        [$start,$end] = $this->bounds($period);
        $driver = DB::connection()->getDriverName();
        $format = $period === 'today' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        // Stored timestamps remain UTC. Tanzania has a fixed UTC+03 offset without DST.
        $bucket = $driver === 'sqlite' ? "strftime('$format', completed_at, '+3 hours')" : "DATE_FORMAT(DATE_ADD(completed_at, INTERVAL 3 HOUR), '$format')";
        $trend = $this->paid($start,$end)->selectRaw("$bucket AS bucket, currency, COUNT(*) AS sales, SUM(amount) AS gross, SUM(CASE WHEN settlement_currency = currency AND fee_amount IS NOT NULL THEN net_amount ELSE NULL END) AS net, SUM(CASE WHEN settlement_currency = currency AND fee_amount IS NOT NULL AND net_amount IS NOT NULL THEN 1 ELSE 0 END) AS settled_sales")
            ->groupByRaw("$bucket, currency")->orderBy('bucket')->get();
        $packages = $this->paid($start,$end)->join('orders','orders.id','=','payments.order_id')->join('plans','plans.id','=','orders.plan_id')
            ->selectRaw('plans.id AS plan_id, plans.name, payments.currency, COUNT(*) AS sales, SUM(payments.amount) AS gross, SUM(CASE WHEN payments.settlement_currency = payments.currency AND payments.fee_amount IS NOT NULL THEN payments.net_amount ELSE NULL END) AS net, SUM(CASE WHEN payments.settlement_currency = payments.currency AND payments.fee_amount IS NOT NULL AND payments.net_amount IS NOT NULL THEN 1 ELSE 0 END) AS settled_sales')
            ->groupBy('plans.id','plans.name','payments.currency')->orderByDesc('sales')->get();
        $status = Payment::where(fn ($q)=>$q->where(fn ($paid)=>$paid->where('status','completed')->where('completed_at','>=',$start)->where('completed_at','<',$end))
            ->orWhere(fn ($other)=>$other->where('status','!=','completed')->where('updated_at','>=',$start)->where('updated_at','<',$end)))
            ->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count','status')->map(fn($n)=>(int)$n);
        return ['period'=>$period,'timezone'=>self::TIMEZONE,'from'=>$start->setTimezone(self::TIMEZONE)->toDateString(),'to'=>$end->setTimezone(self::TIMEZONE)->subDay()->toDateString(),'totals'=>$this->totals($this->paid($start,$end)), 'trend'=>$trend,'packages'=>$packages,'statuses'=>$status];
    }
}
