<?php

namespace App\Controllers;

use \App\System\App;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * İş / çalışma işlemlerini yöneten controller.
 * Aktif iş sözleşmesiyle günlük mesai ve istifa işlemleri.
 */
class Job extends Controller
{
    /** Çalışma başına kazanılan XP miktarı */
    const XP_PER_SHIFT = 10;

    /** Skill başına gereken XP eşiği: skill_level * XP_THRESHOLD_MULTIPLIER */
    const XP_THRESHOLD_MULTIPLIER = 200;
    /**
     * Günlük mesaiyi gerçekleştirir (POST /api/job/work).
     *
     * İşçi çalıştığında:
     * - Aktif iş work_offers.worker = uid ile bulunur
     * - last_work üzerinden günlük 1 kez çalışma backend'de kontrol edilir
     * - Maaş doğru currency kolonuna user_money içine yazılır
     * - Şirkete bağlı CompanyType'a göre ürün üretimi tetiklenir
     * - economic_xp / economic_skill ilerlemesi hesaplanır
     */
    public function work()
    {
        $uid = $this->uid();

        // Aktif iş sözleşmesini work_offers tablosundan bul
        $activeJob = DB::table('work_offers')
            ->where('worker', $uid)
            ->first();

        if (!$activeJob) {
            return $this->error('Aktif bir iş sözleşmeniz bulunmuyor.');
        }

        // Günlük 1 kez çalışma limitini kontrol et
        if ($activeJob->last_work) {
            $lastWorkDate = date('Y-m-d', strtotime($activeJob->last_work));
            $today = date('Y-m-d');

            if ($lastWorkDate === $today) {
                return $this->error('Bugünkü vardiyayı zaten tamamladınız. Yarın tekrar çalışabilirsiniz.');
            }
        }

        // Şirket bilgisini al
        $company = DB::table('companies')->where('id', $activeJob->company)->first();
        if (!$company) {
            return $this->error('İş ilanına bağlı şirket bulunamadı.');
        }

        // Maaş ve para birimi
        $salary   = (float) $activeJob->salary;
        $currency = $activeJob->currency ?? null;

        // Para birimini belirle - önce work_offers.currency, yoksa şirketin ülkesinin para birimi
        if (!$currency) {
            $country = DB::table('countries')->where('id', $activeJob->country)->first();
            $currency = $country ? $country->currency : null;
        }

        $now = date('Y-m-d H:i:s');

        // Maaşı kullanıcı hesabına ekle
        if ($currency && $salary > 0) {
            $moneyRow = DB::table('user_money')->where('uid', $uid)->first();

            if ($moneyRow) {
                // Mevcut para birimi kolonunu güncelle
                DB::table('user_money')
                    ->where('uid', $uid)
                    ->increment($currency, $salary);
            } else {
                // İlk kez kayıt oluştur
                DB::table('user_money')->insert([
                    'uid'    => $uid,
                    $currency => $salary,
                ]);
            }
        }

        // Ürün üretimini tetikle
        $this->triggerProduction($company, $uid);

        // last_work tarihini güncelle
        DB::table('work_offers')
            ->where('id', $activeJob->id)
            ->update(['last_work' => $now, 'updated_at' => $now]);

        // Ekonomik XP ve skill ilerlemesini güncelle
        $this->advanceEconomicSkill($uid);

        return $this->success(
            sprintf('Vardiya tamamlandı! %.2f %s kazandınız.', $salary, strtoupper($currency ?? '-'))
        );
    }

    /**
     * İş sözleşmesini fesheder.
     * Hem eski redirect akışını hem de yeni /api/job/resign JSON akışını destekler.
     * worker ve last_work alanları temizlenir.
     */
    public function resign()
    {
        $uid = $this->uid();

        // Aktif iş sözleşmesini bul
        $activeJob = DB::table('work_offers')
            ->where('worker', $uid)
            ->first();

        if (!$activeJob) {
            // Eski akış: redirect
            if (!$this->isAjax) {
                return $this->redirect('/work-offers');
            }
            return $this->error('Aktif bir iş sözleşmeniz bulunmuyor.');
        }

        $now = date('Y-m-d H:i:s');

        // worker ve last_work alanlarını temizle
        DB::table('work_offers')
            ->where('id', $activeJob->id)
            ->update([
                'worker'     => null,
                'last_work'  => null,
                'updated_at' => $now,
            ]);

        // Eski akış: redirect
        if (!$this->isAjax) {
            return $this->redirect('/work-offers');
        }

        return $this->success('Sözleşme başarıyla feshedildi.');
    }

    // -------------------------------------------------------
    // Yardımcı metodlar (private)
    // -------------------------------------------------------

    /**
     * Şirket tipine göre otomatik ürün üretimi tetikler.
     * Girdi malzeme gerekiyorsa stoktan düşer, çıktıyı şirket sahibine ekler.
     */
    private function triggerProduction($company, int $workerUid): void
    {
        // Şirket tipi konfigürasyonu
        $companyType = DB::table('company_types')->where('id', $company->type)->first();
        if (!$companyType) {
            return;
        }

        $outputItem    = $companyType->output_item ?? null;
        $outputQty     = $companyType->output_amount ?? 1;
        $consumeItem   = $companyType->consume_item ?? null;
        $consumeAmount = $companyType->consume_amount ?? 0;

        // Girdi malzeme gerekiyorsa stoktan düş
        if ($consumeItem && $consumeAmount > 0) {
            $ownerStock = DB::table('user_items')
                ->where('uid', $company->uid)
                ->where('item', $consumeItem)
                ->where('quality', $company->quality)
                ->first();

            // Yeterli stok yoksa üretim gerçekleşmez
            if (!$ownerStock || $ownerStock->quantity < $consumeAmount) {
                return;
            }

            // Girdi stoğunu düş
            DB::table('user_items')
                ->where('uid', $company->uid)
                ->where('item', $consumeItem)
                ->where('quality', $company->quality)
                ->decrement('quantity', $consumeAmount);
        }

        // Çıktı ürününü şirket sahibinin deposuna ekle
        if ($outputItem) {
            $existingOutput = DB::table('user_items')
                ->where('uid', $company->uid)
                ->where('item', $outputItem)
                ->where('quality', $company->quality)
                ->first();

            if ($existingOutput) {
                DB::table('user_items')
                    ->where('uid', $company->uid)
                    ->where('item', $outputItem)
                    ->where('quality', $company->quality)
                    ->increment('quantity', $outputQty);
            } else {
                DB::table('user_items')->insert([
                    'uid'        => $company->uid,
                    'item'       => $outputItem,
                    'quality'    => $company->quality,
                    'quantity'   => $outputQty,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * Çalışma sonrası ekonomik XP ve skill ilerlemesini günceller.
     * Her çalışmada XP kazanılır; belirli XP eşiğine ulaşınca skill artar.
     */
    private function advanceEconomicSkill(int $uid): void
    {
        $user = DB::table('users')
            ->select('economic_skill', 'economic_xp')
            ->where('id', $uid)
            ->first();

        if (!$user) {
            return;
        }

        $currentSkill = (int) $user->economic_skill;
        $currentXp    = (int) $user->economic_xp;

        // Her çalışmada XP_PER_SHIFT XP kazan
        $xpGain   = self::XP_PER_SHIFT;
        $newXp    = $currentXp + $xpGain;
        $newSkill = $currentSkill;

        // XP eşiği: skill * XP_THRESHOLD_MULTIPLIER XP gerekir (örn: skill 1 için 200, skill 2 için 400)
        $xpThreshold = $currentSkill * self::XP_THRESHOLD_MULTIPLIER;

        if ($newXp >= $xpThreshold) {
            $newSkill = $currentSkill + 1;
            $newXp    = 0; // XP sıfırla
        }

        DB::table('users')
            ->where('id', $uid)
            ->update([
                'economic_skill' => $newSkill,
                'economic_xp'    => $newXp,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
    }
}
