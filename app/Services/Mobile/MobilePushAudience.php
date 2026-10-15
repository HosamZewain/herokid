<?php

namespace App\Services\Mobile;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MobilePushAudience
{
    public function query(string $category, ?int $userId = null): Builder
    {
        return User::query()->where('role', 'customer')->where('is_active', true)
            ->when($userId, fn ($q) => $q->whereKey($userId))
            ->whereHas('deviceInstallations', fn ($q) => $q->whereNull('revoked_at')->whereNotNull('push_token_hash')
                ->where($category === 'marketing' ? 'marketing_notifications' : 'operational_notifications', true));
    }
}
