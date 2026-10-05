<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['is_active' => true, 'is_super_user' => false];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_super_user' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'access_advanced_network_tools' => 'boolean',
        ];
    }

    public function permissions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions');
    }

    public function hasAdminPermission(string $key): bool
    {
        return $this->is_active && ($this->is_super_user || $this->permissions->contains('key', $key));
    }

    public function adminAccess(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'is_active' => (bool) $this->is_active, 'is_super_user' => (bool) $this->is_super_user,
            'permissions' => $this->is_super_user ? \App\Support\AdminPermissions::KEYS : $this->permissions->pluck('key')->all(),
        ];
    }
}
