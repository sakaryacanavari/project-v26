<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresidentialVote extends Model
{
    protected $fillable = ['candidate_uid', 'uid', 'country', 'election_key'];
}
