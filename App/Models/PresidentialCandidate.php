<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresidentialCandidate extends Model
{
    protected $fillable = ['uid', 'country', 'election_key', 'votes', 'campaign_title', 'campaign_message'];
}
