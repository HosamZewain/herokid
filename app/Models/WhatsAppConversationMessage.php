<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppConversationMessage extends Model
{
    protected $table = 'whatsapp_conversation_messages';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['remote_id', 'remote_hash', 'fingerprint', 'provider_metadata', 'confirmation_event_id'];

    protected $casts = [
        'provider_metadata' => 'encrypted:array',
        'remote_id' => 'encrypted', 'body' => 'encrypted', 'sender_name' => 'encrypted', 'employee_name' => 'encrypted', 'attachments' => 'encrypted:array',
        'sent_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime',
    ];
}
