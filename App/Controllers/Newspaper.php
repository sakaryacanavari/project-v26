<?php
namespace App\Controllers;

use App\Models\NewspaperArticle;
use App\Models\Newspaper as NewspaperModel;
use App\Models\UserMoney;
use App\Models\ArticleVote;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use App\System\Input;
use App\System\Notify;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class Newspaper extends Controller
{
    public function showHome() {
        $uid = App::user()->getUid();
        $followedNewspaperIds = array_map('intval', DB::table('newspaper_subscribers')
            ->where('uid', $uid)
            ->pluck('newspaper_id')
            ->toArray());
        $categoryWeights = $this->buildUserCategoryWeights($uid);

        $articles = NewspaperArticle::with("creator")
            ->orderBy('id', 'DESC')
            ->limit(60)
            ->get();

        $lastWeek = Carbon::now()->subDays(7);
        $weeklyStars = NewspaperArticle::with("creator")
            ->where('created_at', '>=', $lastWeek)
            ->orderBy('votes', 'DESC')
            ->orderBy('views', 'DESC')
            ->limit(3)
            ->get();

        $followingArticles = DB::table('newspaper_subscribers as ns')
            ->join('newspapers as n', 'ns.newspaper_id', '=', 'n.id')
            ->join('newspaper_articles as a', 'n.uid', '=', 'a.uid')
            ->leftJoin('users as u', 'a.uid', '=', 'u.id')
            ->where('ns.uid', $uid)
            ->select('a.*', 'n.name as newspaper_name', 'n.id as newspaper_id', 'u.nick as creator_nick')
            ->orderBy('a.id', 'DESC')
            ->limit(8)
            ->get();

        $myNewspaper = App::user()->getNewspaper();

        $articleRows = $articles ? $articles->toArray() : [];
        $weeklyStarRows = $weeklyStars ? $weeklyStars->toArray() : [];
        $followingRows = $followingArticles ? json_decode(json_encode($followingArticles), true) : [];

        $allUids = [];
        foreach ([$articleRows, $weeklyStarRows, $followingRows] as $rowSet) {
            foreach ($rowSet as $row) {
                $rowUid = (int)($row['uid'] ?? 0);
                if ($rowUid > 0) {
                    $allUids[$rowUid] = $rowUid;
                }
            }
        }

        $newspaperMetaByUid = [];
        if (!empty($allUids)) {
            $newspaperRows = NewspaperModel::whereIn('uid', array_values($allUids))
                ->get(['id', 'uid', 'name', 'subscribers']);

            foreach ($newspaperRows as $newspaperRow) {
                $newspaperMetaByUid[(int)$newspaperRow->uid] = [
                    'id' => (int)$newspaperRow->id,
                    'name' => (string)$newspaperRow->name,
                    'subscribers' => (int)$newspaperRow->subscribers
                ];
            }
        }

        $allArticleIds = [];
        foreach ([$articleRows, $followingRows] as $rowSet) {
            foreach ($rowSet as $row) {
                $articleId = (int)($row['id'] ?? 0);
                if ($articleId > 0) {
                    $allArticleIds[$articleId] = $articleId;
                }
            }
        }

        $commentCounts = [];
        if (!empty($allArticleIds)) {
            $commentCounts = DB::table('article_comments')
                ->select('article_id', DB::raw('COUNT(*) as aggregate_count'))
                ->whereIn('article_id', array_values($allArticleIds))
                ->groupBy('article_id')
                ->pluck('aggregate_count', 'article_id')
                ->toArray();
        }

        $articleRows = array_map(function ($row) use ($newspaperMetaByUid, $commentCounts, $followedNewspaperIds, $categoryWeights) {
            return $this->normalizeArticleForFeed($row, $newspaperMetaByUid, $commentCounts, $followedNewspaperIds, $categoryWeights);
        }, $articleRows);

        $weeklyStarRows = array_map(function ($row) use ($newspaperMetaByUid, $commentCounts, $followedNewspaperIds, $categoryWeights) {
            return $this->normalizeArticleForFeed($row, $newspaperMetaByUid, $commentCounts, $followedNewspaperIds, $categoryWeights);
        }, $weeklyStarRows);

        $followingRows = array_map(function ($row) use ($newspaperMetaByUid, $commentCounts, $followedNewspaperIds, $categoryWeights) {
            return $this->normalizeArticleForFeed($row, $newspaperMetaByUid, $commentCounts, $followedNewspaperIds, $categoryWeights);
        }, $followingRows);

        usort($articleRows, function ($left, $right) {
            return ((int)($right['sort_personal'] ?? 0)) <=> ((int)($left['sort_personal'] ?? 0));
        });

        usort($weeklyStarRows, function ($left, $right) {
            return ((int)($right['sort_trend'] ?? 0)) <=> ((int)($left['sort_trend'] ?? 0));
        });

        usort($followingRows, function ($left, $right) {
            return ((int)($right['sort_latest'] ?? 0)) <=> ((int)($left['sort_latest'] ?? 0));
        });
        
        return $this->render('news/home.html.twig', [
            "articles" => $articleRows,
            "weeklyStars" => $weeklyStarRows,
            "followingArticles" => $followingRows,
            "myNewspaper" => $myNewspaper ? $myNewspaper->toArray() : null
        ]);
    }

    public function showCreateArticle() {
        $myNewspaper = App::user()->getNewspaper();
        if (!$myNewspaper) {
            $baseUrl = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']), '/');
            header("Location: " . $baseUrl . "/newspaper/create"); exit;
        }
        return $this->render('news/createArticle.html.twig', ["myNewspaper" => $myNewspaper->toArray()]);
    }

    public function publishArticle() {
        $uid = (int) App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'newspaper_publish_article',
            'uid:' . $uid,
            6,
            3600,
            7200,
            'Kisa surede cok fazla makale yayinladiniz. Lutfen daha sonra tekrar deneyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $myNewspaper = App::user()->getNewspaper();
        if (!$myNewspaper) throw new AppException(AppException::INVALID_DATA, "Gazeteniz bulunamadı.");

        $title = trim(strip_tags($_POST["title"]));
        $text = trim(strip_tags($_POST["text"]));
        $category = Input::getInteger("category");

        if (mb_strlen($title, 'UTF-8') < 4 || mb_strlen($text, 'UTF-8') < 10) {
            throw new AppException(AppException::INVALID_DATA, "Başlık veya içerik çok kısa.");
        }

        DB::statement("SET NAMES 'utf8mb4'");
        $article = NewspaperArticle::create([
            "title" => $title,
            "text" => $text,
            "category" => $category,
            "uid" => App::user()->getUid(),
            "country" => $myNewspaper->country
        ]);

        $baseUrl = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']), '/');
        $articleUrl = $baseUrl . "/news/article/" . $article->id;
        $subscriberUids = DB::table('newspaper_subscribers')
            ->where('newspaper_id', $myNewspaper->id)
            ->pluck('uid')
            ->toArray();

        foreach ($subscriberUids as $subscriberUid) {
            $subscriberUid = (int)$subscriberUid;

            if ($subscriberUid < 1 || $subscriberUid === (int)App::user()->getUid()) {
                continue;
            }

            Notify::push(
                $subscriberUid,
                'newspaper_article',
                'Takip ettigin gazetede yeni makale',
                $myNewspaper->name . ': ' . $title,
                $articleUrl,
                [
                    'article_id' => (int)$article->id,
                    'newspaper_id' => (int)$myNewspaper->id,
                    'author_uid' => (int)App::user()->getUid()
                ]
            );
        }

        if ($this->isAdminCriticalArticle($uid, $title)) {
            $targetUids = DB::table('users')
                ->where('id', '!=', $uid)
                ->pluck('id')
                ->toArray();

            foreach ($targetUids as $targetUid) {
                $targetUid = (int) $targetUid;
                if ($targetUid < 1 || $this->hasArticleNotification($targetUid, (int) $article->id, 'admin_critical_article')) {
                    continue;
                }

                Notify::push(
                    $targetUid,
                    'admin_critical_article',
                    'Kritik sistem duyurusu',
                    $title,
                    $articleUrl,
                    [
                        'article_id' => (int) $article->id,
                        'author_uid' => $uid,
                        'category' => $category,
                    ]
                );
            }
        }

        return $article->id; 
    }

    private function isAdminCriticalArticle($uid, $title)
    {
        $isAdmin = (int) DB::table('users')->where('id', (int) $uid)->value('is_admin') === 1;
        if (!$isAdmin) {
            return false;
        }

        return (bool) preg_match('/bakim|bakım|maintenance|guncelleme|güncelleme|update|kritik|duyuru|announcement|critical/iu', (string) $title);
    }

    private function hasArticleNotification($uid, $articleId, $type)
    {
        return DB::table('notifications')
            ->where('uid', (int) $uid)
            ->where('type', (string) $type)
            ->where('meta', 'like', '%"article_id":' . (int) $articleId . '%')
            ->exists();
    }

    public function showArticle ($id) {
        $this->ensureArticleEnhancementSchema();
        $article = NewspaperArticle::with("creator")->find($id);
        if (!$article) throw new AppException(AppException::INVALID_DATA);
        $article->views++; $article->save();
        
        $comments = DB::table('article_comments as c')
            ->join('users as u', 'c.uid', '=', 'u.id')
            ->leftJoin('users as mu', 'c.mention_uid', '=', 'mu.id')
            ->select('c.*', 'u.nick', 'mu.nick as mention_nick')
            ->where('c.article_id', $id)
            ->orderBy(DB::raw('(COALESCE(c.is_author_pinned,0) + COALESCE(c.is_pinned,0))'), 'DESC')
            ->orderBy('c.parent_id', 'ASC')
            ->orderBy('c.votes', 'DESC')
            ->orderBy('c.created_at', 'ASC')
            ->get();

        $commentRows = $comments ? json_decode(json_encode($comments), true) : [];
        $commentIds = array_values(array_filter(array_map(function ($row) {
            return (int)($row['id'] ?? 0);
        }, $commentRows)));

        $reactionSummary = [];
        $myReactionMap = [];
        if (!empty($commentIds)) {
            $reactionRows = DB::table('article_comment_reactions')
                ->select('comment_id', 'reaction', DB::raw('COUNT(*) as aggregate_count'))
                ->whereIn('comment_id', $commentIds)
                ->groupBy('comment_id', 'reaction')
                ->get();

            foreach ($reactionRows as $reactionRow) {
                $commentId = (int)$reactionRow->comment_id;
                if (empty($reactionSummary[$commentId])) {
                    $reactionSummary[$commentId] = [];
                }
                $reactionSummary[$commentId][(string)$reactionRow->reaction] = (int)$reactionRow->aggregate_count;
            }

            $myReactionRows = DB::table('article_comment_reactions')
                ->where('uid', App::user()->getUid())
                ->whereIn('comment_id', $commentIds)
                ->get(['comment_id', 'reaction']);

            foreach ($myReactionRows as $reactionRow) {
                $commentId = (int)$reactionRow->comment_id;
                if (empty($myReactionMap[$commentId])) {
                    $myReactionMap[$commentId] = [];
                }
                $myReactionMap[$commentId][] = (string)$reactionRow->reaction;
            }
        }

        $comments = $this->buildCommentTree($commentRows, $reactionSummary, $myReactionMap);

        $authorNewspaper = NewspaperModel::where('uid', $article->uid)->first();
        $isSubscribed = $authorNewspaper ? DB::table('newspaper_subscribers')->where('newspaper_id', $authorNewspaper->id)->where('uid', App::user()->getUid())->exists() : false;

        $sponsorRows = DB::table('article_endorsements as ae')
            ->join('users as u', 'ae.uid', '=', 'u.id')
            ->select('ae.uid', 'u.nick', 'ae.currency', DB::raw('SUM(ae.amount) as total_amount'))
            ->where('ae.article_id', $id)
            ->groupBy('ae.uid', 'u.nick', 'ae.currency')
            ->get();

        return $this->render('news/article.html.twig', [
            "article" => $article->toArray(), 
            "isSubscribed" => $isSubscribed, 
            "comments" => $comments,
            "topSponsors" => $this->buildSponsorLeaderboard($sponsorRows),
            "authorCard" => $this->buildAuthorIntelCard((int)$article->uid, $authorNewspaper),
            "factCheck" => $this->buildFactCheckSummary((int)$article->id, App::user()->getUid())
        ]);
    }

    public function postComment() { 
        $uid = (int) App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'newspaper_comment',
            'uid:' . $uid,
            12,
            120,
            300,
            'Yorum denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $this->ensureArticleEnhancementSchema();
        $aId = Input::getInteger("articleId"); 
        $msg = trim(strip_tags($_POST["message"])); 
        if ($aId < 1 || mb_strlen($msg, 'UTF-8') < 2) return ["error" => true, "message" => "Geçersiz mesaj formatı."];
        $article = NewspaperArticle::find($aId);
        if (!$article) return ["error" => true, "message" => "Makale bulunamadi."];

        if (mb_strlen($msg, 'UTF-8') > 250) {
            return ["error" => true, "message" => "Yorum 250 karakteri gecemez."];
        }

        $parentId = max(0, Input::getInteger("parentId"));
        $mentionUid = max(0, Input::getInteger("mentionUid"));
        $isPinned = Input::getInteger("isPinned") === 1 ? 1 : 0;
        $pinAmount = 0;
        $pinCurrency = null;
        $now = date("Y-m-d H:i:s");

        $recentComment = DB::table('article_comments')
            ->where('uid', App::user()->getUid())
            ->orderBy('id', 'DESC')
            ->first();

        if ($recentComment && !empty($recentComment->created_at)) {
            $recentTimestamp = strtotime((string)$recentComment->created_at);
            if ($recentTimestamp !== false && (time() - $recentTimestamp) < 15) {
                return ["error" => true, "message" => "Cok hizli yorum gonderiyorsun. Lutfen kisa bir sure bekle."];
            }
        }

        if ($parentId > 0) {
            $parentExists = DB::table('article_comments')
                ->where('id', $parentId)
                ->where('article_id', $aId)
                ->exists();
            if (!$parentExists) return ["error" => true, "message" => "Yanitlanacak yorum bulunamadi."];
        }

        if ($isPinned) {
            $money = UserMoney::where('uid', App::user()->getUid())->first();
            if (!$money || $money->gold < 1) return ["error" => true, "message" => "VIP yorum icin en az 1 ALTIN gerekir."];
            $money->gold -= 1;
            $money->save();
            $pinAmount = 1;
            $pinCurrency = 'gold';
        }

        DB::statement("SET NAMES 'utf8mb4'"); 
        $commentId = DB::table('article_comments')->insertGetId([
            "article_id" => $aId, 
            "uid" => App::user()->getUid(), 
            "message" => $msg,
            "parent_id" => $parentId,
            "mention_uid" => $mentionUid > 0 ? $mentionUid : null,
            "is_pinned" => $isPinned,
            "pin_currency" => $pinCurrency,
            "pin_amount" => $pinAmount,
            "created_at" => $now,
            "updated_at" => $now
        ]);

        if ($mentionUid > 0 && $mentionUid !== (int)App::user()->getUid()) {
            $baseUrl = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']), '/');
            Notify::push(
                $mentionUid,
                'article_reply',
                'Yorumuna yanit geldi',
                App::user()->getUser()['nick'] . ' seni bir tartismada etiketledi.',
                $baseUrl . '/news/article/' . $aId . '#comment-' . $commentId,
                [
                    'article_id' => (int)$aId,
                    'comment_id' => (int)$commentId
                ]
            );
        }

        return ["success" => true]; 
    }

    // --- YORUM BEĞEN DÜZELTİLDİ (SPAM KORUMASI EKLENDİ) ---
    public function editComment() {
        $this->ensureArticleEnhancementSchema();

        $commentId = Input::getInteger("id");
        $message = trim(strip_tags(Input::getString("message")));
        $uid = App::user()->getUid();

        if ($commentId < 1 || mb_strlen($message, 'UTF-8') < 2 || mb_strlen($message, 'UTF-8') > 250) {
            return ["error" => true, "message" => "Yorum 2 ile 250 karakter arasinda olmalidir."];
        }

        $comment = DB::table('article_comments')->where('id', $commentId)->first();
        if (!$comment) {
            return ["error" => true, "message" => "Yorum bulunamadi."];
        }
        if ((int)$comment->uid !== (int)$uid) {
            return ["error" => true, "message" => "Sadece kendi yorumunu duzenleyebilirsin."];
        }

        DB::table('article_comments')->where('id', $commentId)->update([
            'message' => $message,
            'updated_at' => date("Y-m-d H:i:s")
        ]);

        return ["success" => true];
    }

    public function pinComment() {
        $this->ensureArticleEnhancementSchema();

        $commentId = Input::getInteger("id");
        $uid = App::user()->getUid();

        if ($commentId < 1) {
            return ["error" => true, "message" => "Gecersiz yorum secildi."];
        }

        $comment = DB::table('article_comments')->where('id', $commentId)->first();
        if (!$comment) {
            return ["error" => true, "message" => "Yorum bulunamadi."];
        }

        $article = NewspaperArticle::find((int)$comment->article_id);
        if (
            !$article ||
            (
                (int)$article->uid !== (int)$uid &&
                (int)$comment->uid !== (int)$uid
            )
        ) {
            return ["error" => true, "message" => "Bu yorumu sabitleme yetkin yok."];
        }

        $isPinned = (int)($comment->is_author_pinned ?? 0) === 1 ? 0 : 1;

        DB::table('article_comments')->where('id', $commentId)->update([
            'is_author_pinned' => $isPinned,
            'updated_at' => date("Y-m-d H:i:s")
        ]);

        return ["success" => true, "status" => $isPinned === 1 ? 'pinned' : 'unpinned'];
    }

    public function deleteComment() {
        $this->ensureArticleEnhancementSchema();

        $commentId = Input::getInteger("id");
        $uid = App::user()->getUid();

        if ($commentId < 1) {
            return ["error" => true, "message" => "Gecersiz yorum secildi."];
        }

        $comment = DB::table('article_comments')->where('id', $commentId)->first();
        if (!$comment) {
            return ["error" => true, "message" => "Yorum bulunamadi."];
        }
        if ((int)$comment->uid !== (int)$uid) {
            return ["error" => true, "message" => "Sadece kendi yorumunu silebilirsin."];
        }

        $articleId = (int)$comment->article_id;
        $rows = DB::table('article_comments')->where('article_id', $articleId)->get(['id', 'parent_id']);

        $childrenMap = [];
        foreach ($rows as $row) {
            $parentId = (int)$row->parent_id;
            if (!isset($childrenMap[$parentId])) {
                $childrenMap[$parentId] = [];
            }
            $childrenMap[$parentId][] = (int)$row->id;
        }

        $idsToDelete = [];
        $stack = [$commentId];
        while (!empty($stack)) {
            $currentId = (int)array_pop($stack);
            if (in_array($currentId, $idsToDelete, true)) {
                continue;
            }
            $idsToDelete[] = $currentId;
            foreach (($childrenMap[$currentId] ?? []) as $childId) {
                $stack[] = (int)$childId;
            }
        }

        DB::beginTransaction();
        try {
            DB::table('article_comment_reactions')->whereIn('comment_id', $idsToDelete)->delete();
            DB::table('article_comment_votes')->whereIn('comment_id', $idsToDelete)->delete();
            DB::table('article_comments')->whereIn('id', $idsToDelete)->delete();
            DB::commit();
            return ["success" => true];
        } catch (\Exception $e) {
            DB::rollBack();
            return ["error" => true, "message" => "Yorum silinemedi."];
        }
    }

    public function voteComment() {
        $this->ensureArticleEnhancementSchema();
        $commentId = Input::getInteger("id");
        $uid = App::user()->getUid();

        $existing = DB::table('article_comment_votes')->where(['comment_id' => $commentId, 'uid' => $uid])->exists();
        if ($existing) {
            return ["error" => true, "message" => "Bu analize zaten destek verdiniz!"];
        }

        DB::beginTransaction();
        try {
            DB::table('article_comment_votes')->insert(["comment_id" => $commentId, "uid" => $uid]);
            DB::table('article_comments')->where('id', $commentId)->increment('votes');
            DB::commit();
            return ["success" => true];
        } catch (\Exception $e) { 
            DB::rollBack(); 
            return ["error" => true, "message" => "Sistem hatası."];
        }
    }

    public function reactComment() {
        $this->ensureArticleEnhancementSchema();

        $commentId = Input::getInteger("id");
        $reaction = trim(Input::getString("reaction"));
        $uid = App::user()->getUid();
        $allowedReactions = ['brain', 'clown', 'sword', 'shield'];

        if ($commentId < 1 || !in_array($reaction, $allowedReactions, true)) {
            return ["error" => true, "message" => "Gecersiz tepki secildi."];
        }

        $existing = DB::table('article_comment_reactions')
            ->where(['comment_id' => $commentId, 'uid' => $uid, 'reaction' => $reaction])
            ->first();

        if ($existing) {
            DB::table('article_comment_reactions')->where('id', $existing->id)->delete();
            return ["success" => true, "status" => "removed"];
        }

        DB::table('article_comment_reactions')->insert([
            "comment_id" => $commentId,
            "uid" => $uid,
            "reaction" => $reaction,
            "created_at" => date("Y-m-d H:i:s"),
            "updated_at" => date("Y-m-d H:i:s")
        ]);

        return ["success" => true, "status" => "added"];
    }

    public function factCheckArticle() {
        $this->ensureArticleEnhancementSchema();

        $articleId = Input::getInteger("id");
        $verdict = Input::getString("verdict") === 'true' ? 1 : 0;
        $article = NewspaperArticle::find($articleId);

        if (!$article) {
            return ["error" => true, "message" => "Makale bulunamadi."];
        }

        DB::table('article_fact_checks')->updateOrInsert(
            [
                'article_id' => $articleId,
                'uid' => App::user()->getUid()
            ],
            [
                'verdict' => $verdict,
                'created_at' => date("Y-m-d H:i:s"),
                'updated_at' => date("Y-m-d H:i:s")
            ]
        );

        return ["success" => true];
    }

    public function promoteArticle() { 
        $id = Input::getInteger("id"); 
        $u = App::user()->getUid(); 
        $a = NewspaperArticle::find($id); 
        if (!$a || $a->uid != $u) return ["error" => true, "message" => "Geçersiz işlem."];
        
        $m = UserMoney::where('uid', $u)->first(); 
        if (!$m || $m->gold < 2) return ["error" => true, "message" => "Yetersiz Altın bakiyesi."];
        
        DB::beginTransaction(); 
        try { 
            $m->gold -= 2; $m->save(); 
            $a->is_promoted = 1; $a->save(); 
            DB::commit(); return ["success" => true]; 
        } catch (\Exception $e) { DB::rollBack(); return ["error" => true, "message" => "Hata oluştu."]; } 
    }

    public function endorseArticle() { 
        $this->ensureArticleEnhancementSchema();
        $id = Input::getInteger("id"); 
        $amt = Input::getFloat("amount"); 
        $cur = Input::getString("currency"); 
        $u = App::user()->getUid(); 
        $a = NewspaperArticle::find($id); 
        
        if (!$a || $a->uid == $u || $amt <= 0) return ["error" => true, "message" => "Kendinize fon gönderemezsiniz veya geçersiz tutar."];
        
        $col = ($cur === 'gold') ? 'gold' : App::user()->getLocation()["country"]["currency"]; 
        $m = UserMoney::where('uid', $u)->first(); 
        if (!$m || $m->$col < $amt) return ["error" => true, "message" => "Cüzdanınızda yeterli bakiye bulunmuyor."];
        
        $auth = UserMoney::where('uid', $a->uid)->first(); 
        DB::beginTransaction(); 
        try { 
            $m->$col -= $amt; $m->save(); 
            $auth->$col += $amt; $auth->save();
            DB::table('article_endorsements')->insert([
                'article_id' => $id,
                'uid' => $u,
                'amount' => $amt,
                'currency' => $col,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            DB::commit(); return ["success" => true]; 
        } catch (\Exception $e) { DB::rollBack(); return ["error" => true, "message" => "Transfer başarısız."]; } 
    }

    // --- ABONE OL DÜZELTİLDİ (404 HATASI İÇİN YAZAR UID DESTEĞİ EKLENDİ) ---
    public function subscribe($fId = null) { 
        $nId = $fId ?: Input::getInteger("id"); 
        $authorUid = Input::getInteger("uid"); // Makaleden yazarın UID'si ile gelirse fallback

        if (!$nId && $authorUid) {
            $n = NewspaperModel::where('uid', $authorUid)->first();
            if ($n) {
                $nId = $n->id;
            } else {
                return ["error" => true, "message" => "Bu yazarın henüz bir gazetesi bulunmuyor."];
            }
        }

        $u = App::user()->getUid(); 
        $n = NewspaperModel::find($nId); 
        if (!$n) return ["error" => true, "message" => "Gazete veritabanında bulunamadı."];
        if ($n->uid == $u) return ["error" => true, "message" => "Kendi gazetenize abone olamazsınız."];
        
        $ex = DB::table('newspaper_subscribers')->where(['newspaper_id' => $nId, 'uid' => $u])->first(); 
        if ($ex) { 
            DB::table('newspaper_subscribers')->where('id', $ex->id)->delete(); 
            $n->decrement('subscribers'); return ["status" => "unsubscribed", "success" => true]; 
        } else { 
            DB::table('newspaper_subscribers')->insert(['newspaper_id' => $nId, 'uid' => $u]); 
            $n->increment('subscribers'); return ["status" => "subscribed", "success" => true]; 
        } 
    }

    // --- OY VER (SQL DUPLICATE) HATASI DÜZELTİLDİ ---
    public function voteArticle() { 
        $id = Input::getInteger("id");
        $uid = App::user()->getUid();

        // Daha önce bu makaleye oy verilmiş mi?
        $existing = ArticleVote::where(["article" => $id, "uid" => $uid])->first();
        if ($existing) {
            return ["error" => true, "message" => "Bu makaleye zaten destek verdiniz."];
        }

        $a = NewspaperArticle::find($id); 
        if (!$a) return ["error" => true, "message" => "Makale bulunamadı."];

        $a->votes++; 
        $a->save(); 
        ArticleVote::create(["article" => $a->id, "uid" => $uid]); 
        
        return ["success" => true]; 
    }

    public function deleteArticle() { 
        $a = NewspaperArticle::find(Input::getInteger("id")); 
        if (!$a || (!App::user()->isAdminOrMod() && $a->uid != App::user()->getUid())) return ["error" => true, "message" => "Geçersiz yetki."];
        $a->delete(); return true; 
    }

    public function editArticle () {
        $article = NewspaperArticle::find(Input::getInteger("id"));
        if (!$article || $article->uid != App::user()->getUid()) return ["error" => true, "message" => "Geçersiz yetki."];
        
        DB::statement("SET NAMES 'utf8mb4'");
        $article->title = isset($_POST["title"]) ? trim(strip_tags($_POST["title"])) : $article->title;
        $article->text = isset($_POST["text"]) ? trim(strip_tags($_POST["text"])) : $article->text;
        $article->save(); return ["success" => true];
    }

    public function subscribeByAuthor() { 
        $u = Input::getInteger("uid"); 
        $n = NewspaperModel::where('uid', $u)->first(); 
        if (!$n) return ["error" => true, "message" => "Bu yazarın gazetesi yok."];
        return $this->subscribe($n->id); 
    }

    private function normalizeArticleForFeed(array $row, array $newspaperMetaByUid = [], array $commentCounts = [], array $followedNewspaperIds = [], array $categoryWeights = [])
    {
        $uid = (int)($row['uid'] ?? 0);
        $articleId = (int)($row['id'] ?? 0);
        $categoryRaw = (int)($row['category'] ?? 0);
        $title = trim((string)($row['title'] ?? 'Makale'));
        $text = trim((string)($row['text'] ?? ($row['content'] ?? '')));
        $cleanText = preg_replace('/\s+/u', ' ', strip_tags($text));
        $newspaperMeta = $newspaperMetaByUid[$uid] ?? null;
        $commentCount = (int)($row['comments_count'] ?? ($commentCounts[$articleId] ?? 0));
        $views = (int)($row['views'] ?? 0);
        $votes = (int)($row['votes'] ?? 0);
        $isPromoted = (int)($row['is_promoted'] ?? 0);
        $createdTimestamp = $this->resolveArticleTimestamp($row['created_at'] ?? null, $articleId);
        $hoursOld = max(1, (int)floor((time() - $createdTimestamp) / 3600));
        $freshnessScore = max(0, 96 - min(96, $hoursOld)) * 12;
        $viewScore = (int)round(log(max(1, $views) + 1, 2) * 42);
        $voteScore = $votes * 180;
        $commentScore = $commentCount * 130;
        $subscriberScore = min((int)($newspaperMeta['subscribers'] ?? 0), 500) * 2;
        $promotedScore = $isPromoted ? 260 : 0;
        $trendScore = $freshnessScore + $viewScore + $voteScore + $commentScore + $subscriberScore + $promotedScore + $articleId;
        $personalBoost = 0;

        if ($cleanText === null) {
            $cleanText = '';
        }

        $excerpt = mb_substr($cleanText, 0, 138, 'UTF-8');
        if (mb_strlen($cleanText, 'UTF-8') > 138) {
            $excerpt = rtrim($excerpt) . '...';
        }

        if (!empty($newspaperMeta['id']) && in_array((int)$newspaperMeta['id'], $followedNewspaperIds, true)) {
            $personalBoost += 1400;
        }

        if (!empty($categoryWeights[$categoryRaw])) {
            $personalBoost += ((int)$categoryWeights[$categoryRaw]) * 220;
        }

        $row['category_label'] = $this->mapArticleCategoryLabel($categoryRaw);
        $row['excerpt'] = $excerpt !== '' ? $excerpt : 'Detaylari okumak, tartismalara katilmak ve oy vermek icin makale sayfasina gidebilirsiniz.';
        $row['newspaper_name'] = $row['newspaper_name'] ?? ($newspaperMeta['name'] ?? 'Bagimsiz Gazete');
        $row['newspaper_id'] = (int)($row['newspaper_id'] ?? ($newspaperMeta['id'] ?? 0));
        $row['newspaper_subscribers'] = (int)($newspaperMeta['subscribers'] ?? 0);
        $row['comments_count'] = $commentCount;
        $row['sort_trend'] = $trendScore;
        $row['sort_personal'] = $trendScore + $personalBoost;
        $row['sort_latest'] = $createdTimestamp;

        if (!isset($row['creator']) || !is_array($row['creator'])) {
            $row['creator'] = [
                'nick' => $row['creator_nick'] ?? 'Anonim Yazar'
            ];
        } elseif (empty($row['creator']['nick']) && !empty($row['creator_nick'])) {
            $row['creator']['nick'] = $row['creator_nick'];
        }

        $row['title'] = $title !== '' ? $title : 'Makale';
        $row['text'] = $text;

        return $row;
    }

    private function mapArticleCategoryLabel($category)
    {
        switch ((int)$category) {
            case 1:
                return 'Siyaset';
            case 2:
                return 'Ekonomi';
            case 3:
                return 'Askeri';
            case 4:
                return 'Sosyal';
            default:
                return 'Genel Yayin';
        }
    }

    private function buildUserCategoryWeights($uid)
    {
        $weights = [];

        $voteWeights = DB::table('article_votes as av')
            ->join('newspaper_articles as a', 'av.article', '=', 'a.id')
            ->where('av.uid', $uid)
            ->select('a.category', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('a.category')
            ->pluck('aggregate_count', 'a.category')
            ->toArray();

        foreach ($voteWeights as $category => $count) {
            $weights[(int)$category] = (int)($weights[(int)$category] ?? 0) + (((int)$count) * 3);
        }

        $commentWeights = DB::table('article_comments as ac')
            ->join('newspaper_articles as a', 'ac.article_id', '=', 'a.id')
            ->where('ac.uid', $uid)
            ->select('a.category', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('a.category')
            ->pluck('aggregate_count', 'a.category')
            ->toArray();

        foreach ($commentWeights as $category => $count) {
            $weights[(int)$category] = (int)($weights[(int)$category] ?? 0) + (((int)$count) * 2);
        }

        return $weights;
    }

    private function resolveArticleTimestamp($createdAt, $fallbackId = 0)
    {
        try {
            if (!empty($createdAt)) {
                return Carbon::parse($createdAt)->timestamp;
            }
        } catch (\Exception $e) {
        }

        return time() - max(0, ((int)$fallbackId * 60));
    }

    private function ensureArticleEnhancementSchema()
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `article_endorsements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `article_id` int(11) NOT NULL,
            `uid` int(11) NOT NULL,
            `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
            `currency` varchar(20) NOT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `article_idx` (`article_id`),
            KEY `uid_idx` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS `article_comment_reactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `comment_id` int(11) NOT NULL,
            `uid` int(11) NOT NULL,
            `reaction` varchar(20) NOT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `comment_uid_reaction` (`comment_id`,`uid`,`reaction`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS `article_fact_checks` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `article_id` int(11) NOT NULL,
            `uid` int(11) NOT NULL,
            `verdict` tinyint(1) NOT NULL DEFAULT '1',
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `article_uid` (`article_id`,`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->ensureColumn('article_comments', 'parent_id', "ALTER TABLE `article_comments` ADD COLUMN `parent_id` int(11) NOT NULL DEFAULT '0' AFTER `article_id`");
        $this->ensureColumn('article_comments', 'mention_uid', "ALTER TABLE `article_comments` ADD COLUMN `mention_uid` int(11) NULL DEFAULT NULL AFTER `uid`");
        $this->ensureColumn('article_comments', 'is_pinned', "ALTER TABLE `article_comments` ADD COLUMN `is_pinned` tinyint(1) NOT NULL DEFAULT '0' AFTER `message`");
        $this->ensureColumn('article_comments', 'pin_currency', "ALTER TABLE `article_comments` ADD COLUMN `pin_currency` varchar(20) NULL DEFAULT NULL AFTER `is_pinned`");
        $this->ensureColumn('article_comments', 'pin_amount', "ALTER TABLE `article_comments` ADD COLUMN `pin_amount` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `pin_currency`");
        $this->ensureColumn('article_comments', 'votes', "ALTER TABLE `article_comments` ADD COLUMN `votes` int(11) NOT NULL DEFAULT '0' AFTER `pin_amount`");
        $this->ensureColumn('article_comments', 'updated_at', "ALTER TABLE `article_comments` ADD COLUMN `updated_at` datetime NULL DEFAULT NULL AFTER `created_at`");
        $this->ensureColumn('article_comments', 'is_author_pinned', "ALTER TABLE `article_comments` ADD COLUMN `is_author_pinned` tinyint(1) NOT NULL DEFAULT '0' AFTER `is_pinned`");
    }

    private function ensureColumn($table, $column, $sql)
    {
        $existing = DB::select("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "'");
        if (empty($existing)) {
            DB::statement($sql);
        }
    }

    private function buildSponsorLeaderboard($rows)
    {
        $sponsors = [];

        foreach ($rows as $row) {
            $uid = (int)$row->uid;
            if (empty($sponsors[$uid])) {
                $sponsors[$uid] = [
                    'uid' => $uid,
                    'nick' => (string)$row->nick,
                    'gold' => 0.0,
                    'locals' => [],
                    'score' => 0
                ];
            }

            $amount = (float)$row->total_amount;
            $currency = strtolower((string)$row->currency);

            if ($currency === 'gold') {
                $sponsors[$uid]['gold'] += $amount;
                $sponsors[$uid]['score'] += (int)round($amount * 1000);
            } else {
                $sponsors[$uid]['locals'][$currency] = (float)($sponsors[$uid]['locals'][$currency] ?? 0) + $amount;
                $sponsors[$uid]['score'] += (int)round($amount);
            }
        }

        $sponsors = array_values($sponsors);
        usort($sponsors, function ($left, $right) {
            return ((int)$right['score']) <=> ((int)$left['score']);
        });

        $sponsors = array_slice($sponsors, 0, 3);
        foreach ($sponsors as &$sponsor) {
            $sponsor['initial'] = strtoupper(mb_substr((string)$sponsor['nick'], 0, 1, 'UTF-8') ?: 'U');

            if ($sponsor['gold'] > 0) {
                $sponsor['support_text'] = rtrim(rtrim(number_format($sponsor['gold'], 2, '.', ''), '0'), '.') . ' ALTIN destekledi';
            } elseif (!empty($sponsor['locals'])) {
                $currencyKey = (string)array_key_first($sponsor['locals']);
                $amount = (float)$sponsor['locals'][$currencyKey];
                $sponsor['support_text'] = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . ' ' . strtoupper($currencyKey) . ' destekledi';
            } else {
                $sponsor['support_text'] = 'Destek verdi';
            }
        }
        unset($sponsor);

        return $sponsors;
    }

    private function buildAuthorIntelCard($uid, $newspaper = null)
    {
        $user = DB::table('users as u')
            ->leftJoin('regions as r', 'u.region', '=', 'r.id')
            ->leftJoin('countries as c', 'r.country', '=', 'c.id')
            ->select('u.id', 'u.nick', 'u.level', 'u.xp', 'u.strength', 'r.name as region_name', 'c.name as country_name')
            ->where('u.id', $uid)
            ->first();

        if (!$user) {
            return null;
        }

        $partyMember = DB::table('party_members as pm')
            ->leftJoin('political_parties as pp', 'pm.party', '=', 'pp.id')
            ->select('pm.level', 'pp.name as party_name')
            ->where('pm.uid', $uid)
            ->first();

        $isCongress = DB::table('congress_members')->where('uid', $uid)->exists();
        $role = 'Bagimsiz Analist';

        if ($partyMember && (int)$partyMember->level === 3) {
            $role = 'Parti Lideri';
        } elseif ($isCongress) {
            $role = 'Kongre Uyesi';
        } elseif ($partyMember) {
            $role = 'Siyasi Operatif';
        }

        return [
            'nick' => (string)$user->nick,
            'initial' => strtoupper(mb_substr((string)$user->nick, 0, 1, 'UTF-8') ?: 'U'),
            'rank' => $this->mapMilitaryRank((int)$user->level),
            'country' => (string)($user->country_name ?: 'Bilinmiyor'),
            'region' => (string)($user->region_name ?: 'Bilinmeyen Bolge'),
            'political_role' => $role,
            'party_name' => $partyMember && !empty($partyMember->party_name) ? (string)$partyMember->party_name : 'Bagimsiz',
            'level' => (int)$user->level,
            'xp' => (int)$user->xp,
            'strength' => (int)$user->strength,
            'newspaper_name' => $newspaper ? (string)$newspaper->name : 'Bagimsiz Gazete'
        ];
    }

    private function mapMilitaryRank($level)
    {
        if ($level >= 50) {
            return 'Maresal';
        }
        if ($level >= 35) {
            return 'General';
        }
        if ($level >= 25) {
            return 'Albay';
        }
        if ($level >= 15) {
            return 'Yuzbasi';
        }
        if ($level >= 8) {
            return 'Uzman';
        }

        return 'Er';
    }

    private function buildFactCheckSummary($articleId, $uid)
    {
        $rows = DB::table('article_fact_checks')
            ->select('verdict', DB::raw('COUNT(*) as aggregate_count'))
            ->where('article_id', $articleId)
            ->groupBy('verdict')
            ->get();

        $truthCount = 0;
        $falseCount = 0;
        foreach ($rows as $row) {
            if ((int)$row->verdict === 1) {
                $truthCount = (int)$row->aggregate_count;
            } else {
                $falseCount = (int)$row->aggregate_count;
            }
        }

        $totalVotes = $truthCount + $falseCount;
        $totalBase = max(1, $totalVotes);
        $truthPercent = (int)round(($truthCount / $totalBase) * 100);
        $falsePercent = (int)round(($falseCount / $totalBase) * 100);
        $myVerdictRow = DB::table('article_fact_checks')
            ->where('article_id', $articleId)
            ->where('uid', $uid)
            ->first();

        return [
            'truth_count' => $truthCount,
            'false_count' => $falseCount,
            'truth_percent' => $truthPercent,
            'false_percent' => $falsePercent,
            'total' => $totalVotes,
            'my_verdict' => $myVerdictRow ? ((int)$myVerdictRow->verdict === 1 ? 'true' : 'false') : null,
            'is_suspicious' => $totalVotes >= 5 && $falsePercent >= 70
        ];
    }

    private function buildCommentTree(array $rows, array $reactionSummary = [], array $myReactionMap = [])
    {
        $items = [];
        $tree = [];
        $reactionKeys = ['brain', 'clown', 'sword', 'shield'];

        foreach ($rows as $row) {
            $commentId = (int)($row['id'] ?? 0);
            $row['parent_id'] = (int)($row['parent_id'] ?? 0);
            $row['is_pinned'] = (int)($row['is_pinned'] ?? 0);
            $row['is_author_pinned'] = (int)($row['is_author_pinned'] ?? 0);
            $row['votes'] = (int)($row['votes'] ?? 0);
            $row['depth'] = 0;
            $row['children'] = [];
            $row['reactions'] = [];
            $row['my_reactions'] = $myReactionMap[$commentId] ?? [];
            $row['is_edited'] = !empty($row['updated_at']) && !empty($row['created_at']) && strtotime((string)$row['updated_at']) > strtotime((string)$row['created_at']);

            foreach ($reactionKeys as $reactionKey) {
                $row['reactions'][$reactionKey] = (int)($reactionSummary[$commentId][$reactionKey] ?? 0);
            }

            $items[$commentId] = $row;
        }

        foreach ($items as $commentId => &$item) {
            $parentId = (int)$item['parent_id'];
            if ($parentId > 0 && !empty($items[$parentId])) {
                $item['depth'] = 1;
                $items[$parentId]['children'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        $sortComments = function (&$comments) use (&$sortComments) {
            usort($comments, function ($left, $right) {
                $leftPinned = ((int)$left['is_pinned']) + ((int)($left['is_author_pinned'] ?? 0));
                $rightPinned = ((int)$right['is_pinned']) + ((int)($right['is_author_pinned'] ?? 0));
                if ($leftPinned !== $rightPinned) {
                    return $rightPinned <=> $leftPinned;
                }
                if ((int)$left['votes'] !== (int)$right['votes']) {
                    return ((int)$right['votes']) <=> ((int)$left['votes']);
                }
                return strcmp((string)$left['created_at'], (string)$right['created_at']);
            });

            foreach ($comments as &$comment) {
                if (!empty($comment['children'])) {
                    $sortComments($comment['children']);
                }
            }
        };

        $sortComments($tree);

        return $tree;
    }

    public function showCreateForm () { 
        $myNewspaper = App::user()->getNewspaper(); 
        if (!empty($myNewspaper)) { 
            $baseUrl = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']), '/'); 
            header("Location: " . $baseUrl . "/newspaper/" . $myNewspaper->id); exit; 
        } 
        return $this->render('news/createNewspaper.html.twig', ["creationCost" => NewspaperModel::CREATION_COST]); 
    }

    public function showNewspaper ($id) { 
        $n = NewspaperModel::find($id); 
        if (!$n) throw new AppException(AppException::INVALID_DATA); 
        $a = NewspaperArticle::where(["uid" => $n->uid])->orderBy('id', 'DESC')->get(); 
        $is = DB::table('newspaper_subscribers')->where('newspaper_id', $id)->where('uid', App::user()->getUid())->exists(); 
        return $this->render('news/newspaperProfile.html.twig', ["newspaper" => $n->toArray(), "articles" => $a ? $a->toArray() : [], "isSubscribed" => $is]); 
    }
	
	// --- EKSİK OLAN GAZETE KURULUM (POST) FONKSİYONU ---
    public function createNewspaper() {
        $uid = App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'newspaper_create',
            'uid:' . (int) $uid,
            2,
            86400,
            86400,
            'Gazete kurma denemesi limiti doldu. Lutfen daha sonra tekrar deneyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $name = trim(strip_tags($_POST["name"] ?? ''));
        $desc = trim(strip_tags($_POST["description"] ?? ''));
        $user = App::user()->getUser();
        $location = null;

        try {
            $location = App::session()->getLocation();
        } catch (\Throwable $e) {
            $location = null;
        }

        $countryId = (int)($location["country"]["id"] ?? ($user["country_id"] ?? 0));

        if (mb_strlen($name, 'UTF-8') < 4 || mb_strlen($desc, 'UTF-8') < 10) {
            return ["error" => true, "message" => "Kuruluş adı veya açıklama çok kısa."];
        }

        if ($countryId < 1) {
            return ["error" => true, "message" => "Gazete kurmak için geçerli bir ülke konumu bulunamadı."];
        }

        // Kullanıcının zaten gazetesi var mı?
        $existing = NewspaperModel::where('uid', $uid)->first();
        if ($existing) {
            return ["error" => true, "message" => "Sistemde zaten kayıtlı bir medya ağınız bulunuyor."];
        }

        // Altın kontrolü
        $cost = NewspaperModel::CREATION_COST ?? 2;
        $m = UserMoney::where('uid', $uid)->first();
        if (!$m || $m->gold < $cost) {
            return ["error" => true, "message" => "Kuruluş bedelini ödemek için yeterli altınınız yok."];
        }

        DB::beginTransaction();
        try {
            // Altını düş
            $m->gold -= $cost;
            $m->save();

            // Yeni Gazeteyi Veritabanına Ekle
            $newspaper = new NewspaperModel();
            $newspaper->name = $name;
            $newspaper->description = $desc;
            $newspaper->uid = $uid;
            $newspaper->country = $countryId;
            $newspaper->subscribers = 0;
            $newspaper->save();

            DB::commit();
            return ["success" => true, "id" => $newspaper->id];
        } catch (\Exception $e) {
            DB::rollBack();
            return ["error" => true, "message" => "Sistem veritabanı hatası."];
        }
    }
}
