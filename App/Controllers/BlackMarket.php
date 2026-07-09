<?php

namespace App\Controllers;

use App\Models\UserMoney;
use App\System\App;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

class BlackMarket extends Controller
{
    public function index()
    {
        $marketItems = DB::table('black_market_items')
            ->where('quantity', '>', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->render('BlackMarket/index.html.twig', [
            'marketItems' => $marketItems,
        ]);
    }

    public function createAd()
    {
        return $this->render('BlackMarket/create.html.twig');
    }

    public function storeAd()
    {
        $request = $this->app->getContainer()->get('request');
        $data = $request->getParsedBody();
        $userId = App::session()->getUid();

        $category = trim((string)($data['category'] ?? 'diger'));
        $itemName = trim((string)($data['item_name'] ?? ''));
        $price = (float)($data['price'] ?? 0);
        $quantity = (int)($data['quantity'] ?? 0);

        if ($itemName === '' || $price <= 0 || $quantity <= 0) {
            return ['error' => true, 'message' => 'Geçersiz ilan bilgileri.'];
        }

        DB::table('black_market_items')->insert([
            'seller_id' => $userId,
            'category' => $category !== '' ? $category : 'diger',
            'item_name' => $itemName,
            'price' => $price,
            'quantity' => $quantity,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['error' => false, 'message' => 'Gizli ilan başarıyla Karaborsa ağına eklendi.'];
    }

    public function buyItem($itemId)
    {
        $buyerId = App::session()->getUid();

        try {
            DB::beginTransaction();

            $item = DB::table('black_market_items')
                ->where('id', (int)$itemId)
                ->lockForUpdate()
                ->first();

            if (!$item || (int)$item->quantity <= 0) {
                throw new \Exception('Bu ilan artık geçerli değil veya stok tükendi.');
            }

            if ((int)$item->seller_id === (int)$buyerId) {
                throw new \Exception('Kendi verdiğiniz ilanı satın alamazsınız.');
            }

            $buyerMoney = UserMoney::where('uid', $buyerId)->lockForUpdate()->first();
            $sellerMoney = UserMoney::where('uid', (int)$item->seller_id)->lockForUpdate()->first();

            if (!$buyerMoney || !$sellerMoney) {
                throw new \Exception('Taraflardan birinin para kaydı bulunamadı.');
            }

            if ((float)($buyerMoney->gold ?? 0) < (float)$item->price) {
                throw new \Exception('Bu eşyayı almak için yeterli altınınız yok.');
            }

            $buyerMoney->gold = round((float)$buyerMoney->gold - (float)$item->price, 2);
            $sellerMoney->gold = round((float)$sellerMoney->gold + (float)$item->price, 2);

            $buyerMoney->save();
            $sellerMoney->save();

            if ((int)$item->quantity === 1) {
                DB::table('black_market_items')->where('id', (int)$itemId)->delete();
            } else {
                DB::table('black_market_items')
                    ->where('id', (int)$itemId)
                    ->update([
                        'quantity' => (int)$item->quantity - 1,
                    ]);
            }

            DB::commit();

            return ['error' => false, 'message' => 'Satın alma başarılı. Transfer tamamlandı.'];
        } catch (\Exception $e) {
            DB::rollBack();
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }
}
