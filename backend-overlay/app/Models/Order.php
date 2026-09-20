<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'uuid', 'order_number', 'plan_id', 'customer_phone', 'customer_name',
        'customer_email', 'device_mac', 'amount', 'currency', 'status',
        'paid_at', 'completed_at', 'failed_reason',
    ];
    protected $casts = ['paid_at' => 'datetime', 'completed_at' => 'datetime'];

    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function voucher(): HasOne { return $this->hasOne(Voucher::class); }
}
