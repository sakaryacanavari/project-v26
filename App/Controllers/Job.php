<?php

namespace App\Controllers;

use App\Models\CompanyType;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use App\Models\User as UserModel;
use Illuminate\Database\Capsule\Manager as DB;

class Job extends Controller
{
    public function work()
    {
        $uid = (int) App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'job_work',
            'uid:' . $uid,
            4,
            60,
            180,
            'Calisma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return [
                "error" => true,
                "message" => (string) ($blocked["message"] ?? "Calisma limiti asildi.")
            ];
        }

        $userLastDailyWorkAt = $this->getUserLastDailyWorkAt($uid);

        $job = $this->getLatestAssignedWorkOffer($uid);

        if (empty($job)) {
            return [
                "error" => true,
                "message" => "Aktif bir isiniz bulunmuyor."
            ];
        }

        if (!empty($userLastDailyWorkAt) && date('Y-m-d', strtotime($userLastDailyWorkAt)) === date('Y-m-d')) {
            return [
                "error" => true,
                "message" => "Bugunku calisma hakkinizi zaten kullandiniz."
            ];
        }

        $salary = (float) ($job->salary ?? 0);
        $currency = strtolower(trim((string) ($job->currency ?? '')));
        $companyId = (int) ($job->company ?? 0);

        if ($salary <= 0) {
            return [
                "error" => true,
                "message" => "Bu is icin gecerli maas tanimli degil."
            ];
        }

        if ($currency === '') {
            return [
                "error" => true,
                "message" => "Bu is icin gecerli para birimi tanimli degil."
            ];
        }

        if ($companyId < 1) {
            return [
                "error" => true,
                "message" => "Bu is ilanina bagli gecerli bir sirket bulunamadi."
            ];
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasColumn('user_money', $currency)) {
            return [
                "error" => true,
                "message" => "Bu maas para birimi sistemde tanimli degil: " . strtoupper($currency)
            ];
        }

        $company = DB::table('companies')
            ->where('id', $companyId)
            ->first();

        if (empty($company)) {
            return [
                "error" => true,
                "message" => "Ise bagli sirket kaydi bulunamadi."
            ];
        }

        $companyTypeKey = $company->type ?? null;
        $companyQuality = (int) ($company->quality ?? 0);
        $companyOwnerUid = (int) ($company->uid ?? 0);

        if (empty($companyTypeKey) || $companyQuality < 1) {
            return [
                "error" => true,
                "message" => "Sirket uretim verileri gecersiz."
            ];
        }

        if (!isset(CompanyType::$types[$companyTypeKey])) {
            return [
                "error" => true,
                "message" => "Sirket tipi sistemde bulunamadi."
            ];
        }

        $companyType = CompanyType::$types[$companyTypeKey];

        if (
            !isset($companyType['qualities']) ||
            !isset($companyType['qualities'][$companyQuality])
        ) {
            return [
                "error" => true,
                "message" => "Sirket kalite verisi sistemde bulunamadi."
            ];
        }

        $qualityData = $companyType['qualities'][$companyQuality];
        $productId = isset($companyType['product']) ? $companyType['product'] : null;
        $produceAmount = (int) ($qualityData['product_amount'] ?? 0);
        $consumeProduct = (int) ($qualityData['consume_product'] ?? 0);
        $consumeAmount = (int) ($qualityData['consume_amount'] ?? 0);

        if (empty($productId) || $produceAmount <= 0) {
            return [
                "error" => true,
                "message" => "Bu sirket icin gecerli uretim ciktisi tanimli degil."
            ];
        }

        if ($companyOwnerUid < 1) {
            return [
                "error" => true,
                "message" => "Sirket sahibine ait gecerli kullanici bulunamadi."
            ];
        }

        DB::beginTransaction();

        try {
            $workerMoney = $this->lockUserMoneyRow($uid);
            $ownerMoney = $companyOwnerUid === $uid
                ? $workerMoney
                : $this->lockUserMoneyRow($companyOwnerUid);

            $ownerBalance = (float) ($ownerMoney->{$currency} ?? 0);

            if ($ownerBalance < $salary) {
                DB::rollBack();

                return [
                    "error" => true,
                    "message" => "Sirket sahibinin maasi odeyecek kadar " . strtoupper($currency) . " bakiyesi yok."
                ];
            }

            if ($consumeProduct > 0 && $consumeAmount > 0) {
                $ownerInputItem = DB::table('user_items')
                    ->where('uid', $companyOwnerUid)
                    ->where('item', $consumeProduct)
                    ->where('quality', $companyQuality)
                    ->lockForUpdate()
                    ->first();

                $ownerInputQty = (int) ($ownerInputItem->quantity ?? 0);

                if ($ownerInputQty < $consumeAmount) {
                    DB::rollBack();

                    return [
                        "error" => true,
                        "message" => "Sirket uretim icin yeterli girdi stoguna sahip degil."
                    ];
                }

                $newInputQty = $ownerInputQty - $consumeAmount;

                if ($newInputQty > 0) {
                    DB::table('user_items')
                        ->where('id', (int) $ownerInputItem->id)
                        ->update([
                            'quantity' => $newInputQty
                        ]);
                } else {
                    DB::table('user_items')
                        ->where('id', (int) $ownerInputItem->id)
                        ->delete();
                }
            }

            $ownerOutputItem = DB::table('user_items')
                ->where('uid', $companyOwnerUid)
                ->where('item', $productId)
                ->where('quality', $companyQuality)
                ->lockForUpdate()
                ->first();

            if (!empty($ownerOutputItem)) {
                DB::table('user_items')
                    ->where('id', (int) $ownerOutputItem->id)
                    ->update([
                        'quantity' => (int) $ownerOutputItem->quantity + $produceAmount
                    ]);
            } else {
                DB::table('user_items')->insert([
                    'uid' => $companyOwnerUid,
                    'item' => $productId,
                    'quality' => $companyQuality,
                    'quantity' => $produceAmount
                ]);
            }

            if ($companyOwnerUid !== $uid) {
                $workerBalance = (float) ($workerMoney->{$currency} ?? 0);

                DB::table('user_money')
                    ->where('uid', $companyOwnerUid)
                    ->update([
                        $currency => $ownerBalance - $salary
                    ]);

                DB::table('user_money')
                    ->where('uid', $uid)
                    ->update([
                        $currency => $workerBalance + $salary
                    ]);
            }

            DB::table('work_offers')
                ->where('id', (int) $job->id)
                ->update([
                    'last_work' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $this->setUserLastDailyWorkAt($uid, date('Y-m-d H:i:s'));
            $this->grantEconomicWorkProgress($uid, 1);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                "error" => true,
                "message" => "Mesai islemi sirasinda hata olustu: " . $e->getMessage()
            ];
        }

        return [
            "error" => false,
            "message" => "Gunluk calisma tamamlandi, maas sirket bakiyesinden karsilandi ve uretim otomatik islendi."
        ];
    }

    public function resign()
    {
        $uid = (int) App::user()->getUid();

        $blocked = ActionRateLimiter::throttle(
            'job_resign',
            'uid:' . $uid,
            6,
            120,
            300,
            'Istifa denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return [
                "error" => true,
                "message" => (string) ($blocked["message"] ?? "Istifa limiti asildi.")
            ];
        }

        $job = $this->getLatestAssignedWorkOffer($uid);

        $isApiRequest =
            (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/api/') !== false) ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if (empty($job)) {
            if ($isApiRequest) {
                return [
                    "error" => true,
                    "message" => "Aktif bir isiniz bulunmuyor."
                ];
            }

            App::redirect($this->app->getContainer()->get('router')->urlFor('workOffers'));
            exit;
        }

        try {
            DB::table('work_offers')
                ->where('worker', $uid)
                ->update([
                    'worker' => null,
                    'last_work' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Exception $e) {
            if ($isApiRequest) {
                return [
                    "error" => true,
                    "message" => "Istifa islemi sirasinda hata olustu."
                ];
            }

            throw new AppException(AppException::ACTION_FAILED);
        }

        if ($isApiRequest) {
            return [
                "error" => false,
                "message" => "Is sozlesmeniz basariyla sonlandirildi."
            ];
        }

        App::redirect($this->app->getContainer()->get('router')->urlFor('workOffers'));
        exit;
    }

    private function grantEconomicWorkProgress($uid, $xpGain = 1)
    {
        $user = UserModel::where([
            "id" => $uid
        ])->first();

        if (empty($user)) {
            return false;
        }

        $user->economic_skill = max(1, (int) ($user->economic_skill ?? 1));
        $user->economic_xp = max(0, (int) ($user->economic_xp ?? 0));
        $user->economic_xp += max(0, (int) $xpGain);

        while ($user->economic_xp >= $this->getEconomicSkillRequiredXp($user->economic_skill)) {
            $requiredXp = $this->getEconomicSkillRequiredXp($user->economic_skill);
            $user->economic_xp -= $requiredXp;
            $user->economic_skill++;
        }

        return $user->save();
    }

    private function getEconomicSkillRequiredXp($currentSkill)
    {
        $currentSkill = max(1, (int) $currentSkill);
        return $currentSkill * 3;
    }

    private function getLatestAssignedWorkOffer($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return null;
        }

        $assignedOfferIds = DB::table('work_offers')
            ->where('worker', $uid)
            ->orderBy('id', 'desc')
            ->pluck('id')
            ->toArray();

        if (empty($assignedOfferIds)) {
            return null;
        }

        $latestOfferId = (int) $assignedOfferIds[0];

        if (count($assignedOfferIds) > 1) {
            DB::table('work_offers')
                ->where('worker', $uid)
                ->where('id', '<>', $latestOfferId)
                ->update([
                    'worker' => null,
                    'last_work' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }

        return DB::table('work_offers')
            ->where('id', $latestOfferId)
            ->first();
    }

    private function lockUserMoneyRow($uid)
    {
        $uid = (int) $uid;

        $moneyRow = DB::table('user_money')
            ->where('uid', $uid)
            ->lockForUpdate()
            ->first();

        if (!empty($moneyRow)) {
            return $moneyRow;
        }

        DB::table('user_money')->insert([
            'uid' => $uid
        ]);

        return DB::table('user_money')
            ->where('uid', $uid)
            ->lockForUpdate()
            ->first();
    }

    private function getUserLastDailyWorkAt($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return null;
        }

        $this->ensureUserDailyWorkColumnExists();

        $dbValue = DB::table('users')
            ->where('id', $uid)
            ->value('last_daily_work_at');

        $sessionValue = App::session()->get('last_daily_work_at');

        if (!empty($dbValue)) {
            App::session()->set('last_daily_work_at', $dbValue);
            return $dbValue;
        }

        return !empty($sessionValue) ? $sessionValue : null;
    }

    private function setUserLastDailyWorkAt($uid, $datetime)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return false;
        }

        $this->ensureUserDailyWorkColumnExists();

        $updated = DB::table('users')
            ->where('id', $uid)
            ->update([
                'last_daily_work_at' => $datetime,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        App::session()->set('last_daily_work_at', $datetime);

        return $updated;
    }

    private function ensureUserDailyWorkColumnExists()
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $checked = true;

        $schema = DB::getSchemaBuilder();
        if ($schema->hasColumn('users', 'last_daily_work_at')) {
            return;
        }

        DB::statement("ALTER TABLE users ADD COLUMN last_daily_work_at DATETIME NULL DEFAULT NULL");
    }
}
