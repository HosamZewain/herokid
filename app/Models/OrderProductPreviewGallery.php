<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class OrderProductPreviewGallery extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'public_token_hash',
        'public_token_encrypted',
    ];

    protected $casts = [
        'last_viewed_at' => 'datetime',
    ];

    public function previews(): HasMany
    {
        return $this->hasMany(OrderPreview::class, 'product_gallery_id')->oldest();
    }

    public function plainPublicToken(): ?string
    {
        try {
            return Crypt::decryptString($this->public_token_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function publicUrl(): ?string
    {
        $token = $this->plainPublicToken();

        return $token ? route('order-product-previews.show', ['token' => $token]) : null;
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->status === 'active'
            && $this->previews()->exists()
            && Order::query()
                ->where('checkout_group_key', $this->checkout_group_key)
                ->exists();
    }
}
