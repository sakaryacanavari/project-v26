<?php

namespace App\Controllers;

use App\System\App;
use App\System\Cache;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

class Notifications extends Controller
{
    // routes.php UI tarafı showCenter bekliyor
    public function showCenter()
    {
        return $this->index();
    }

    public function index()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int)$session->getUid();

        try {
            $items = DB::table('notifications')
                ->where('uid', $uid)
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get();
        } catch (\Exception $e) {
            $items = [];
        }

        return $this->render('social/notifications.html.twig', [
            'items' => $items
        ]);
    }

    // ✅ YENİ: Javascript'in üst menüdeki "Zil" ikonuna tıklayınca aradığı liste fonksiyonu
    public function list()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int)$session->getUid();
        
        $limit = (int)($_POST['limit'] ?? 6);
        if ($limit < 1) $limit = 6;

        try {
            $items = DB::table('notifications')
                ->where('uid', $uid)
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();
                
            return $this->jsonOut(['error' => false, 'items' => $items]);
        } catch (\Exception $e) {
            return $this->jsonOut(['error' => true, 'items' => []]);
        }
    }

    // ✅ GÜNCELLENDİ: Javascript hem Bildirim hem de DM sayısını aynı anda tek pakette (badges) istiyor
    public function unreadCount()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int)$session->getUid();

        $cacheKey = \App\System\Notify::unreadCacheKey($uid);
        $cacheHit = false;
        $cachedBadges = Cache::get($cacheKey, $cacheHit);
        if ($cacheHit && is_array($cachedBadges)) {
            return $this->jsonOut(['error' => false, 'badges' => $cachedBadges]);
        }

        try {
            // Okunmamış Bildirim Sayısı
            $notifCnt = (int) DB::table('notifications')
                ->where('uid', $uid)
                ->where('is_read', 0)
                ->count();

            // Okunmamış Mesaj (DM) Sayısı
            $msgCnt = (int) DB::table('messages')
                ->where('to_uid', $uid)
                ->whereNull('read_at')
                ->count();

            Cache::put($cacheKey, [
                'notifications' => $notifCnt,
                'messages' => $msgCnt,
            ], 5);

            // JS'nin tam beklediği "badges" objesi formatı
            return $this->jsonOut([
                'error' => false, 
                'badges' => [
                    'notifications' => $notifCnt,
                    'messages' => $msgCnt
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonOut([
                'error' => false, 
                'badges' => ['notifications' => 0, 'messages' => 0]
            ]);
        }
    }

    // /api/notifications/mark-read
    public function markRead()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int)$session->getUid();

        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            return $this->jsonOut(['error' => true, 'message' => 'Geçersiz id']);
        }

        try {
            DB::table('notifications')
                ->where('id', $id)
                ->where('uid', $uid)
                ->update(['is_read' => 1]);

            \App\System\Notify::forgetUnreadCache($uid);

            return $this->jsonOut(['error' => false]);
        } catch (\Exception $e) {
            return $this->jsonOut(['error' => true, 'message' => 'Veritabanı hatası.']);
        }
    }

    // /api/notifications/mark-all-read
    public function markAllRead()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int)$session->getUid();

        try {
            DB::table('notifications')
                ->where('uid', $uid)
                ->where('is_read', 0)
                ->update(['is_read' => 1]);

            \App\System\Notify::forgetUnreadCache($uid);

            return $this->jsonOut(['error' => false]);
        } catch (\Exception $e) {
            return $this->jsonOut(['error' => true, 'message' => 'Veritabanı hatası.']);
        }
    }

    private function jsonOut(array $payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
