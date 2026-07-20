<?php

namespace App\Controllers;

use App\Models\Country;
use App\Models\Item;
use App\Models\ItemOffer;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use App\System\MarketOrderService;

class Market extends Controller
{
    public function showItemOffers($item, $quality = 0)
    {
        $page = max(1, (int)($_GET["page"] ?? 1));
        $country = (int)($_GET["country"] ?? 1);
        $limitPerPage = 15;

        if ($item < 1 || $quality < 0) {
            throw new AppException(AppException::INVALID_DATA);
        }

        if ($country < 1) {
            $country = 1;
        }

        $itemInfo = Item::find($item);
        if (!$itemInfo) {
            throw new AppException(AppException::INVALID_DATA);
        }

        // ==========================================
        // GÜNCELLEME: RAW (Hammadde) Kalite Kısıtlaması İptal Edildi
        // ==========================================
        // if (($itemInfo["type"] ?? null) == Item::TYPE_RAW) {
        //     $quality = 0;
        // }

        $lifecycleReady = MarketOrderService::schemaAvailable();
        if ($lifecycleReady) {
            $q = MarketOrderService::activeOffersQuery($country)->where([
                "item" => $item,
                "quality" => $quality,
            ])->with(['seller', 'country']);
        } else {
            $q = ItemOffer::with(['seller', 'country'])->where([
                "country" => $country,
                "item" => $item,
                "quality" => $quality,
            ]);
        }

        if (is_object($q) && method_exists($q, 'orderBy')) {
            $q = $q->orderBy('price', 'asc');
        }

        if (is_object($q) && method_exists($q, 'paginate')) {
            $p = $q->paginate($limitPerPage, ['*'], 'page', $page)->toArray();
            $offersData = $p['data'] ?? [];
            $pager = [
                "current_page" => (int)($p["current_page"] ?? 1),
                "last_page"    => (int)($p["last_page"] ?? 1),
                "total"        => (int)($p["total"] ?? 0),
            ];
        } else {
            $rows = is_object($q) && method_exists($q, 'get') ? $q->get() : [];
            $rowsArr = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;

            $total = count($rowsArr);
            $lastPage = (int)max(1, (int)ceil($total / $limitPerPage));
            $page = min($page, $lastPage);

            $offset = ($page - 1) * $limitPerPage;
            $offersData = array_slice($rowsArr, $offset, $limitPerPage);

            $pager = [
                "current_page" => $page,
                "last_page"    => $lastPage,
                "total"        => $total,
            ];
        }

        // ==========================================
        // GÜNCELLEME: AJAX (SPA) YÖNLENDİRMESİ 
        // ==========================================
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $marketFlash = $this->pullMarketFlash();
            return $this->render('market/itemList.html.twig', [
                "offers"      => $offersData,
                "pager"       => $pager,
                "countryList" => Country::all()->toArray(),
                "marketStatus" => $marketFlash["status"] ?? null,
                "marketMessage" => $marketFlash["message"] ?? null,
            ]);
        }

        $marketFlash = $this->pullMarketFlash();
        return $this->render('market/itemList.html.twig', [
            "offers"      => $offersData,
            "pager"       => $pager,
            "countryList" => Country::all()->toArray(),
            "marketStatus" => $marketFlash["status"] ?? null,
            "marketMessage" => $marketFlash["message"] ?? null,
        ]);
    }

    public function showMarketplaceHome()
    {
        // HATA DÜZELTİLDİ: Sadece Dizi (Array) Kullanıldı
        $products = Item::where(["canBeSold" => true]);

        if (is_object($products) && method_exists($products, 'orderBy')) {
            $products = $products->orderBy('type', 'asc')->orderBy('id', 'asc');
            $products = method_exists($products, 'get') ? $products->get() : $products;
        }

        if (is_object($products) && method_exists($products, 'toArray')) {
            $products = $products->toArray();
        } elseif (!is_array($products)) {
            $products = (array)$products;
        }

        usort($products, function ($a, $b) {
            $ta = is_array($a) ? (int)($a['type'] ?? 0) : (int)($a->type ?? 0);
            $tb = is_array($b) ? (int)($b['type'] ?? 0) : (int)($b->type ?? 0);
            if ($ta !== $tb) return $ta <=> $tb;

            $ia = is_array($a) ? (int)($a['id'] ?? 0) : (int)($a->id ?? 0);
            $ib = is_array($b) ? (int)($b['id'] ?? 0) : (int)($b->id ?? 0);
            return $ia <=> $ib;
        });

        // ==========================================
        // GÜNCELLEME: İLK AÇILIŞTA VİTRİN TEKLİFLERİ
        // ==========================================
        $userCountryId = App::user()->getLocation()["country"]["id"] ?? 1;

        $lifecycleReady = MarketOrderService::schemaAvailable();
        if ($lifecycleReady) {
            $initialOffersQ = MarketOrderService::activeOffersQuery($userCountryId)
                ->with(['seller', 'country'])
                ->orderBy('price', 'asc');
        } else {
            $initialOffersQ = ItemOffer::with(['seller', 'country'])
                ->where(["country" => $userCountryId])
                ->orderBy('price', 'asc');
        }
            
        if (is_object($initialOffersQ) && method_exists($initialOffersQ, 'limit')) {
            $initialOffersQ = $initialOffersQ->limit(10);
        }

        $initialOffers = is_object($initialOffersQ) && method_exists($initialOffersQ, 'get') ? $initialOffersQ->get() : [];
        $initialOffersArr = is_object($initialOffers) && method_exists($initialOffers, 'toArray') ? $initialOffers->toArray() : (array)$initialOffers;
        
        $initialOffersArr = array_slice($initialOffersArr, 0, 10);

        $marketFlash = $this->pullMarketFlash();

        return $this->render('market/marketplaceHome.html.twig', [
            "products"    => $products,
            "countryList" => Country::all()->toArray(),
            "offers"      => $initialOffersArr,
            "marketStatus" => $marketFlash["status"] ?? null,
            "marketMessage" => $marketFlash["message"] ?? null,
        ]);
    }

    public function buy()
    {
        $id = (int)($_POST["id"] ?? 0);
        $quantity = (int)($_POST["quantity"] ?? 0);
        $uid = App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'market_buy',
            'uid:' . (int) $uid,
            12,
            60,
            300,
            'Satin alma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return (object) $blocked;
        }

        $result = $this->performBuy($id, $quantity, $uid);

        $response = new \stdClass();
        $response->error = 0;
        $response->message = "Satin alma tamamlandi.";
        $response->result = $result;
        return $response;
    }

    public function buyAndRedirect()
    {
        $id = (int)($_POST["id"] ?? 0);
        $quantity = (int)($_POST["quantity"] ?? 0);
        $uid = App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'market_buy',
            'uid:' . (int) $uid,
            12,
            60,
            300,
            'Satin alma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            $this->pushMarketFlash("error", (string) ($blocked["message"] ?? "Satin alma limiti asildi."));
            App::redirect($this->resolveMarketBackUrl());
        }

        try {
            $this->performBuy($id, $quantity, $uid);
            $this->pushMarketFlash("success", "Satin alma tamamlandi.");
            App::redirect($this->resolveMarketBackUrl());
        } catch (AppException $e) {
            $message = "Satin alma islemi tamamlanamadi.";

            if ($e->getCode() === AppException::NO_ENOUGH_MONEY) {
                $message = "Yeterli bakiye yok.";
            } elseif ($e->getCode() === AppException::NO_ENOUGH_RESOURCES) {
                $message = "Depo kapasitesi dolu.";
            } elseif ($e->getCode() === AppException::INVALID_DATA) {
                $message = "Teklif veya miktar gecersiz.";
            }

            $this->pushMarketFlash("error", $message);
            App::redirect($this->resolveMarketBackUrl());
        }
    }

    private function performBuy($id, $quantity, $uid)
    {
        return MarketOrderService::purchase($id, $quantity, $uid);
    }

    private function pushMarketFlash($status, $message)
    {
        App::session()->flash("market_notice", [
            "market_status" => $status,
            "market_message" => $message,
        ]);
    }

    private function pullMarketFlash()
    {
        $flash = App::session()->pullFlash("market_notice", []);
        if (!is_array($flash)) {
            return [];
        }

        return [
            "status" => isset($flash["market_status"]) ? (string) $flash["market_status"] : null,
            "message" => isset($flash["market_message"]) ? (string) $flash["market_message"] : null,
        ];
    }

    private function resolveMarketBackUrl()
    {
        $defaultPath = $this->app->getContainer()->get("router")->urlFor("marketplace");
        $back = trim((string) ($_SERVER["HTTP_REFERER"] ?? ""));
        if ($back === "") {
            return $defaultPath;
        }

        $parts = parse_url($back);
        if ($parts === false) {
            return $defaultPath;
        }

        $requestUri = $this->req->getUri();
        $requestHost = (string) $requestUri->getHost();
        $requestScheme = (string) $requestUri->getScheme();

        if (!empty($parts["host"]) && strcasecmp((string) $parts["host"], $requestHost) !== 0) {
            return $defaultPath;
        }

        if (!empty($parts["scheme"]) && strcasecmp((string) $parts["scheme"], $requestScheme) !== 0) {
            return $defaultPath;
        }

        $path = (string) ($parts["path"] ?? "");
        if ($path === "") {
            return $defaultPath;
        }

        $allowedPaths = [
            $this->app->getContainer()->get("router")->urlFor("marketplace"),
            "/marketplace",
        ];

        if (strpos($path, "/marketplace/offers/") === 0 || strpos($path, "/htdocs/marketplace/offers/") === 0) {
            $safePath = $path;
        } elseif (in_array($path, $allowedPaths, true) || in_array(rtrim($path, "/"), $allowedPaths, true)) {
            $safePath = $path;
        } else {
            return $defaultPath;
        }

        $query = isset($parts["query"]) && $parts["query"] !== "" ? "?" . $parts["query"] : "";
        return $safePath . $query;
    }

    public function sell()
    {
        return $this->sellSafe();
    }

    public function sellSafe()
    {
        $itemId = (int) ($_POST["item"] ?? 0);
        $quantity = (int) ($_POST["quantity"] ?? 0);
        $quality = (int) ($_POST["quality"] ?? 0);
        $price = round((float) ($_POST["price"] ?? 0), 2);
        $durationHours = (int) ($_POST["duration_hours"] ?? 24);
        $uid = (int) App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'market_sell',
            'uid:' . $uid,
            15,
            120,
            300,
            'Cok hizli satis girisi yapiyorsunuz. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        if ($price < 0.01 || $price > 1000000 || $itemId < 1 || $quantity < 1 || $quantity > 100000 || $quality < 0) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $itemInfo = Item::where(["id" => $itemId]);
        if (empty($itemInfo) || empty($itemInfo[0]) || empty($itemInfo[0]["canBeSold"])) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $countryId = (int) (App::user()->getLocation()["country"]["id"] ?? 1);
        return MarketOrderService::createOrder($uid, $itemId, $quantity, $quality, $price, $countryId, $durationHours);
    }

    public function cancelOrder()
    {
        $id = (int) ($_POST['id'] ?? 0);
        $uid = (int) App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'market_cancel',
            'uid:' . $uid,
            12,
            60,
            300,
            'Cok hizli iptal istegi gonderiyorsunuz. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        return MarketOrderService::cancel($id, $uid);
    }

    // ==========================================
    // YENİ MODÜL: BORSA TREND GRAFİĞİ İÇİN API
    // ==========================================
    public function getMarketTrends($item, $quality = 0)
    {
        $item = (int)$item;
        $quality = (int)$quality;

        if ($item < 1 || $quality < 0) {
            return ['error' => true, 'message' => 'Geçersiz veri'];
        }

        return $this->getRealMarketPriceSummary($item, $quality);
    }

    private function getRealMarketPriceSummary($item, $quality)
    {
        if (!MarketOrderService::schemaAvailable()) {
            return ['labels' => [], 'data' => [], 'basePrice' => null, 'averagePrice' => null, 'offerCount' => 0, 'item_id' => (int) $item, 'quality' => (int) $quality];
        }

        $countryId = (int) (App::user()->getLocation()['country']['id'] ?? 1);
        $offers = MarketOrderService::activeOffersQuery($countryId)
            ->where(['item' => (int) $item, 'quality' => (int) $quality])
            ->get(['price']);
        $prices = [];
        foreach ($offers as $offer) {
            $prices[] = (float) $offer->price;
        }

        return [
            'labels' => [],
            'data' => [],
            'basePrice' => empty($prices) ? null : round(min($prices), 2),
            'averagePrice' => empty($prices) ? null : round(array_sum($prices) / count($prices), 2),
            'offerCount' => count($prices),
            'item_id' => (int) $item,
            'quality' => (int) $quality,
        ];
    }
}
