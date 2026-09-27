<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'uuid', 'code', 'secret', 'plan_id', 'order_id', 'customer_phone',
        'device_mac', 'status', 'mikrotik_id', 'activated_at', 'expires_at',
        'provisioned_at', 'last_synced_at', 'provision_error',
    ];
    protected $hidden = ['secret', 'recovery_pin_hash'];
    protected $casts = [
        'secret' => 'encrypted',
        'recovery_pin_created_at'=>'datetime', 'recovery_pin_issued_at'=>'datetime',
        'compromised_at'=>'datetime', 'claimed_at'=>'datetime',
        'recovery_token_version'=>'integer', 'transfer_count'=>'integer',
        'activated_at' => 'datetime', 'expires_at' => 'datetime',
        'provisioned_at' => 'datetime', 'last_synced_at' => 'datetime',
    ];

    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function sessions(): HasMany { return $this->hasMany(HotspotSession::class); }
}
