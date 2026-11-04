<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Explicit staff consent for imported artwork, never a synthetic provider attempt. */
class LegacyBookCoverApproval extends Model
{
    protected $guarded = [];

    protected $casts = ['inputs' => 'encrypted:array', 'configuration_before' => 'encrypted:array'];
}
