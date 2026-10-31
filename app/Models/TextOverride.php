<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The shop's own wording of one text in one language — App\Core\Translation\TextOverrides. */
class TextOverride extends Model
{
    protected $fillable = ['locale', 'key', 'value'];
}
