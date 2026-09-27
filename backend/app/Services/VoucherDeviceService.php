<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherDeviceOperation;
use Illuminate\Support\Facades\DB;

final class VoucherDeviceService
{
    public function __construct(private MikrotikRestClient $router) {}

    public function operate(Voucher $voucher, string $action, string $key, int $actor, ?int $transfer = null): array
    {
        $op = DB::transaction(function () use ($voucher, $action, $key, $actor, $transfer) {
            $v = Voucher::lockForUpdate()->findOrFail($voucher->id);
            $existing = VoucherDeviceOperation::where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->voucher_id === $v->id && $existing->action === $action && $existing->transfer_request_id === $transfer, 409, 'Operation key already used.');

                return $existing;
            }
            $pending = VoucherDeviceOperation::where('voucher_id', $v->id)->where('state', '!=', 'completed')->first();
            if ($pending) {
                abort_unless($pending->action === $action && $pending->transfer_request_id === $transfer, 409, 'Resolve the pending router operation first.');

                return $pending;
            }
            abort_if(in_array($v->status, ['disabled', 'revoked', 'expired', 'provision_pending'], true) || $v->expires_at?->isPast(), 409, 'Voucher is unavailable for this action.');
            if ($transfer) {
                abort_unless(DB::table('voucher_device_transfer_requests')->where('id', $transfer)->where('voucher_id', $v->id)->where('status', 'pending')->exists(), 409, 'No pending transfer.');
            }
            $secret = null;
            if ($action === 'rotate') {
                do {
                    $secret = (string) random_int(100000, 999999);
                } while (hash_equals((string) $v->secret, $secret) || ($v->recovery_pin_hash && \Illuminate\Support\Facades\Hash::check($secret, $v->recovery_pin_hash)));
            }
            if ($action === 'release') {
                abort_unless($v->device_mac, 409, 'Voucher has no device binding.');
            }

            return VoucherDeviceOperation::create(['voucher_id' => $v->id, 'request_key' => $key, 'action' => $action, 'state' => 'pending_reconciliation', 'target_secret' => $secret, 'actor_id' => $actor, 'transfer_request_id' => $transfer]);
        });
        if ($op->state === 'completed') {
            return ['state' => 'completed'];
        }
        try {
            // Serialize sync and admin actions on the voucher. The operation is committed
            // separately so a crash after a router write can resume with the same secret.
            return DB::transaction(function () use ($op) {
                $v = Voucher::lockForUpdate()->findOrFail($op->voucher_id);
                $op->refresh();
                if ($op->state === 'completed') {
                    return ['state' => 'completed'];
                }
                $this->router->reconcileVoucherDevice($v, $op->action, $op->target_secret);
                $changes = ['recovery_token_version' => $v->recovery_token_version + 1];
                if ($op->action === 'release') {
                    $changes['device_mac'] = null;
                    $changes['transfer_count'] = $v->transfer_count + 1;
                } else {
                    $changes['secret'] = $op->target_secret;
                    $changes['compromised_at'] = null;
                }
                VoucherEvents::record($v, $op->action === 'release' ? 'device_released' : 'credentials_rotated', null, $op->actor_id);
                $v->forceFill($changes)->save();
                $v->sessions()->whereNull('ended_at')->update(['ended_at' => now()]);
                if ($op->transfer_request_id) {
                    DB::table('voucher_device_transfer_requests')->where('id', $op->transfer_request_id)->update(['status' => 'approved', 'resolved_at' => now(), 'resolved_by' => $op->actor_id, 'updated_at' => now()]);
                    VoucherEvents::record($v, 'device_transfer_approved', null, $op->actor_id);
                }
                $op->forceFill(['state' => 'completed', 'target_secret' => null])->save();

                return ['state' => 'completed'];
            });
        } catch (\Throwable) {
            return ['state' => 'pending_reconciliation', 'message' => 'Router changes are not yet confirmed. Retry this operation; voucher validity and payment are preserved.'];
        }
    }
}
