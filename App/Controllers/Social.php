<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Sosyal özellikler controller'ı.
 */
class Social extends Controller
{
    public function index()
    {
        return $this->render('social/index.html.twig');
    }

    public function respondFriendRequest()
    {
        return $this->error('Sosyal sistem yakında aktif.');
    }

    public function toggleFollow()
    {
        return $this->error('Sosyal sistem yakında aktif.');
    }

    public function sendFriendRequest()
    {
        return $this->error('Sosyal sistem yakında aktif.');
    }
}
