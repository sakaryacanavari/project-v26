<?php

namespace App\Controllers;

use App\System\App;
use App\System\Controller;
use App\System\DmPrivacy;
use App\System\Logger;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

class Messages extends Controller
{
    private const EDIT_WINDOW_SECONDS = 900;
    private const PIN_LIMIT = 3;

    public function showInbox()
    {
        return $this->inbox();
    }

    public function showThread($otherUid)
    {
        return $this->thread($otherUid);
    }

    public function inbox()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();

        try {
            $threads = DB::table('messages')
                ->selectRaw('CASE WHEN from_uid = ? THEN to_uid ELSE from_uid END as other_uid, MAX(id) as last_id', [$uid])
                ->where('from_uid', $uid)
                ->orWhere('to_uid', $uid)
                ->groupBy('other_uid')
                ->orderBy('last_id', 'desc')
                ->limit(60)
                ->get();

            $otherUids = $threads->pluck('other_uid')->map(function ($value) {
                return (int) $value;
            })->all();

            $archivedSet = [];
            if (!empty($otherUids)) {
                try {
                    $archived = DB::table('message_archives')
                        ->where('uid', $uid)
                        ->whereIn('other_uid', $otherUids)
                        ->pluck('other_uid')
                        ->map(function ($value) {
                            return (int) $value;
                        })
                        ->all();
                    $archivedSet = array_fill_keys($archived, true);
                } catch (\Throwable $e) {
                }
            }

            $usersRaw = [];
            if (!empty($otherUids)) {
                $users = DB::table('users')->whereIn('id', $otherUids)->get();
                foreach ($users as $user) {
                    $usersRaw[(int) $user->id] = $user->nick ?? $user->nickname ?? $user->username ?? $user->name ?? 'Bilinmeyen oyuncu';
                }
            }

            $lastIds = $threads->pluck('last_id')->all();
            $lastMessages = [];
            if (!empty($lastIds)) {
                $rows = DB::table('messages')->whereIn('id', $lastIds)->get();
                foreach ($rows as $row) {
                    $lastMessages[(int) $row->id] = $row;
                }
            }

            $unreadMap = [];
            if (!empty($otherUids)) {
                $unreadRows = DB::table('messages')
                    ->selectRaw('from_uid as other_uid, COUNT(*) as cnt')
                    ->where('to_uid', $uid)
                    ->whereNull('read_at')
                    ->whereIn('from_uid', $otherUids)
                    ->groupBy('from_uid')
                    ->get();

                foreach ($unreadRows as $row) {
                    $unreadMap[(int) $row->other_uid] = (int) $row->cnt;
                }
            }

            $list = [];
            foreach ($threads as $thread) {
                $otherUid = (int) $thread->other_uid;
                if (isset($archivedSet[$otherUid])) {
                    continue;
                }

                $last = $lastMessages[(int) $thread->last_id] ?? null;
                $list[] = [
                    'other_uid' => $otherUid,
                    'other_name' => $usersRaw[$otherUid] ?? 'Bilinmeyen oyuncu',
                    'last_body' => $this->messageDisplayBody($last),
                    'last_at' => $last ? (string) $last->created_at : '',
                    'unread' => $unreadMap[$otherUid] ?? 0,
                ];
            }

            return $this->render('social/messages.html.twig', [
                'threads' => $list,
                'myUid' => $uid,
            ]);
        } catch (\Exception $e) {
            Logger::exception($e, [
                'controller' => 'Messages',
                'action' => 'inbox',
                'uid' => $uid,
            ]);

            return $this->render('social/messages.html.twig', [
                'threads' => [],
                'myUid' => $uid,
                'error' => 'Mesaj listesi gecici olarak yuklenemedi.',
            ]);
        }
    }

    public function thread($otherUid)
    {
        $uid = 0;
        $otherUid = (int) $otherUid;
        $otherName = 'Bilinmeyen kullanici';

        try {
            $session = App::session();
            $session->ensureLogged();
            $uid = (int) $session->getUid();

            if ($otherUid < 1 || $otherUid === $uid) {
                return $this->render('social/thread.html.twig', [
                    'other_uid' => $otherUid,
                    'other_name' => 'Gecersiz Protokol',
                    'messages' => [],
                ]);
            }

            $otherUser = DB::table('users')->where('id', $otherUid)->first();
            if (!$otherUser) {
                return $this->render('social/thread.html.twig', [
                    'other_uid' => $otherUid,
                    'other_name' => 'Bulunamayan Operator',
                    'messages' => [],
                ]);
            }

            $otherName = $otherUser->nick ?? $otherUser->nickname ?? $otherUser->username ?? $otherUser->name ?? 'Bilinmeyen oyuncu';

            $messages = DB::table('messages')
                ->where(function ($query) use ($uid, $otherUid) {
                    $query->where('from_uid', $uid)->where('to_uid', $otherUid);
                })
                ->orWhere(function ($query) use ($uid, $otherUid) {
                    $query->where('from_uid', $otherUid)->where('to_uid', $uid);
                })
                ->orderBy('id', 'asc')
                ->limit(300)
                ->get();

            DB::table('messages')
                ->where('to_uid', $uid)
                ->where('from_uid', $otherUid)
                ->whereNull('read_at')
                ->update(['read_at' => date('Y-m-d H:i:s')]);

            return $this->render('social/thread.html.twig', [
                'other_uid' => $otherUid,
                'other_name' => $otherName,
                'messages' => $messages,
            ]);
        } catch (\Exception $e) {
            Logger::exception($e, [
                'controller' => 'Messages',
                'action' => 'thread',
                'uid' => $uid,
                'other_uid' => $otherUid,
            ]);

            return $this->render('social/thread.html.twig', [
                'other_uid' => $otherUid,
                'other_name' => $otherName,
                'messages' => [],
                'error' => 'Sohbet ekrani gecici olarak yuklenemedi.',
            ]);
        }
    }

    public function send()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();
        $to = (int) ($_POST['to_uid'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($to < 1 || $to === $uid || $body === '' || mb_strlen($body) > 240) {
            return $this->jsonOut(['error' => true, 'message' => 'Gecersiz veri paketi.']);
        }

        if (preg_match_all('~https?://|www\.~i', $body) > 2) {
            return $this->jsonOut(['error' => true, 'message' => 'Cok fazla link iceren mesaj gonderilemez.']);
        }

        if (!DB::table('users')->where('id', $to)->exists()) {
            return $this->jsonOut(['error' => true, 'message' => 'Hedef operator bulunamadi.']);
        }

        $dmPrivacy = DmPrivacy::canStartDm($uid, $to);
        if (empty($dmPrivacy['allowed'])) {
            return $this->jsonOut(['error' => true, 'message' => $dmPrivacy['message']]);
        }

        $now = date('Y-m-d H:i:s');

        try {
            $recent = DB::table('messages')
                ->where('from_uid', $uid)
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3))
                ->count();

            if ($recent >= 1) {
                return $this->jsonOut(['error' => true, 'message' => 'Cok hizli mesaj gonderiyorsun.']);
            }

            $last = DB::table('messages')
                ->where('from_uid', $uid)
                ->where('to_uid', $to)
                ->orderBy('id', 'desc')
                ->first();

            if ($last && trim((string) $last->body) === $body && strtotime((string) $last->created_at) >= time() - 60) {
                return $this->jsonOut(['error' => true, 'message' => 'Ayni mesaji tekrar gonderemezsin.']);
            }

            try {
                DB::table('message_archives')->where('uid', $uid)->where('other_uid', $to)->delete();
                DB::table('message_archives')->where('uid', $to)->where('other_uid', $uid)->delete();
            } catch (\Throwable $e) {
            }

            $id = DB::table('messages')->insertGetId([
                'from_uid' => $uid,
                'to_uid' => $to,
                'body' => mb_substr($body, 0, 240),
                'created_at' => $now,
                'read_at' => null,
            ]);

            if (class_exists('\\App\\System\\Notify')) {
                \App\System\Notify::push($to, 'dm', 'Yeni mesaj', mb_substr($body, 0, 80) . '...', null, ['from_uid' => $uid]);
            }

            return $this->jsonOut(['error' => false, 'id' => (int) $id, 'created_at' => $now]);
        } catch (\Exception $e) {
            Logger::exception($e, [
                'controller' => 'Messages',
                'action' => 'send',
                'uid' => $uid,
                'to_uid' => $to,
            ]);

            return $this->jsonOut(['error' => true, 'message' => 'Mesaj gonderilemedi.']);
        }
    }

    public function threads()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();
        $limit = (int) ($_POST['limit'] ?? 8);

        return $this->jsonOut([
            'error' => false,
            'threads' => $this->buildThreadsList($uid, ($limit > 30 ? 30 : $limit)),
        ]);
    }

    public function searchUsers()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();
        $q = trim((string) ($_POST['q'] ?? ''));

        if (mb_strlen($q) < 2) {
            return $this->jsonOut(['error' => false, 'users' => []]);
        }

        $q = mb_substr($q, 0, 40);
        $schema = DB::getSchemaBuilder();
        $select = ['id', 'nick'];

        foreach (['level'] as $column) {
            if ($schema->hasColumn('users', $column)) {
                $select[] = $column;
            }
        }

        try {
            $query = DB::table('users')
                ->select($select)
                ->where('id', '<>', $uid)
                ->whereRaw('LOWER(nick) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orderByRaw('CASE WHEN LOWER(nick) = ? THEN 0 WHEN LOWER(nick) LIKE ? THEN 1 ELSE 2 END', [
                    mb_strtolower($q),
                    mb_strtolower($q) . '%',
                ])
                ->orderBy('nick')
                ->limit(8);

            $rows = $query->get();
            $users = [];

            foreach ($rows as $row) {
                $name = trim((string) ($row->nick ?? ''));
                if ($name === '') {
                    continue;
                }

                $meta = [];
                if (isset($row->level)) {
                    $meta[] = 'Lv ' . (int) $row->level;
                }
                $users[] = [
                    'uid' => (int) $row->id,
                    'name' => $name,
                    'meta' => implode(' · ', $meta),
                ];
            }

            return $this->jsonOut(['error' => false, 'users' => $users]);
        } catch (\Exception $e) {
            Logger::exception($e, [
                'controller' => 'Messages',
                'action' => 'searchUsers',
                'uid' => $uid,
            ]);

            return $this->jsonOut(['error' => true, 'message' => 'Oyuncu aramasi gecici olarak kullanilamiyor.']);
        }
    }

    public function fetch()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();
        $otherUid = (int) ($_POST['other_uid'] ?? 0);
        $sinceId = (int) ($_POST['since_id'] ?? 0);

        if ($otherUid < 1 || $otherUid === $uid) {
            return $this->jsonOut(['error' => false, 'messages' => []]);
        }

        try {
            $rows = DB::table('messages')
                ->where('id', '>', $sinceId)
                ->where(function ($query) use ($uid, $otherUid) {
                    $query->where(function ($innerQuery) use ($uid, $otherUid) {
                        $innerQuery->where('from_uid', $uid)->where('to_uid', $otherUid);
                    })->orWhere(function ($innerQuery) use ($uid, $otherUid) {
                        $innerQuery->where('from_uid', $otherUid)->where('to_uid', $uid);
                    });
                })
                ->orderBy('id', 'asc')
                ->get();

            DB::table('messages')
                ->where('to_uid', $uid)
                ->where('from_uid', $otherUid)
                ->whereNull('read_at')
                ->update(['read_at' => date('Y-m-d H:i:s')]);

            return $this->jsonOut([
                'error' => false,
                'messages' => $rows,
                'pins' => $this->pinnedMessages($uid, $otherUid),
            ]);
        } catch (\Exception $e) {
            Logger::exception($e, [
                'controller' => 'Messages',
                'action' => 'fetch',
                'uid' => $uid,
                'other_uid' => $otherUid,
            ]);

            return $this->jsonOut(['error' => true, 'message' => 'Mesajlar yuklenemedi.']);
        }
    }

    public function markRead()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $otherUid = (int) ($_POST['other_uid'] ?? 0);

        DB::table('messages')
            ->where('to_uid', $uid)
            ->where('from_uid', $otherUid)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return $this->jsonOut(['error' => false]);
    }

    public function archiveBulk()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $uids = (array) ($_POST['other_uids'] ?? []);

        foreach ($uids as $otherUid) {
            if ((int) $otherUid < 1) {
                continue;
            }

            try {
                DB::table('message_archives')->updateOrInsert(
                    ['uid' => $uid, 'other_uid' => (int) $otherUid],
                    ['archived_at' => date('Y-m-d H:i:s')]
                );
            } catch (\Throwable $e) {
            }
        }

        return $this->jsonOut(['error' => false]);
    }

    public function edit()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($messageId < 1 || $body === '' || mb_strlen($body) > 240) {
            return $this->jsonOut(['error' => true, 'message' => 'Gecersiz mesaj.']);
        }

        $message = DB::table('messages')->where('id', $messageId)->first();
        if (!$message || (int) $message->from_uid !== $uid || !empty($message->deleted_at)) {
            return $this->jsonOut(['error' => true, 'message' => 'Bu mesaji duzenleyemezsin.']);
        }

        if (strtotime((string) $message->created_at) < time() - self::EDIT_WINDOW_SECONDS) {
            return $this->jsonOut(['error' => true, 'message' => 'Duzenleme suresi doldu.']);
        }

        DB::table('messages')->where('id', $messageId)->update([
            'body' => mb_substr($body, 0, 240),
            'edited_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonOut(['error' => false, 'body' => mb_substr($body, 0, 240), 'edited_at' => date('Y-m-d H:i:s')]);
    }

    public function delete()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $message = DB::table('messages')->where('id', $messageId)->first();

        if (!$message || (int) $message->from_uid !== $uid || !empty($message->deleted_at)) {
            return $this->jsonOut(['error' => true, 'message' => 'Bu mesaji silemezsin.']);
        }

        DB::table('messages')->where('id', $messageId)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $uid,
        ]);
        DB::table('message_pins')->where('message_id', $messageId)->delete();

        return $this->jsonOut(['error' => false, 'body' => 'Mesaj silindi']);
    }

    public function togglePin()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();

        $messageId = (int) ($_POST['message_id'] ?? 0);
        $message = $this->messageForParticipant($messageId, $uid);
        if (!$message || !empty($message->deleted_at)) {
            return $this->jsonOut(['error' => true, 'message' => 'Mesaj bulunamadi.']);
        }

        $otherUid = $this->otherUidForMessage($message, $uid);
        $existing = DB::table('message_pins')->where('uid', $uid)->where('message_id', $messageId)->first();
        if ($existing) {
            DB::table('message_pins')->where('uid', $uid)->where('message_id', $messageId)->delete();
            return $this->jsonOut(['error' => false, 'pinned' => false, 'pins' => $this->pinnedMessages($uid, $otherUid)]);
        }

        $count = DB::table('message_pins')->where('uid', $uid)->where('other_uid', $otherUid)->count();
        if ($count >= self::PIN_LIMIT) {
            return $this->jsonOut(['error' => true, 'message' => 'En fazla ' . self::PIN_LIMIT . ' mesaj sabitleyebilirsin.']);
        }

        DB::table('message_pins')->insert([
            'uid' => $uid,
            'other_uid' => $otherUid,
            'message_id' => $messageId,
            'pinned_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonOut(['error' => false, 'pinned' => true, 'pins' => $this->pinnedMessages($uid, $otherUid)]);
    }

    public function searchMessages()
    {
        $session = App::session();
        $session->ensureLogged();
        $uid = (int) $session->getUid();
        $this->ensureMessageFeatureSchema();

        $otherUid = (int) ($_POST['other_uid'] ?? 0);
        $q = trim((string) ($_POST['q'] ?? ''));

        if ($otherUid < 1 || $otherUid === $uid || mb_strlen($q) < 2) {
            return $this->jsonOut(['error' => false, 'results' => []]);
        }

        $results = DB::table('messages')
            ->whereNull('deleted_at')
            ->where('body', 'like', '%' . mb_substr($q, 0, 80) . '%')
            ->where(function ($query) use ($uid, $otherUid) {
                $query->where(function ($innerQuery) use ($uid, $otherUid) {
                    $innerQuery->where('from_uid', $uid)->where('to_uid', $otherUid);
                })->orWhere(function ($innerQuery) use ($uid, $otherUid) {
                    $innerQuery->where('from_uid', $otherUid)->where('to_uid', $uid);
                });
            })
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        return $this->jsonOut(['error' => false, 'results' => $results]);
    }

    private function buildThreadsList(int $uid, int $limit = 8): array
    {
        try {
            $threads = DB::table('messages')
                ->selectRaw('CASE WHEN from_uid = ? THEN to_uid ELSE from_uid END as other_uid, MAX(id) as last_id', [$uid])
                ->where('from_uid', $uid)
                ->orWhere('to_uid', $uid)
                ->groupBy('other_uid')
                ->orderBy('last_id', 'desc')
                ->limit($limit)
                ->get();

            $list = [];
            foreach ($threads as $thread) {
                $otherUid = (int) $thread->other_uid;
                $last = DB::table('messages')->where('id', $thread->last_id)->first();
                $user = DB::table('users')->where('id', $otherUid)->first();

                $list[] = [
                    'other_uid' => $otherUid,
                    'other_name' => $user->nick ?? $user->nickname ?? $user->username ?? 'Bilinmeyen oyuncu',
                    'last_body' => $this->messageDisplayBody($last),
                    'last_at' => $last ? $last->created_at : '',
                    'unread' => DB::table('messages')
                        ->where('to_uid', $uid)
                        ->where('from_uid', $otherUid)
                        ->whereNull('read_at')
                        ->count(),
                ];
            }

            return $list;
        } catch (\Exception $e) {
            Logger::exception($e, [
                'controller' => 'Messages',
                'action' => 'buildThreadsList',
                'uid' => $uid,
            ]);

            return [];
        }
    }

    private function ensureMessageFeatureSchema(): void
    {
        $schema = DB::getSchemaBuilder();

        if ($schema->hasTable('messages')) {
            if (!$schema->hasColumn('messages', 'edited_at')) {
                $schema->table('messages', function (Blueprint $table) {
                    $table->dateTime('edited_at')->nullable();
                });
            }

            if (!$schema->hasColumn('messages', 'deleted_at')) {
                $schema->table('messages', function (Blueprint $table) {
                    $table->dateTime('deleted_at')->nullable();
                });
            }

            if (!$schema->hasColumn('messages', 'deleted_by')) {
                $schema->table('messages', function (Blueprint $table) {
                    $table->integer('deleted_by')->nullable();
                });
            }
        }

        if (!$schema->hasTable('message_pins')) {
            $schema->create('message_pins', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('uid');
                $table->integer('other_uid');
                $table->integer('message_id');
                $table->dateTime('pinned_at')->nullable();
                $table->unique(['uid', 'message_id']);
                $table->index(['uid', 'other_uid']);
            });
        }
    }

    private function messageDisplayBody($message): string
    {
        if (!$message) {
            return '';
        }

        if (!empty($message->deleted_at)) {
            return 'Mesaj silindi';
        }

        return (string) ($message->body ?? '');
    }

    private function messageForParticipant(int $messageId, int $uid)
    {
        return DB::table('messages')
            ->where('id', $messageId)
            ->where(function ($query) use ($uid) {
                $query->where('from_uid', $uid)->orWhere('to_uid', $uid);
            })
            ->first();
    }

    private function otherUidForMessage($message, int $uid): int
    {
        return (int) $message->from_uid === $uid ? (int) $message->to_uid : (int) $message->from_uid;
    }

    private function pinnedMessages(int $uid, int $otherUid): array
    {
        $pins = DB::table('message_pins')
            ->where('uid', $uid)
            ->where('other_uid', $otherUid)
            ->orderBy('pinned_at', 'desc')
            ->limit(self::PIN_LIMIT)
            ->get();

        if ($pins->isEmpty()) {
            return [];
        }

        $messageIds = [];
        foreach ($pins as $pin) {
            $messageIds[] = (int) $pin->message_id;
        }

        $messages = [];
        foreach (DB::table('messages')->whereIn('id', $messageIds)->get() as $message) {
            $messages[(int) $message->id] = $message;
        }

        $list = [];
        foreach ($pins as $pin) {
            $message = $messages[(int) $pin->message_id] ?? null;
            if (!$message || !empty($message->deleted_at)) {
                continue;
            }

            $list[] = [
                'id' => (int) $message->id,
                'body' => (string) $message->body,
                'from_uid' => (int) $message->from_uid,
                'pinned_at' => (string) $pin->pinned_at,
            ];
        }

        return $list;
    }

    private function jsonOut(array $payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
