<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Business extends Model
{
    protected $fillable = ['uuid','name','code','status'];
    public function getRouteKeyName(): string { return 'uuid'; }
    public function gatewayAccounts(): HasMany { return $this->hasMany(PaymentGatewayAccount::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
