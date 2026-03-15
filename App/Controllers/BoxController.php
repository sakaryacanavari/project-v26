<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Kutu sistemi controller'ı.
 */
class BoxController extends Controller
{
    public function index()
    {
        return $this->render('box/index.html.twig');
    }

    public function openBox()
    {
        return $this->error('Kutu sistemi yakında aktif.');
    }

    public function upgrade()
    {
        return $this->error('Kutu yükseltme sistemi yakında aktif.');
    }
}
