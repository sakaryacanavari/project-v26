<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoutRestriction extends Model
{
    protected $table = 'shout_user_restrictions';

    protected $fillable = [
        'uid',
        'muted_until',
        'reason',
        'created_by',
    ];
}
