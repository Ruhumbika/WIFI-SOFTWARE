<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PaymentGatewayAccount extends Model
{
    protected $fillable = ['uuid','business_id','provider','api_key_encrypted','webhook_secret_encrypted','base_url','webhook_url','payment_profile_id','active'];
    protected $hidden = ['api_key_encrypted','webhook_secret_encrypted'];
    protected $casts = ['api_key_encrypted'=>'encrypted','webhook_secret_encrypted'=>'encrypted','active'=>'boolean'];
    protected $appends = ['api_key_configured','webhook_secret_configured'];
    public function getRouteKeyName(): string { return 'uuid'; }
    public function getApiKeyConfiguredAttribute(): bool { return !empty($this->attributes['api_key_encrypted']); }
    public function getWebhookSecretConfiguredAttribute(): bool { return !empty($this->attributes['webhook_secret_encrypted']); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
