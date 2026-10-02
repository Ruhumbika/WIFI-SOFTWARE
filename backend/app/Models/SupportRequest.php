<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupportRequest extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['open_key'];
    protected $casts = ['technical_snapshot'=>'array','resolved_at'=>'datetime'];
    public function voucher(): BelongsTo { return $this->belongsTo(Voucher::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
