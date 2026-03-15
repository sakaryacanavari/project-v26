<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Bildirimler controller'ı.
 */
class Notifications extends Controller
{
    public function showCenter()
    {
        return $this->render('notifications/center.html.twig');
    }

    public function unreadCount()
    {
        $uid   = $this->uid();
        $count = DB::table('notifications')
            ->where('uid', $uid)
            ->where('is_read', 0)
            ->count();

        return $this->success('Bildirim sayısı.', ['count' => $count]);
    }

    public function list()
    {
        $uid = $this->uid();
        $notifications = DB::table('notifications')
            ->where('uid', $uid)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        return $this->success('Bildirimler listelendi.', ['notifications' => $notifications]);
    }

    public function markRead()
    {
        $uid = $this->uid();
        $id  = (int) $this->input('id', 0);

        DB::table('notifications')
            ->where('uid', $uid)
            ->where('id', $id)
            ->update(['is_read' => 1]);

        return $this->success('Bildirim okundu olarak işaretlendi.');
    }

    public function markAllRead()
    {
        $uid = $this->uid();
        DB::table('notifications')
            ->where('uid', $uid)
            ->update(['is_read' => 1]);

        return $this->success('Tüm bildirimler okundu olarak işaretlendi.');
    }
}
