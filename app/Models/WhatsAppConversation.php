<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $guarded = [];

    protected $hidden = ['phone', 'phone_hash', 'sync_cursor'];

    protected $casts = [
        'phone' => 'encrypted', 'sync_cursor' => 'encrypted',
        'sync_requested_at' => 'datetime', 'sync_started_at' => 'datetime', 'last_synced_at' => 'datetime',
        'last_full_synced_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppConversationMessage::class, 'conversation_id');
    }
}
