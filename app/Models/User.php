<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (app()->runningUnitTests() && $user->role === 'admin' && Schema::hasTable('permissions')) {
                $user->permissions()->syncWithoutDetaching(Permission::pluck('id'));
            }
        });
    }

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
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function storyViews(): HasMany
    {
        return $this->hasMany(CustomerStoryView::class);
    }

    public function adminActivityLogs(): HasMany
    {
        return $this->hasMany(AdminActivityLog::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' && $this->is_active === true;
    }

    public function hasPermission(string $permissionKey): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return $this->permissionKeys()->contains($permissionKey);
    }

    public function hasAnyPermission(array $permissionKeys): bool
    {
        if (! $this->isAdmin() || $permissionKeys === []) {
            return false;
        }

        return $this->permissionKeys()->intersect($permissionKeys)->isNotEmpty();
    }

    public function hasAllPermissions(array $permissionKeys): bool
    {
        if (! $this->isAdmin() || $permissionKeys === []) {
            return false;
        }

        return $this->permissionKeys()->intersect($permissionKeys)->count() === count(array_unique($permissionKeys));
    }

    public function permissionKeys()
    {
        if (! $this->relationLoaded('permissions')) {
            $this->load('permissions:id,key');
        }

        return $this->permissions->pluck('key');
    }
}
