<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresidentialElectionHistory extends Model
{
    protected $fillable = [
        'country',
        'election_key',
        'winner_uid',
        'winner_votes',
        'total_votes',
        'candidate_count',
        'summary_json',
        'finished_at',
    ];
}
