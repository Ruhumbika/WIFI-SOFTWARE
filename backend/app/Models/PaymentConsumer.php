<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentConsumer extends Model
{
    protected $fillable = ['uuid', 'business_id', 'code', 'token_hash', 'return_origin', 'active'];
    protected $hidden = ['token_hash'];
    protected $casts = ['active' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
