<?php
use \App\Controllers\User;
use \App\Controllers\Home;
use \App\Controllers\Company;
use \App\Controllers\Congress;
use \App\Controllers\Market;
use \App\Controllers\PoliticalParty;
use \App\Controllers\WorkOffers;
use \App\Controllers\Newspaper;
use \App\Controllers\War;
use \App\Controllers\WorldMap;
use \App\Controllers\Gym;
use \App\Controllers\Job;
use \App\Controllers\UserSettings;
use \App\Controllers\BugFix;
use \App\Controllers\Notifications;
use \App\Controllers\Messages;
use \App\Controllers\Shout;
use \App\Controllers\Social;
use \App\Controllers\BoxController;
use \App\Controllers\BlackMarket;
use \App\Controllers\AdminOps;
use \App\System\App as AppController;
use \Illuminate\Database\Capsule\Manager as DB;

$ensureLogged = function($request, $response, $next) use ($app) {
    $app->getContainer()->get("session")->ensureLogged();
    return $next($request, $response);
};

$congressistsOnly = function($request, $response, $next) use ($app) {
    if (!AppController::user()->isCongressist()) {
        return AppController::container()->get("view")->render($response, "congress/access_restricted.html.twig", []);
    }
    return $next($request, $response);
};

$adminOnly = function($request, $response, $next) use ($app) {
    $userId = AppController::session()->getUid();
    $isAdmin = DB::table('users')->where('id', $userId)->value('is_admin') == 1;

    if (!$isAdmin) {
        return $response->withStatus(403)->write(json_encode([
            'error' => true,
            'message' => 'Bu işlem için yetkiniz yok.'
        ]));
    }

    return $next($request, $response);
};

$app->get('/login', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('showLogin');
})->setName('login');

$app->post('/login', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('doLogin');
});

$app->get('/signup', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('showSignup');
})->setName('signup');

$app->post('/signup', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('signup');
});

$app->get('/forgot-password', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('showForgotPassword');
})->setName('forgotPassword');

$app->post('/forgot-password', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('requestPasswordReset');
});

$app->get('/reset-password/{token}', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('showResetPassword', $args['token']);
})->setName('resetPassword');

$app->post('/reset-password/{token}', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('resetPasswordWithToken', $args['token']);
});

$app->post('/language', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('setGuestLanguage');
})->setName('guestLanguage');

$app->post('/api/auth/check-nick', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('checkNicknameAvailability');
})->setName('checkNicknameAvailability');

$app->get('/verify-email/{token}', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('verifyEmail', $args['token']);
})->setName('verifyEmail');

$app->post('/api/auth/resend-verification', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->json('resendVerificationEmail');
})->setName('resendVerificationEmail');

$app->get('/logout', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('logout');
})->setName('logout');

$app->group('', function () use ($app, $congressistsOnly, $adminOnly) {

    $app->get('/', function($request, $response, $args) use ($app) {
        $ct = new Home($app, $response);
        $ct->exec('showHomepage');
    })->setName('home');

    $app->get('/work-offers', function($request, $response, $args) use ($app) {
        $ct = new WorkOffers($app, $response);
        $ct->exec('showList');
    })->setName('workOffers');

    $app->get('/workoffers', function($request, $response, $args) use ($app) {
        $queryParams = $request->getQueryParams();
        $target = $app->getContainer()->get('router')->pathFor('workOffers');

        if (!empty($queryParams)) {
            $target .= '?' . http_build_query($queryParams);
        }

        return $response->withRedirect($target, 302);
    });

    $app->get('/resign', function($request, $response, $args) use ($app) {
        $ct = new Job($app, $response);
        $ct->exec('resign');
    })->setName('resign');

    $app->get('/mycompanies', function($request, $response, $args) use ($app) {
        $ct = new Company($app, $response);
        $ct->exec('showMyCompanies');
    })->setName('myCompanies');

    $app->get('/create-company', function($request, $response, $args) use ($app) {
        $ct = new Company($app, $response);
        $ct->exec('showCreate');
    })->setName('createCompany');

    $app->get('/storage', function($request, $response, $args) use ($app) {
        $ct = new User($app, $response);
        $ct->exec('showStorage');
    })->setName('storage');

    $app->get('/gyms', function($request, $response, $args) use ($app) {
        $ct = new User($app, $response);
        $ct->exec('showGyms');
    })->setName('gyms');

    $app->get('/settings', function($request, $response, $args) use ($app) {
        $ct = new UserSettings($app, $response);
        $ct->exec('showSettings');
    })->setName('settings');

    $app->get('/bug-report', function($request, $response, $args) use ($app) {
        $ct = new BugFix($app, $response);
        $ct->exec('index');
    })->setName('bugReport');

    $app->get('/wars', function($request, $response, $args) use ($app) {
        $ct = new War($app, $response);
        $ct->exec('showList');
    })->setName('wars');

    $app->get('/map', function($request, $response, $args) use ($app) {
        $ct = new WorldMap($app, $response);
        $ct->exec('showMap');
    })->setName('worldMap');

    $app->get('/box', function($request, $response, $args) use ($app) {
        $ct = new BoxController($app, $response);
        $ct->exec('index');
    })->setName('box');

    $app->get('/blackmarket', function($request, $response, $args) use ($app) {
        $ct = new BlackMarket($app, $response);
        $ct->exec('index');
    })->setName('blackmarket');

    $app->get('/blackmarket/create', function($request, $response, $args) use ($app) {
        $ct = new BlackMarket($app, $response);
        $ct->exec('createAd');
    })->setName('createBlackMarketAd');

    $app->get('/marketplace', function($request, $response, $args) use ($app) {
        $ct = new Market($app, $response);
        $ct->exec('showMarketplaceHome');
    })->setName('marketplace');

    $app->get('/marketplace/offers/{item}[/{quality}]', function($request, $response, $args) use ($app) {
        $ct = new Market($app, $response);
        $ct->exec('showItemOffers', $args['item'], $args['quality'] ?? 0);
    })->setName('marketplaceOffers');

    $app->post('/marketplace/buy', function($request, $response, $args) use ($app) {
        $ct = new Market($app, $response);
        $ct->exec('buyAndRedirect');
    })->setName('marketBuyPage');

    $app->get('/market/trends/{item}/{quality}', function($request, $response, $args) use ($app) {
        $ct = new Market($app, $response);
        return $ct->json('getMarketTrends', $args['item'], $args['quality'] ?? 0);
    })->setName('marketTrends');

    $app->get('/messages', function($request, $response, $args) use ($app) {
        $ct = new Messages($app, $response);
        $ct->exec('showInbox');
    })->setName('messages');

    $app->get('/messages/{uid}', function($request, $response, $args) use ($app) {
        $ct = new Messages($app, $response);
        $ct->exec('showThread', $args['uid']);
    })->setName('messagesThread');

    $app->get('/citizen/{uid}', function($request, $response, $args) use ($app) {
        $ct = new User($app, $response);
        $ct->exec('showCitizen', $args['uid']);
    })->setName('citizenProfile');

    $app->get('/notifications', function($request, $response, $args) use ($app) {
        $ct = new Notifications($app, $response);
        $ct->exec('showCenter');
    })->setName('notifications');

    $app->get('/social', function($request, $response, $args) use ($app) {
        $ct = new Social($app, $response);
        $ct->exec('index');
    })->setName('social');

    $app->group('/congress', function () use ($app) {
        $app->get('', function($request, $response, $args) use ($app) {
            $ct = new Congress($app, $response);
            $ct->exec('showHome');
        })->setName('congressHome');

        $app->get('/law/{id}', function($request, $response, $args) use ($app) {
            $ct = new Congress($app, $response);
            $ct->exec('showLawProposal', $args["id"]);
        })->setName('congressLaw');
    });

    $app->get('/elections', function($request, $response, $args) use ($app) {
        $ct = new Congress($app, $response);
        $ct->exec('showElections');
    })->setName('electionsHome');

    $app->get('/elections/archive[/{type}]', function($request, $response, $args) use ($app) {
        $ct = new Congress($app, $response);
        $ct->exec('showElectionArchive', $args['type'] ?? null);
    })->setName('electionsArchive');

    $app->get('/parties', function($request, $response, $args) use ($app) {
        $ct = new PoliticalParty($app, $response);
        $ct->exec('showList');
    })->setName('partyList');

    $app->get('/party/create', function($request, $response, $args) use ($app) {
        $ct = new PoliticalParty($app, $response);
        $ct->exec('showCreationForm');
    })->setName('partyCreationForm');

    $app->get('/party/{id}[/{slug}]', function($request, $response, $args) use ($app) {
        $ct = new PoliticalParty($app, $response);
        $ct->exec('showParty', $args['id']);
    })->setName('party');

    $app->get('/admin/ops', function($request, $response, $args) use ($app) {
        $ct = new AdminOps($app, $response);
        return $ct->exec('index');
    })->setName('adminOps')->add($adminOnly);

    $app->group('/news', function () use ($app) {
        $app->get('', function($request, $response, $args) use ($app) {
            $ct = new Newspaper($app, $response);
            $ct->exec('showHome');
        })->setName('newspaperList');

        $app->get('/article/{id}', function($request, $response, $args) use ($app) {
            $ct = new Newspaper($app, $response);
            $ct->exec('showArticle', $args["id"]);
        })->setName('showArticle');

        $app->get('/create', function($request, $response, $args) use ($app) {
            $ct = new Newspaper($app, $response);
            $ct->exec('showCreateArticle');
        })->setName('createArticle');
    });

    $app->group('/newspaper', function () use ($app) {
        $app->get('/create', function($request, $response, $args) use ($app) {
            $ct = new Newspaper($app, $response);
            $ct->exec('showCreateForm');
        })->setName('createNewspaper');

        $app->get('/{id}', function($request, $response, $args) use ($app) {
            $ct = new Newspaper($app, $response);
            $ct->exec('showNewspaper', $args["id"]);
        })->setName('showNewspaper');
    });

})->add($ensureLogged);

$app->group('/api', function () use ($app, $adminOnly) {

    $app->post('/box/open', function($req, $res) use ($app) {
        $ct = new BoxController($app, $res);
        return $ct->json('openBox');
    });

    $app->post('/box/upgrade', function($req, $res) use ($app) {
        $ct = new BoxController($app, $res);
        return $ct->json('upgrade');
    });

    $app->post('/blackmarket/store', function($req, $res) use ($app) {
        $ct = new BlackMarket($app, $res);
        return $ct->json('storeAd');
    })->setName('storeBlackMarketAd');

    $app->post('/blackmarket/buy/{id}', function($req, $res) use ($app) {
        $ct = new BlackMarket($app, $res);
        return $ct->json('buyItem');
    })->setName('buyBlackMarketItem');

    $app->post('/messages/send', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('send');
    });

    $app->post('/messages/fetch', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('fetch');
    });

    $app->post('/messages/threads', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('threads');
    });

    $app->post('/messages/search-users', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('searchUsers');
    });

    $app->post('/messages/mark-read', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('markRead');
    });

    $app->post('/messages/archive-bulk', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('archiveBulk');
    });

    $app->post('/messages/edit', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('edit');
    });

    $app->post('/messages/delete', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('delete');
    });

    $app->post('/messages/pin-toggle', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('togglePin');
    });

    $app->post('/messages/search', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('searchMessages');
    });

    $app->post('/shouts/create', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('create');
    });

    $app->post('/shouts/list', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('listFeed');
    });

    $app->post('/shouts/thread', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('thread');
    });

    $app->post('/shouts/vote-poll', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('votePoll');
    });

    $app->post('/shouts/tip', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('tip');
    });

    $app->post('/shouts/like', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('toggleLike');
    });

    $app->post('/shouts/report', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('report');
    });

    $app->post('/shouts/edit', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('edit');
    });

    $app->post('/shouts/delete', function($req, $res) use ($app) {
        $ct = new Shout($app, $res);
        return $ct->json('delete');
    });

    $app->post('/notifications/unread-count', function($req, $res) use ($app) {
        $ct = new Notifications($app, $res);
        return $ct->json('unreadCount');
    });

    $app->post('/notifications/list', function($req, $res) use ($app) {
        $ct = new Notifications($app, $res);
        return $ct->json('list');
    });

    $app->post('/notifications/mark-read', function($req, $res) use ($app) {
        $ct = new Notifications($app, $res);
        return $ct->json('markRead');
    });

    $app->post('/notifications/mark-all-read', function($req, $res) use ($app) {
        $ct = new Notifications($app, $res);
        return $ct->json('markAllRead');
    });

    $app->post('/social/respond-friend-request', function($req, $res) use ($app) {
        $ct = new Social($app, $res);
        return $ct->json('respondFriendRequest');
    });

    $app->post('/social/toggle-follow', function($req, $res) use ($app) {
        $ct = new Social($app, $res);
        return $ct->json('toggleFollow');
    });

    $app->post('/social/send-friend-request', function($req, $res) use ($app) {
        $ct = new Social($app, $res);
        return $ct->json('sendFriendRequest');
    });

    $app->group('/user/settings', function () use ($app) {
        $app->post('/profile', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updateProfile');
        });

        $app->post('/password', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updatePassword');
        });

        $app->post('/theme', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updateTheme');
        });

        $app->post('/language', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updateLanguage');
        });

        $app->post('/notifications', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updateNotifications');
        });

        $app->post('/dm-privacy', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updateDmPrivacy');
        });

        $app->post('/game-experience', function($req, $res) use ($app) {
            $ct = new UserSettings($app, $res);
            return $ct->json('updateGameExperience');
        });
    });

    $app->post('/gym/train', function($req, $res) use ($app) {
        $ct = new Gym($app, $res);
        $ct->json('train');
    });

    $app->get('/gym/status', function($req, $res) use ($app) {
        $ct = new Gym($app, $res);
        return $ct->json('status');
    });

    $app->post('/gym/train-extra', function($req, $res) use ($app) {
        $ct = new Gym($app, $res);
        $ct->json('extraTrain');
    });

    $app->post('/gym/spin-wheel', function($req, $res) use ($app) {
        $ct = new Gym($app, $res);
        $ct->json('spinWheel');
    });

    $app->post('/war/fight', function($req, $res) use ($app) {
        $ct = new War($app, $res);
        $ct->json('fight');
    });

    $app->post('/job/work', function($req, $res) use ($app) {
        $ct = new Job($app, $res);
        return $ct->json('work');
    })->setName('jobWork');

    $app->post('/job/resign', function($req, $res) use ($app) {
        $ct = new Job($app, $res);
        return $ct->json('resign');
    })->setName('jobResign');

    $app->post('/jobs/apply', function($req, $res) use ($app) {
        $ct = new WorkOffers($app, $res);
        return $ct->json('applySafe');
    })->setName('jobsApply');

    $app->group('/company', function() use ($app) {
        $app->post('/create', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            $ct->json('create');
        });

        $app->group('/market', function () use ($app) {
            $app->post('/buy', function($req, $res) use ($app) {
                $ct = new Market($app, $res);
                $ct->json('buy');
            })->setName('marketBuy');

            $app->post('/sell', function($req, $res) use ($app) {
                $ct = new Market($app, $res);
                $ct->json('sellSafe');
            })->setName('marketSell');
        });

        $app->post('/work', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            $ct->json('workAsManager');
        });

        $app->post('/produce', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            $ct->json('workAsManager');
        });

        $app->post('/work-as-manager', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            $ct->json('workAsManager');
        });

        $app->post('/work-offer/create', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            return $ct->json('createWorkOfferSafe');
        });

        $app->post('/work-offer/update', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            return $ct->json('updateWorkOfferSafe');
        });

        $app->post('/work-offer/cancel', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            return $ct->json('cancelWorkOffer');
        });
    });

    $app->group('/newspaper', function() use ($app) {
        $app->post('/create', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('createNewspaper');
        });

        $app->post('/subscribe', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('subscribe');
        });

        $app->post('/subscribe_by_author', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('subscribeByAuthor');
        });
    });

    $app->group('/article', function() use ($app) {
        $app->post('/create', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('publishArticle');
        });

        $app->post('/vote', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('voteArticle');
        });

        $app->post('/promote', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('promoteArticle');
        });

        $app->post('/endorse', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('endorseArticle');
        });

        $app->post('/update', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('editArticle');
        });

        $app->post('/delete', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('deleteArticle');
        });

        $app->post('/comment', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('postComment');
        });

        $app->post('/comment-vote', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('voteComment');
        });

        $app->post('/comment-react', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('reactComment');
        });

        $app->post('/comment-update', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('editComment');
        });

        $app->post('/comment-delete', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('deleteComment');
        });

        $app->post('/comment-pin', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('pinComment');
        });

        $app->post('/fact-check', function($req, $res) use ($app) {
            $ct = new Newspaper($app, $res);
            return $ct->json('factCheckArticle');
        });
    });

    $app->group('/party', function() use ($app) {
        $app->post('/create', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('create');
        });

        $app->post('/update', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('update');
        });

        $app->post('/join', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('join');
        });

        $app->post('/leave', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('leave');
        });

        $app->post('/donate', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('donate');
        });

        $app->post('/buy-ad', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('buyAd');
        });

        $app->post('/assign-role', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('assignRole');
        });

        $app->post('/remove-member', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('removeMember');
        });

        $app->post('/review-application', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('reviewJoinApplication');
        });

        $app->post('/create-coalition', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('createCoalition');
        });

        $app->post('/leave-coalition', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('leaveCoalition');
        });

        $app->post('/invite-to-coalition', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('inviteToCoalition');
        });

        $app->post('/accept-coalition-invite', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('acceptCoalitionInvite');
        });

        $app->post('/reject-coalition-invite', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('rejectCoalitionInvite');
        });

        $app->post('/donate-to-coalition', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('donateToCoalition');
        });

        $app->post('/distribute-coalition-fund', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('distributeCoalitionFund');
        });

        $app->post('/add-embargo', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('addEmbargo');
        });

        $app->post('/remove-embargo', function($req, $res) use ($app) {
            $ct = new PoliticalParty($app, $res);
            $ct->json('removeEmbargo');
        });
    });

    $app->group('/congress', function() use ($app) {
        $app->group('/presidential', function() use ($app) {
            $app->post('/apply', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('submitPresidentialApplication');
            });

            $app->post('/vote', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('votePresidentialCandidate');
            });

            $app->post('/resign', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('resignPresidentialApplication');
            });
        });

        $app->group('/party-election', function() use ($app) {
            $app->post('/apply', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('submitPartyLeaderApplication');
            });

            $app->post('/vote', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('votePartyLeaderCandidate');
            });

            $app->post('/resign', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('resignPartyLeaderApplication');
            });
        });

        $app->group('/candidate', function() use ($app) {
            $app->post('/apply', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('submitApplication');
            });

            $app->post('/vote', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('voteCandidate');
            });

            $app->post('/resign', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('resign');
            });

            $app->post('/remove', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('removeCongressCandidate');
            });
        });

        $app->group('/law', function() use ($app) {
            $app->post('/propose', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('proposeLaw');
            });

            $app->post('/vote', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('voteLaw');
            });

            $app->post('/veto', function($req, $res) use ($app) {
                $ct = new Congress($app, $res);
                $ct->json('presidentialVeto');
            });
        });

        $app->post('/emergency', function($req, $res) use ($app) {
            $ct = new Congress($app, $res);
            $ct->json('callEmergency');
        });

        $app->post('/whip', function($req, $res) use ($app) {
            $ct = new Congress($app, $res);
            $ct->json('setWhip');
        });

        $app->post('/revoke_state', function($req, $res) use ($app) {
            $ct = new Congress($app, $res);
            $ct->json('revokeState');
        });
    });

    $app->group('/bugs', function () use ($app) {
        $app->post('/create', function($req, $res) use ($app) {
            $ct = new BugFix($app, $res);
            $ct->json('create');
        });

        $app->post('/vote', function($req, $res) use ($app) {
            $ct = new BugFix($app, $res);
            $ct->json('toggleVote');
        });

        $app->post('/subscribe', function($req, $res) use ($app) {
            $ct = new BugFix($app, $res);
            $ct->json('subscribe');
        });

        $app->post('/unsubscribe', function($req, $res) use ($app) {
            $ct = new BugFix($app, $res);
            $ct->json('unsubscribe');
        });

        $app->post('/reply', function($req, $res) use ($app) {
            $ct = new BugFix($app, $res);
            $ct->json('adminReply');
        });
    })->add($adminOnly);

    $app->group('/admin', function () use ($app) {
        $app->post('/user-status', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('updateUserStatus');
        });

        $app->post('/cancel-party-ad', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('cancelPartyAd');
        });

        $app->post('/cancel-work-offer', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('cancelWorkOffer');
        });

        $app->post('/repair-party-coalitions', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('repairPartyCoalitions');
        });

        $app->post('/sync-user-gyms', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('syncUserGyms');
        });

        $app->post('/repair-party-applications', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('repairPartyApplications');
        });

        $app->post('/delete-article', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('deleteArticle');
        });

        $app->post('/delete-shout', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('deleteShout');
        });

        $app->post('/reopen-shout', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('reopenShout');
        });

        $app->post('/mute-shout-user', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('muteShoutUser');
        });

        $app->post('/clear-shout-restriction', function($req, $res) use ($app) {
            $ct = new AdminOps($app, $res);
            return $ct->json('clearShoutRestriction');
        });
    })->add($adminOnly);

    $app->any('/{class}/{method}', function ($request, $response, $args) use ($app) {
        $className = '\\App\\Controllers\\' . ucfirst($args['class']);
        $method = str_replace('-', '', ucwords($args['method'], '-'));
        $method = lcfirst($method);

        if (class_exists($className)) {
            $ct = new $className($app, $response);
            if (method_exists($ct, $method)) {
                return $ct->json($method);
            }
        }

        return $response->withStatus(404)->write("Sistem Hatası: Sınıf veya metod bulunamadı.");
    });

})->add($ensureLogged);
