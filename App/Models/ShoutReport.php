<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoutReport extends Model
{
    protected $table = 'shout_reports';

    protected $fillable = [
        'shout_id',
        'uid',
        'reason',
    ];
}
