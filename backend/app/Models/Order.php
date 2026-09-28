<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'business_id', 'uuid', 'order_number', 'plan_id', 'customer_phone', 'customer_name',
        'customer_email', 'device_mac', 'amount', 'currency', 'status',
        'paid_at', 'completed_at', 'failed_reason',
    ];
    protected $casts = ['paid_at' => 'datetime', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (!$order->business_id) $order->business_id = Business::where('code', config('snippe.business_code'))->where('status', 'active')->firstOrFail()->id;
        });
    }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function voucher(): HasOne { return $this->hasOne(Voucher::class); }
}
