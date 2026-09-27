<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Services\PhoneNormalizer;
use App\Services\VoucherAccessService;
use App\Services\VoucherConnectionService;
use App\Services\VoucherEvents;
use App\Services\VoucherRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PublicVoucherController extends Controller
{
    public function __construct(private VoucherAccessService $access, private VoucherRecoveryService $recovery, private VoucherConnectionService $connection) {}

    private function context(Request $r): array
    {
        return $r->validate(['device_mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'], 'login_url' => ['nullable', 'url', 'max:500']]);
    }

    private function credentials(Request $r): Voucher
    {
        $data = $r->validate(['code' => 'required|string|max:80', 'pin' => 'required|string|max:80']);
        $v = Voucher::where('code', strtoupper(trim($data['code'])))->first();
        abort_unless($v && hash_equals((string) $v->secret, $data['pin']), 422, "We couldn't verify that voucher. Check the code and PIN.");

        return $v;
    }

    public function redeem(Request $r)
    {
        $data = $this->context($r);
        $v = $this->credentials($r);
        $result = $this->connection->prepare($v, $data['device_mac'] ?? null, $data['login_url'] ?? null);
        $result['voucher'] = $this->access->payload($v, true);
        $result['recovery_token'] = $this->access->token($v, 'redeem');
        $result['expires_at'] = now()->addMinutes(15)->toIso8601String();

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function lookup(Request $r)
    {
        $data = $r->validate(['phone' => 'required|string|max:24']);
        $phone = PhoneNormalizer::normalize($data['phone']);
        $page = Voucher::with('plan')->where('customer_phone', $phone)->latest('id')->paginate(15);

        return response()->json(['vouchers' => $page->getCollection()->map(fn ($v) => $this->access->payload($v)), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $r)
    {
        $data = $r->validate(['phone' => 'required|string|max:24', 'voucher_uuid' => 'required|uuid', 'recovery_pin' => 'required|digits:6']);
        $v = $this->recovery->verify($data['voucher_uuid'], PhoneNormalizer::normalize($data['phone']), $data['recovery_pin'], (string) $r->ip());

        return response()->json(['recovery_token' => $this->access->token($v), 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'voucher' => $this->access->payload($v, true)])->header('Cache-Control', 'no-store');
    }

    public function mine(Request $r)
    {
        $data = $r->validate(['voucher_uuid' => 'required|uuid']);
        $v = Voucher::where('uuid', $data['voucher_uuid'])->firstOrFail();
        $this->access->authorize($r, $v);

        return response()->json(['vouchers' => [$this->access->payload($v, true)]])->header('Cache-Control', 'no-store');
    }

    public function show(Request $r, Voucher $voucher)
    {
        $this->access->authorize($r, $voucher, false);

        return response()->json($this->access->payload($voucher, true))->header('Cache-Control', 'no-store');
    }

    public function issue(Request $r, Voucher $voucher)
    {
        abort_unless($this->access->orderOwns($r, $voucher), 403);

        return response()->json(['recovery_pin' => $this->recovery->issue($voucher)])->header('Cache-Control', 'no-store');
    }

    public function prepare(Request $r, Voucher $voucher)
    {
        $this->access->authorize($r, $voucher, false);
        $data = $this->context($r);

        return $this->connection->prepare($voucher, $data['device_mac'] ?? null, $data['login_url'] ?? null);
    }

    public function connection(Request $r, Voucher $voucher)
    {
        $this->access->authorize($r, $voucher, false);
        $data = $this->context($r);

        return $this->connection->connection($voucher, $data['device_mac'] ?? null);
    }

    public function claim(Request $r)
    {
        $v = $this->credentials($r);
        $data = $r->validate(['phone' => 'required|string|max:24']);
        $phone = PhoneNormalizer::normalize($data['phone']);
        $result = DB::transaction(function () use ($v, $phone) {
            $locked = Voucher::lockForUpdate()->findOrFail($v->id);
            // Credentials are checked again under the lock in case rotation won the race.
            abort_unless(hash_equals((string) $v->secret, (string) $locked->secret), 409, 'Voucher credentials changed.');
            abort_if($locked->customer_phone || $locked->order_id, 409, 'This voucher is already registered.');
            abort_if(in_array($locked->status, ['disabled', 'revoked', 'expired'], true) || $locked->expires_at?->isPast(), 409, 'This voucher is unavailable.');
            $locked->forceFill(['customer_phone' => $phone, 'claimed_at' => now()])->save();
            VoucherEvents::record($locked, 'voucher_claimed');
            $pin = $this->recovery->issue($locked);
            $locked->refresh();

            return ['recovery_pin' => $pin, 'recovery_token' => $this->access->token($locked), 'voucher' => $this->access->payload($locked, true), 'expires_at' => now()->addMinutes(15)->toIso8601String()];
        });

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function transfer(Request $r, Voucher $voucher)
    {
        $this->access->authorize($r, $voucher);
        $data = $this->context($r);
        $reason = $r->validate(['reason' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($r, $voucher, $data, $reason) {
            $v = Voucher::lockForUpdate()->findOrFail($voucher->id);
            $this->access->authorize($r, $v);
            abort_unless($v->device_mac, 409, 'This voucher has no device binding.');
            abort_if($v->expires_at?->isPast() || in_array($v->status, ['disabled', 'revoked', 'expired'], true), 409, 'This voucher is unavailable.');
            $pending = DB::table('voucher_device_transfer_requests')->where('voucher_id', $v->id)->where('status', 'pending')->first();
            if (! $pending) {
                DB::table('voucher_device_transfer_requests')->insert(['voucher_id' => $v->id, 'customer_phone' => $v->customer_phone, 'requested_mac' => isset($data['device_mac']) ? strtoupper($data['device_mac']) : null, 'current_mac' => $v->device_mac, 'status' => 'pending', 'reason' => $reason['reason'] ?? null, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                VoucherEvents::record($v, 'device_transfer_requested');
            }

            return ['state' => 'pending', 'message' => 'Your request is awaiting administrator review.'];
        });
    }

    public function compromise(Request $r, Voucher $voucher)
    {
        $this->access->authorize($r, $voucher);

        return DB::transaction(function () use ($r, $voucher) {
            $v = Voucher::lockForUpdate()->findOrFail($voucher->id);
            $this->access->authorize($r, $v);
            if (! $v->compromised_at) {
                $v->forceFill(['compromised_at' => now()])->save();
                VoucherEvents::record($v,'voucher_compromised');
            }

            return ['state' => 'pending', 'message' => 'Your report is awaiting administrator review.'];
        });
    }
}
