<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Mesajlaşma controller'ı.
 */
class Messages extends Controller
{
    public function showInbox()
    {
        return $this->render('messages/inbox.html.twig');
    }

    public function showThread($otherUid)
    {
        return $this->render('messages/thread.html.twig', ['otherUid' => $otherUid]);
    }

    public function send()
    {
        $uid      = $this->uid();
        $toUid    = (int) $this->input('to', 0);
        $text     = trim($this->input('text', ''));

        if (!$toUid || !$text) {
            return $this->error('Alıcı ve mesaj içeriği gereklidir.');
        }

        $now = date('Y-m-d H:i:s');
        DB::table('messages')->insert([
            'from'       => $uid,
            'to'         => $toUid,
            'text'       => $text,
            'is_read'    => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->success('Mesaj gönderildi.');
    }

    public function fetch()
    {
        $uid      = $this->uid();
        $otherUid = (int) $this->input('uid', 0);

        $messages = DB::table('messages')
            ->where(function ($q) use ($uid, $otherUid) {
                $q->where('from', $uid)->where('to', $otherUid);
            })
            ->orWhere(function ($q) use ($uid, $otherUid) {
                $q->where('from', $otherUid)->where('to', $uid);
            })
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->success('Mesajlar alındı.', ['messages' => $messages]);
    }

    public function threads()
    {
        $uid = $this->uid();
        return $this->success('Konuşmalar alındı.', ['threads' => []]);
    }

    public function markRead()
    {
        $uid = $this->uid();
        $id  = (int) $this->input('id', 0);

        DB::table('messages')->where('id', $id)->where('to', $uid)->update(['is_read' => 1]);
        return $this->success('Okundu.');
    }

    public function archiveBulk()
    {
        return $this->success('Arşivlendi.');
    }
}
