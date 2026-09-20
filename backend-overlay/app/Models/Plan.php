<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'uuid', 'name', 'code', 'description', 'price', 'currency',
        'duration_seconds', 'rate_limit', 'data_limit_bytes',
        'mikrotik_profile_name', 'active',
    ];

    protected $casts = [
        'price' => 'integer',
        'duration_seconds' => 'integer',
        'data_limit_bytes' => 'integer',
        'active' => 'boolean',
    ];

    public function vouchers(): HasMany { return $this->hasMany(Voucher::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
}
