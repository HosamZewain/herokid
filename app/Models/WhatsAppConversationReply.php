<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppConversationReply extends Model
{
    protected $table = 'whatsapp_conversation_replies';

    protected $guarded = [];

    protected $casts = ['employee_name' => 'encrypted', 'error_message' => 'encrypted', 'attachment_expires_at' => 'immutable_datetime'];
}
