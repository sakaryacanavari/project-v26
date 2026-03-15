<?php

namespace App\Models;

use \Illuminate\Database\Eloquent\Model;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Kullanıcı Eloquent modeli.
 * Temel kullanıcı ilişkilerini ve yardımcı metotları içerir.
 */
class User extends Model
{
    protected $table = 'users';

    protected $hidden = ['password'];

    protected $casts = [
        'economic_skill' => 'integer',
        'economic_xp'    => 'integer',
        'is_admin'       => 'boolean',
    ];

    // -------------------------------------------------------
    // İlişkiler
    // -------------------------------------------------------

    /**
     * Kullanıcının bölge ilişkisi.
     */
    public function region()
    {
        return $this->belongsTo(Region::class, 'region');
    }

    /**
     * Konum bilgisine erişim için accessor.
     * Twig'de my.location.country_id şeklinde kullanılır.
     * regions.country sütunu country_id olarak aliaslanır.
     */
    public function getLocationAttribute()
    {
        return \Illuminate\Database\Capsule\Manager::table('regions')
            ->selectRaw('id, name, country AS country_id')
            ->where('id', $this->attributes['region'] ?? 0)
            ->first();
    }

    // -------------------------------------------------------
    // Yardımcı metotlar
    // -------------------------------------------------------

    /**
     * Kullanıcı kongre üyesi mi?
     * Kullanıcının olduğu ülkedeki congressists tablosunda kaydı varsa kongre üyesidir.
     */
    public function isCongressist(): bool
    {
        return DB::table('congressists')
            ->where('uid', $this->id)
            ->exists();
    }

    /**
     * Kullanıcı admin mi?
     */
    public function isAdmin(): bool
    {
        return (bool) ($this->is_admin ?? false);
    }

    /**
     * Aktif iş sözleşmesini döndürür.
     */
    public function activeJob()
    {
        return DB::table('work_offers')
            ->where('worker', $this->id)
            ->first();
    }

    /**
     * Kullanıcının para birimlerine göre parasını döndürür.
     */
    public function money()
    {
        return DB::table('user_money')
            ->where('uid', $this->id)
            ->first();
    }
}
