<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'uuid', 'order_id', 'provider', 'reference', 'external_reference',
        'status', 'amount', 'currency', 'idempotency_key', 'provider_payload',
        'completed_at', 'failed_reason',
    ];
    protected $casts = ['provider_payload' => 'array', 'completed_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function events(): HasMany { return $this->hasMany(PaymentEvent::class); }
}
