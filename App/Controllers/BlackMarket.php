<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Kara pazar controller'ı.
 */
class BlackMarket extends Controller
{
    public function index()
    {
        return $this->render('blackmarket/index.html.twig');
    }

    public function createAd()
    {
        return $this->render('blackmarket/create.html.twig');
    }

    public function storeAd()
    {
        return $this->error('Kara pazar sistemi yakında aktif.');
    }

    public function buyItem()
    {
        return $this->error('Kara pazar sistemi yakında aktif.');
    }
}
