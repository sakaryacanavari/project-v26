<?php

namespace App\Controllers;

use \App\System\App;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Şirket yönetimi controller'ı.
 * Şirket oluşturma, iş ilanı yönetimi ve üretim operasyonları.
 * NOT: Toplu yönetici üretimi kaldırıldı; workAsManager pasif/hata döndürür.
 */
class Company extends Controller
{
    // -------------------------------------------------------
    // Sayfa görünümleri
    // -------------------------------------------------------

    /**
     * Kullanıcının şirketlerini listeler.
     * Şirket sınıfı/seviye, lojistik tüketim, nihai çıktı ve iş ilanı sütunlarını gösterir.
     */
    public function showMyCompanies()
    {
        $uid = $this->uid();

        $companies = DB::table('companies')
            ->join('company_types', 'companies.type', '=', 'company_types.id')
            ->where('companies.uid', $uid)
            ->select(
                'companies.*',
                'company_types.name as type_name',
                'company_types.output_item',
                'company_types.output_amount',
                'company_types.consume_item',
                'company_types.consume_amount'
            )
            ->get();

        // Her şirket için aktif iş ilanını çek
        $companiesData = [];
        foreach ($companies as $company) {
            $openOffer = DB::table('work_offers')
                ->where('company', $company->id)
                ->whereNull('worker')
                ->select('id', 'salary', 'currency', 'required_skill', 'title')
                ->first();

            $companiesData[] = [
                'id'             => $company->id,
                'type'           => $company->type,
                'type_name'      => $company->type_name,
                'quality'        => $company->quality,
                'output_item'    => $company->output_item,
                'output_amount'  => $company->output_amount,
                'consume_item'   => $company->consume_item,
                'consume_amount' => $company->consume_amount,
                'last_work'      => $company->last_work,
                'created_at'     => $company->created_at,
                // Aktif iş ilanı - salary/required_skill alanlarını esas al
                'openOffer'      => $openOffer ? [
                    'id'             => $openOffer->id,
                    'salary'         => (float) $openOffer->salary,   // offer.salary kullan
                    'currency'       => $openOffer->currency ?? '-',
                    'required_skill' => (int) ($openOffer->required_skill ?? 0), // offer.required_skill kullan
                    'title'          => $openOffer->title ?? '',
                ] : null,
            ];
        }

        return $this->render('user/companies.html.twig', [
            'companies' => $companiesData,
        ]);
    }

    /**
     * Şirket oluşturma formunu gösterir.
     */
    public function showCreate()
    {
        $companyTypes = DB::table('company_types')->orderBy('name')->get();
        return $this->render('user/createCompany.html.twig', [
            'companyTypes' => $companyTypes,
        ]);
    }

    // -------------------------------------------------------
    // API işlemleri
    // -------------------------------------------------------

    /**
     * Yeni şirket oluşturur.
     */
    public function create()
    {
        $uid    = $this->uid();
        $typeId = (int) $this->input('type', 0);

        if (!$typeId) {
            return $this->error('Geçersiz şirket tipi.');
        }

        if (!DB::table('company_types')->where('id', $typeId)->exists()) {
            return $this->error('Şirket tipi bulunamadı.');
        }

        // Kullanıcının parasını kontrol et (şirket kurmak bedava mı?)
        // Gerekirse burada gold kontrolü yapılabilir

        $now = date('Y-m-d H:i:s');
        $companyId = DB::table('companies')->insertGetId([
            'uid'        => $uid,
            'type'       => $typeId,
            'quality'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->success('Şirket başarıyla kuruldu.', ['id' => $companyId]);
    }

    /**
     * İş ilanı oluşturur (POST /api/company/work-offer/create).
     */
    public function createWorkOffer()
    {
        $uid       = $this->uid();
        $companyId = (int) $this->input('company_id', 0);
        $salary    = (float) $this->input('salary', 0);
        $currency  = trim($this->input('currency', ''));
        $reqSkill  = (int) $this->input('required_skill', 1);
        $title     = trim($this->input('title', ''));
        $desc      = trim($this->input('description', ''));

        if (!$companyId || $salary <= 0) {
            return $this->error('Şirket ve maaş bilgisi zorunludur.');
        }

        // Şirket sahibi kontrolü
        $company = DB::table('companies')
            ->where('id', $companyId)
            ->where('uid', $uid)
            ->first();

        if (!$company) {
            return $this->error('Bu şirkete erişim yetkiniz yok.');
        }

        // Aynı şirkette açık ilan var mı?
        $existingOffer = DB::table('work_offers')
            ->where('company', $companyId)
            ->whereNull('worker')
            ->first();

        if ($existingOffer) {
            return $this->error('Bu şirkete ait zaten bir açık iş ilanı var. Önce mevcut ilanı kaldırın veya güncelleyin.');
        }

        // Şirketin ülkesini bul
        $countryId = $this->getCompanyCountry($uid);

        // Para birimi yoksa ülkenin varsayılan para birimini kullan
        if (!$currency) {
            $country = DB::table('countries')->where('id', $countryId)->first();
            $currency = $country ? $country->currency : 'gold';
        }

        $now = date('Y-m-d H:i:s');
        $offerId = DB::table('work_offers')->insertGetId([
            'company'        => $companyId,
            'salary'         => $salary,
            'currency'       => $currency,
            'country'        => $countryId,
            'required_skill' => max(1, $reqSkill),
            'title'          => $title ?: null,
            'description'    => $desc ?: null,
            'worker'         => null,
            'last_work'      => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $this->success('İş ilanı başarıyla oluşturuldu.', ['id' => $offerId]);
    }

    /**
     * Mevcut iş ilanını günceller (POST /api/company/work-offer/update).
     */
    public function updateWorkOffer()
    {
        $uid     = $this->uid();
        $offerId = (int) $this->input('offer_id', 0);
        $salary  = (float) $this->input('salary', 0);
        $reqSkill = (int) $this->input('required_skill', 1);
        $title   = trim($this->input('title', ''));
        $desc    = trim($this->input('description', ''));

        if (!$offerId || $salary <= 0) {
            return $this->error('Geçersiz ilan veya maaş bilgisi.');
        }

        // İlan ve sahiplik kontrolü
        $offer = DB::table('work_offers')
            ->join('companies', 'work_offers.company', '=', 'companies.id')
            ->where('work_offers.id', $offerId)
            ->where('companies.uid', $uid)
            ->select('work_offers.*')
            ->first();

        if (!$offer) {
            return $this->error('Bu ilana erişim yetkiniz yok.');
        }

        DB::table('work_offers')
            ->where('id', $offerId)
            ->update([
                'salary'         => $salary,
                'required_skill' => max(1, $reqSkill),
                'title'          => $title ?: null,
                'description'    => $desc ?: null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

        return $this->success('İş ilanı güncellendi.');
    }

    /**
     * İş ilanını iptal eder (POST /api/company/work-offer/cancel).
     * Aktif işçisi olan ilan iptal edilemez.
     */
    public function cancelWorkOffer()
    {
        $uid     = $this->uid();
        $offerId = (int) $this->input('offer_id', 0);

        if (!$offerId) {
            return $this->error('Geçersiz ilan.');
        }

        // İlan ve sahiplik kontrolü
        $offer = DB::table('work_offers')
            ->join('companies', 'work_offers.company', '=', 'companies.id')
            ->where('work_offers.id', $offerId)
            ->where('companies.uid', $uid)
            ->select('work_offers.*')
            ->first();

        if (!$offer) {
            return $this->error('Bu ilana erişim yetkiniz yok.');
        }

        // Aktif işçisi varsa iptal edilemez
        if ($offer->worker) {
            return $this->error('Aktif işçisi olan ilan kaldırılamaz. Önce işçi istifa etmelidir.');
        }

        DB::table('work_offers')->where('id', $offerId)->delete();

        return $this->success('İş ilanı kaldırıldı.');
    }

    /**
     * Toplu yönetici üretimi - KALDIRILDI.
     * Üretim artık işçi günlük mesai yaptığında otomatik gerçekleşiyor.
     * Bu endpoint geriye dönük uyumluluk için pasif olarak bırakıldı.
     */
    public function workAsManager()
    {
        return $this->error(
            'Toplu yönetici üretimi kaldırıldı. Üretim işçi mesaisi sırasında otomatik gerçekleşir.',
            1
        );
    }

    // -------------------------------------------------------
    // Yardımcı metodlar (private)
    // -------------------------------------------------------

    /**
     * Kullanıcının bulunduğu ülke ID'sini döndürür.
     */
    private function getCompanyCountry(int $uid): int
    {
        $user = DB::table('users')
            ->join('regions', 'users.region', '=', 'regions.id')
            ->select(DB::raw('regions.country AS country_id'))
            ->where('users.id', $uid)
            ->first();

        return (int) ($user->country_id ?? 0);
    }
}
