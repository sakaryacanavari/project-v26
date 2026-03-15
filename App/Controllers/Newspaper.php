<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Gazete ve makale controller'ı.
 */
class Newspaper extends Controller
{
    public function showHome()
    {
        $articles = DB::table('newspaper_articles')
            ->join('newspapers', 'newspaper_articles.newspaper', '=', 'newspapers.id')
            ->orderBy('newspaper_articles.created_at', 'desc')
            ->limit(20)
            ->select('newspaper_articles.*', 'newspapers.name as newspaper_name')
            ->get();

        return $this->render('news/home.html.twig', ['articles' => $articles]);
    }

    public function showArticle($id)
    {
        $article = DB::table('newspaper_articles')
            ->join('newspapers', 'newspaper_articles.newspaper', '=', 'newspapers.id')
            ->where('newspaper_articles.id', $id)
            ->select('newspaper_articles.*', 'newspapers.name as newspaper_name')
            ->first();

        if (!$article) {
            return $this->redirect('/news');
        }

        return $this->render('news/article.html.twig', ['article' => $article]);
    }

    public function showCreateArticle()
    {
        $uid = $this->uid();
        $newspaper = DB::table('newspapers')->where('uid', $uid)->first();
        return $this->render('news/create.html.twig', ['newspaper' => $newspaper]);
    }

    public function showCreateForm()
    {
        return $this->render('newspaper/create.html.twig');
    }

    public function showNewspaper($id)
    {
        $newspaper = DB::table('newspapers')->where('id', $id)->first();
        $articles  = DB::table('newspaper_articles')
            ->where('newspaper', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->render('newspaper/show.html.twig', [
            'newspaper' => $newspaper,
            'articles'  => $articles,
        ]);
    }
}
