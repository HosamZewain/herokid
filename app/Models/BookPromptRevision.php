<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookPromptRevision extends Model
{
    protected $guarded = [];

    protected $casts = ['previous_template' => 'encrypted', 'new_template' => 'encrypted'];
}
