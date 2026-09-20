<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotSession extends Model
{
    protected $fillable = [
        'voucher_id', 'mikrotik_id', 'mac_address', 'ip_address', 'login_by',
        'started_at', 'last_seen_at', 'ended_at', 'bytes_in', 'bytes_out', 'uptime',
    ];
    protected $casts = ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'ended_at' => 'datetime'];
    public function voucher(): BelongsTo { return $this->belongsTo(Voucher::class); }
}
