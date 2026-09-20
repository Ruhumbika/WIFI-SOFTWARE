<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    protected $fillable = ['payment_id', 'event_id', 'event_type', 'reference', 'payload', 'processed_at'];
    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
