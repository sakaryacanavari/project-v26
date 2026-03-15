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
use \App\Controllers\Social;
use \App\Controllers\BoxController;
use \App\Controllers\BlackMarket;
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

$app->get('/logout', function($request, $response, $args) use ($app) {
    $ct = new User($app, $response);
    $ct->exec('logout');
})->setName('logout');

$app->group('', function () use ($app, $congressistsOnly) {

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

    $app->post('/messages/mark-read', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('markRead');
    });

    $app->post('/messages/archive-bulk', function($req, $res) use ($app) {
        $ct = new Messages($app, $res);
        return $ct->json('archiveBulk');
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
    });

    $app->post('/gym/train', function($req, $res) use ($app) {
        $ct = new Gym($app, $res);
        $ct->json('train');
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
        return $ct->json('apply');
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
                $ct->json('sell');
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
            return $ct->json('createWorkOffer');
        });

        $app->post('/work-offer/update', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            return $ct->json('updateWorkOffer');
        });

        $app->post('/work-offer/cancel', function($req, $res) use ($app) {
            $ct = new Company($app, $res);
            return $ct->json('cancelWorkOffer');
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

$app->group('/api/admin', function () use ($app) {
})->add($ensureLogged)->add($adminOnly);  