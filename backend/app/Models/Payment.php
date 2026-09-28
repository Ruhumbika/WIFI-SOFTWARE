<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'business_id', 'payment_gateway_account_id', 'session_reference', 'checkout_url', 'uuid', 'order_id', 'provider', 'reference', 'external_reference',
        'status', 'amount', 'currency', 'idempotency_key', 'provider_payload',
        'completed_at', 'failed_reason',
    ];
    protected $casts = ['provider_payload' => 'array', 'completed_at' => 'datetime'];

    protected $hidden = ['provider_payload'];
    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (!$payment->business_id) $payment->business_id = Order::findOrFail($payment->order_id)->business_id;
        });
    }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function gatewayAccount(): BelongsTo { return $this->belongsTo(PaymentGatewayAccount::class, 'payment_gateway_account_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function events(): HasMany { return $this->hasMany(PaymentEvent::class); }
}
