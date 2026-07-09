<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    const STATUS_BANNED = 2;
    const STATUS_ACTIVATED = 1;
    const STATUS_PENDING = 0;
    const MIN_PASSWORD_LENGTH = 10;
    const MAX_PASSWORD_LENGTH = 72;
    const MIN_NICK_LENGTH = 4;
    const MAX_NICK_LENGTH = 20;

    protected $fillable = [
        "email",
        "nick",
        "password",
        "status",
        "email_verified_at",
        "region",
        "country_id",
        "strength",
        "referrer",
        "xp",
        "level",
        "theme",
        "language",
        "economic_skill",
        "economic_xp",
        "media_reputation",
    ];

}
