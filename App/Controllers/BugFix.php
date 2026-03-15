<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Hata bildirimi controller'ı.
 */
class BugFix extends Controller
{
    public function index()
    {
        $bugs = DB::table('bug_reports')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return $this->render('box/bugs.html.twig', ['bugs' => $bugs]);
    }

    public function create()
    {
        $uid   = $this->uid();
        $title = trim($this->input('title', ''));
        $desc  = trim($this->input('description', ''));

        if (!$title || !$desc) {
            return $this->error('Başlık ve açıklama gereklidir.');
        }

        $now = date('Y-m-d H:i:s');
        $id = DB::table('bug_reports')->insertGetId([
            'uid'         => $uid,
            'title'       => $title,
            'description' => $desc,
            'votes'       => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return $this->success('Hata bildirimi oluşturuldu.', ['id' => $id]);
    }

    public function toggleVote()
    {
        return $this->error('Oylama sistemi yakında aktif.');
    }

    public function subscribe()
    {
        return $this->error('Abonelik sistemi yakında aktif.');
    }

    public function unsubscribe()
    {
        return $this->error('Abonelik sistemi yakında aktif.');
    }

    public function adminReply()
    {
        return $this->error('Admin yanıt sistemi yakında aktif.');
    }
}
