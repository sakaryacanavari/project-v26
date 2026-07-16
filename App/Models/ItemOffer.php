<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemOffer extends Model
{
    protected $fillable = [
        "item", "uid", "price", "quantity", "quality", "country",
        "status", "listed_quantity", "expires_at", "closed_at",
    ];

    public function seller() {
        return $this->hasOne('App\Models\User', 'id', 'uid');
    }

    public function country() {
        return $this->hasOne('App\Models\Country', 'id', 'country');
    }
}
