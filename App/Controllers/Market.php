<?php

namespace App\Controllers;

use \App\System\App;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Pazar yeri controller'ı.
 * Ürün alım/satım ve market listesi işlemleri.
 */
class Market extends Controller
{
    /**
     * Pazar yeri ana sayfasını gösterir.
     */
    public function showMarketplaceHome()
    {
        $uid = $this->uid();

        // Ürün kategorileri
        $items = DB::table('items')->orderBy('id')->get();

        return $this->render('market/home.html.twig', [
            'items' => $items,
        ]);
    }

    /**
     * Belirli bir ürün için teklifleri listeler.
     */
    public function showItemOffers($itemId, $quality = 0)
    {
        $uid     = $this->uid();
        $itemId  = (int) $itemId;
        $quality = (int) $quality;

        $user = DB::table('users')
            ->join('regions', 'users.region', '=', 'regions.id')
            ->select(DB::raw('regions.country AS country_id'))
            ->where('users.id', $uid)
            ->first();

        $countryId = (int) ($user->country_id ?? 0);
        $item      = DB::table('items')->where('id', $itemId)->first();

        if (!$item) {
            return $this->error('Ürün bulunamadı.');
        }

        $offers = DB::table('item_offers')
            ->join('users', 'item_offers.uid', '=', 'users.id')
            ->where('item_offers.item', $itemId)
            ->where('item_offers.quantity', '>', 0)
            ->when($quality > 0, function ($q) use ($quality) {
                return $q->where('item_offers.quality', $quality);
            })
            ->where('item_offers.country', $countryId)
            ->orderBy('item_offers.price', 'asc')
            ->select('item_offers.*', 'users.nick as seller_nick')
            ->get();

        return $this->render('market/itemList.html.twig', [
            'item'    => $item,
            'offers'  => $offers,
            'quality' => $quality,
        ]);
    }

    /**
     * Pazar trendleri (JSON API).
     */
    public function getMarketTrends($itemId, $quality = 0)
    {
        $itemId  = (int) $itemId;
        $quality = (int) $quality;

        $minPrice = DB::table('item_offers')
            ->where('item', $itemId)
            ->where('quality', $quality)
            ->where('quantity', '>', 0)
            ->min('price');

        return $this->success('Pazar trendi alındı.', [
            'min_price' => $minPrice ?? 0,
        ]);
    }

    /**
     * Ürün satın alır.
     */
    public function buy()
    {
        $uid      = $this->uid();
        $offerId  = (int) $this->input('offer_id', 0);
        $quantity = (int) $this->input('quantity', 1);

        if (!$offerId || $quantity < 1) {
            return $this->error('Geçersiz teklif veya miktar.');
        }

        $offer = DB::table('item_offers')
            ->where('id', $offerId)
            ->where('quantity', '>=', $quantity)
            ->first();

        if (!$offer) {
            return $this->error('Teklif bulunamadı veya yeterli stok yok.');
        }

        // Kullanıcının parasını kontrol et
        $totalCost = $offer->price * $quantity;
        $country   = DB::table('countries')
            ->join('regions', 'countries.id', '=', 'regions.country')
            ->join('users', 'regions.id', '=', 'users.region')
            ->where('users.id', $uid)
            ->select('countries.currency')
            ->first();

        $currency = $country ? $country->currency : 'gold';
        $money    = DB::table('user_money')->where('uid', $uid)->first();
        $balance  = $money ? (float) ($money->$currency ?? 0) : 0;

        if ($balance < $totalCost) {
            return $this->error(sprintf('Yetersiz bakiye. Gerekli: %.2f %s', $totalCost, strtoupper($currency)));
        }

        $now = date('Y-m-d H:i:s');

        // Parayı düş
        DB::table('user_money')->where('uid', $uid)->decrement($currency, $totalCost);

        // Satıcıya parayı ekle
        DB::table('user_money')->where('uid', $offer->uid)->increment($currency, $totalCost);

        // Stoku güncelle
        DB::table('item_offers')->where('id', $offerId)->decrement('quantity', $quantity);

        // Alıcının deposuna ekle
        $existing = DB::table('user_items')
            ->where('uid', $uid)
            ->where('item', $offer->item)
            ->where('quality', $offer->quality)
            ->first();

        if ($existing) {
            DB::table('user_items')
                ->where('uid', $uid)
                ->where('item', $offer->item)
                ->where('quality', $offer->quality)
                ->increment('quantity', $quantity);
        } else {
            DB::table('user_items')->insert([
                'uid'        => $uid,
                'item'       => $offer->item,
                'quality'    => $offer->quality,
                'quantity'   => $quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->success(sprintf('%d adet ürün satın alındı.', $quantity));
    }

    /**
     * Ürün satar.
     */
    public function sell()
    {
        $uid      = $this->uid();
        $itemId   = (int) $this->input('item', 0);
        $quality  = (int) $this->input('quality', 0);
        $price    = (float) $this->input('price', 0);
        $quantity = (int) $this->input('quantity', 1);

        if (!$itemId || $price <= 0 || $quantity < 1) {
            return $this->error('Geçersiz satış bilgileri.');
        }

        // Depoda yeterli stok var mı?
        $stock = DB::table('user_items')
            ->where('uid', $uid)
            ->where('item', $itemId)
            ->where('quality', $quality)
            ->first();

        if (!$stock || $stock->quantity < $quantity) {
            return $this->error('Yeterli stok bulunamadı.');
        }

        // Kullanıcının ülkesini bul
        $userCountry = DB::table('users')
            ->join('regions', 'users.region', '=', 'regions.id')
            ->selectRaw('regions.country AS country_id')
            ->where('users.id', $uid)
            ->first();

        $countryId = $userCountry ? $userCountry->country_id : 0;

        $now = date('Y-m-d H:i:s');

        // Stoktan düş
        DB::table('user_items')
            ->where('uid', $uid)
            ->where('item', $itemId)
            ->where('quality', $quality)
            ->decrement('quantity', $quantity);

        // Market'e ekle
        DB::table('item_offers')->insert([
            'item'       => $itemId,
            'quality'    => $quality,
            'uid'        => $uid,
            'price'      => $price,
            'quantity'   => $quantity,
            'country'    => $countryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->success(sprintf('%d adet ürün %s fiyatla markete konuldu.', $quantity, number_format($price, 2)));
    }
}
