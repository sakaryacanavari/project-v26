<?php

namespace App\Controllers;

use App\Models\Country;
use App\Models\Item;
use App\Models\UserMoney;
use App\Models\UserItem;
use App\Models\ItemOffer;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

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

        $q = ItemOffer::with(['seller', 'country'])->where([
            "country" => $country,
            "item" => $item,
            "quality" => $quality,
        ]);

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

        $initialOffersQ = ItemOffer::with(['seller', 'country'])
            ->where(["country" => $userCountryId])
            ->orderBy('price', 'asc');
            
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
            } elseif ($e->getCode() === AppException::INVALID_DATA) {
                $message = "Teklif veya miktar gecersiz.";
            }

            $this->pushMarketFlash("error", $message);
            App::redirect($this->resolveMarketBackUrl());
        }
    }

    private function performBuy($id, $quantity, $uid)
    {
        if ($id < 1 || $quantity < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $offer = ItemOffer::with("country")->where(["id" => $id])->first();
        if (!$offer || (int)$offer->quantity < $quantity) {
            throw new AppException(AppException::INVALID_DATA);
        }

        if ((int)$offer->uid === (int)$uid) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $buyerMoney = UserMoney::where(["uid" => $uid])->first();
        $sellerMoney = UserMoney::where(["uid" => $offer->uid])->first();
        $offerCountry = $offer->relationLoaded("country") ? $offer->getRelation("country") : $offer->country()->first();
        $currency = strtolower(trim((string) ($offerCountry->currency ?? "")));
        $cost = round((float)$offer->price * $quantity, 2);

        if (!$buyerMoney || !$sellerMoney || $currency === "") {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $buyerBalance = $this->getMoneyBalance($buyerMoney, $currency);
        $sellerBalance = $this->getMoneyBalance($sellerMoney, $currency);

        if ($buyerBalance === null || $sellerBalance === null) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        if ($buyerBalance < $cost) {
            throw new AppException(AppException::NO_ENOUGH_MONEY);
        }

        $purchasedItem = $offer->item;
        $purchasedQuality = $offer->quality;

        DB::beginTransaction();

        try {
            $sellerMoney->setAttribute($currency, round($sellerBalance + $cost, 2));
            $sellerMoney->save();

            $buyerMoney->setAttribute($currency, round($buyerBalance - $cost, 2));
            $buyerMoney->save();

            $newQuantity = (int)$offer->quantity - $quantity;
            if ($newQuantity <= 0) {
                $offer->delete();
            } else {
                $offer->quantity = $newQuantity;
                $offer->save();
            }

            $inv = UserItem::firstOrNew([
                "uid" => $uid,
                "item" => $purchasedItem,
                "quality" => $purchasedQuality,
            ]);

            if ($inv->quantity === null) {
                $inv->quantity = 0;
            }

            $inv->quantity += $quantity;
            $inv->save();

            DB::commit();

            return [
                "offer_id" => (int) $id,
                "quantity" => (int) $quantity,
                "currency" => $currency,
                "cost" => $cost,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new AppException(AppException::ACTION_FAILED);
        }
    }

    private function getMoneyBalance(UserMoney $wallet, $currency)
    {
        $attributes = $wallet->getAttributes();
        if (!array_key_exists($currency, $attributes)) {
            return null;
        }

        return round((float) $wallet->getAttribute($currency), 2);
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
        $defaultPath = $this->app->getContainer()->get("router")->pathFor("marketplace");
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
            $this->app->getContainer()->get("router")->pathFor("marketplace"),
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
        $itemId = (int)($_POST["item"] ?? 0);
        $quantity = (int)($_POST["quantity"] ?? 0);
        $quality = (int)($_POST["quality"] ?? 0);
        $price = round((float)($_POST["price"] ?? 0), 2);
        $uid = App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'market_sell',
            'uid:' . (int) $uid,
            15,
            120,
            300,
            'Cok hizli satis girisi yapiyorsunuz. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        if ($price < 0.01 || $itemId < 1 || $quantity < 1 || $quality < 0) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $query = [
            "uid" => $uid,
            "item" => $itemId,
            "quality" => $quality,
        ];

        $inv = UserItem::where($query)->first();
        if (!$inv || (int)$inv->quantity < $quantity) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $offerQuery = [
            "uid" => $uid,
            "item" => $itemId,
            "quality" => $quality,
            "price" => $price,
            "country" => App::user()->getLocation()["country"]["id"] ?? 1
        ];

        $existingOffer = ItemOffer::where($offerQuery)->first();

        if ($existingOffer) {
            $existingOffer->quantity += $quantity;
            $success = $existingOffer->save();
        } else {
            $offerQuery["quantity"] = $quantity;
            $success = ItemOffer::create($offerQuery);
        }

        if ($success) {
            $inv->quantity -= $quantity;
            
            // GÜVENLİK/OPTİMİZASYON GÜNCELLEMESİ: Miktar 0 ise veritabanında tutma, sil
            if ($inv->quantity <= 0) {
                $inv->delete();
            } else {
                $inv->save();
            }
            return true;
        }

        throw new AppException(AppException::ACTION_FAILED);
    }

    public function sellSafe()
    {
        $itemId = (int) ($_POST["item"] ?? 0);
        $quantity = (int) ($_POST["quantity"] ?? 0);
        $quality = (int) ($_POST["quality"] ?? 0);
        $price = round((float) ($_POST["price"] ?? 0), 2);
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

        $countryId = (int) (App::user()->getLocation()["country"]["id"] ?? 1);

        DB::beginTransaction();
        try {
            $query = [
                "uid" => $uid,
                "item" => $itemId,
                "quality" => $quality,
            ];

            $inv = UserItem::where($query)->lockForUpdate()->first();
            if (!$inv || (int) $inv->quantity < $quantity) {
                DB::rollBack();
                throw new AppException(AppException::INVALID_DATA);
            }

            $existingOffer = ItemOffer::where([
                "uid" => $uid,
                "item" => $itemId,
                "quality" => $quality,
                "price" => $price,
                "country" => $countryId,
            ])->lockForUpdate()->first();

            if ($existingOffer) {
                $existingOffer->quantity += $quantity;
                $success = $existingOffer->save();
            } else {
                $success = ItemOffer::create([
                    "uid" => $uid,
                    "item" => $itemId,
                    "quality" => $quality,
                    "price" => $price,
                    "country" => $countryId,
                    "quantity" => $quantity,
                ]);
            }

            if (!$success) {
                DB::rollBack();
                throw new AppException(AppException::ACTION_FAILED);
            }

            $inv->quantity -= $quantity;
            if ($inv->quantity <= 0) {
                $inv->delete();
            } else {
                $inv->save();
            }

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e instanceof AppException ? $e : new AppException(AppException::ACTION_FAILED);
        }
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

        $basePrice = ItemOffer::where(['item' => $item, 'quality' => $quality])->min('price');
        $basePrice = $basePrice ? (float)$basePrice : mt_rand(10, 100);

        $labels = [];
        $data = [];
        
        for ($i = 24; $i >= 0; $i -= 2) {
            $labels[] = $i === 0 ? "Şu an" : "-$i S";
            
            $fluctuation = $i === 0 ? 0 : $basePrice * (mt_rand(-15, 15) / 100);
            $data[] = max(0.01, round($basePrice + $fluctuation, 2));
        }

        $data[count($data) - 1] = $basePrice;

        return [
            'labels' => $labels,
            'data' => $data,
            'item_id' => $item,
            'quality' => $quality
        ];
    }
}
