<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoboDeskConversationSetting extends Model
{
    protected $table = 'robodesk_conversation_settings';

    protected $guarded = [];

    protected $hidden = ['email', 'password'];

    protected $casts = ['enabled' => 'boolean', 'email' => 'encrypted', 'password' => 'encrypted', 'conversation_limit' => 'integer'];
}
