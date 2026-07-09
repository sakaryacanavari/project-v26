<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoutLike extends Model
{
    protected $table = 'shout_likes';

    protected $fillable = [
        'shout_id',
        'uid',
    ];
}
