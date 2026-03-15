<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Ana sayfa controller'ı.
 */
class Home extends Controller
{
    public function showHomepage()
    {
        $uid = $this->uid();

        // Güncel haber başlıkları
        $news = DB::table('newspaper_articles')
            ->join('newspapers', 'newspaper_articles.newspaper', '=', 'newspapers.id')
            ->orderBy('newspaper_articles.created_at', 'desc')
            ->limit(5)
            ->select('newspaper_articles.*', 'newspapers.name as newspaper_name')
            ->get();

        return $this->render('home/home.html.twig', [
            'news' => $news,
        ]);
    }
}
