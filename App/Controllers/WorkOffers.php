<?php

namespace App\Controllers;

use \App\System\App;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * İş piyasası controller'ı.
 * İş ilanlarının listelenmesi, filtrelenmesi ve başvuru işlemleri.
 */
class WorkOffers extends Controller
{
    /** Sayfa başına gösterilecek ilan sayısı */
    const PER_PAGE = 15;

    /**
     * İş piyasası listesini gösterir.
     * Arama, filtre, sıralama ve sayfalama desteği vardır.
     */
    public function showList()
    {
        $uid = $this->uid();

        // Query parametrelerini al
        $searchQuery    = trim($this->query('q', ''));
        $selectedFilter = $this->query('filter', 'all');
        $selectedSort   = $this->query('sort', 'best');
        $selectedCountry = (int) $this->query('country', 0);
        $page           = max(1, (int) $this->query('page', 1));

        // Kullanıcı bilgileri
        $user = DB::table('users')
            ->join('regions', 'users.region', '=', 'regions.id')
            ->select('users.*', DB::raw('regions.country AS country_id'))
            ->where('users.id', $uid)
            ->first();

        $userSkill    = (int) ($user->economic_skill ?? 1);
        $userLocation = (int) ($user->country_id ?? 0);

        // Aktif iş sözleşmesini kontrol et (work_offers tablosundan)
        $activeJob = DB::table('work_offers')
            ->where('worker', $uid)
            ->first();

        $jobData = null;
        if ($activeJob) {
            // Şirket adını getir
            $company = DB::table('companies')->where('id', $activeJob->company)->first();
            $companyName = 'Aktif Şirket';
            if ($company) {
                $companyType = DB::table('company_types')->where('id', $company->type)->first();
                $companyName = $companyType ? $companyType->name : 'Şirket #' . $company->id;
            }

            // Para birimi ülkesini bul
            $currencyCountry = DB::table('countries')
                ->where('currency', $activeJob->currency)
                ->first();

            $lastWorkDate = $activeJob->last_work
                ? date('Y-m-d', strtotime($activeJob->last_work))
                : null;
            $hasWorkedToday = $lastWorkDate && $lastWorkDate === date('Y-m-d');

            $jobData = [
                'id'                  => $activeJob->id,
                'company'             => $activeJob->company,
                'companyName'         => $companyName,
                'company_title'       => $companyName,
                'title'               => $activeJob->title ?? $companyName,
                'salary'              => (float) $activeJob->salary,
                'currency'            => $activeJob->currency ?? '-',
                'currencyCountryName' => $currencyCountry ? $currencyCountry->name : '',
                'currencyColor'       => '#64748b',
                'required_skill'      => (int) ($activeJob->required_skill ?? 0),
                'last_work'           => $activeJob->last_work,
                'hasWorkedToday'      => $hasWorkedToday,
            ];
        }

        // Ülke listesi (filtre için)
        $countries = DB::table('countries')->orderBy('name')->get();

        // Ana sorgu - work_offers tablosundan aktif olmayan (boş) ilanlar
        $query = DB::table('work_offers')
            ->leftJoin('companies', 'work_offers.company', '=', 'companies.id')
            ->leftJoin('company_types', 'companies.type', '=', 'company_types.id')
            ->leftJoin('countries', 'work_offers.country', '=', 'countries.id')
            ->whereNull('work_offers.worker') // Sadece açık (işçisiz) ilanlar
            ->select(
                'work_offers.*',
                'companies.quality',
                'company_types.name as companyTypeName',
                'company_types.output_item',
                'countries.name as countryName',
                'countries.currency as countryCurrency'
            );

        // Arama filtresi
        if ($searchQuery) {
            $query->where(function ($q) use ($searchQuery) {
                $q->where('work_offers.title', 'LIKE', '%' . $searchQuery . '%')
                  ->orWhere('company_types.name', 'LIKE', '%' . $searchQuery . '%');
            });
        }

        // Ülke filtresi
        if ($selectedCountry > 0) {
            $query->where('work_offers.country', $selectedCountry);
        }

        // Filtre tipi
        switch ($selectedFilter) {
            case 'available':
                // Başvurulabilir: skill yeterli, aynı ülkede
                $query->where('work_offers.required_skill', '<=', $userSkill)
                      ->where('work_offers.country', $userLocation);
                break;
            case 'matching':
                // Yetenek uyumlu
                $query->where('work_offers.required_skill', '<=', $userSkill);
                break;
            case 'local':
                // Yerel: kullanıcının bulunduğu ülke
                $query->where('work_offers.country', $userLocation);
                break;
        }

        // Toplam ilan sayısı
        $totalOffers = $query->count();
        $totalPages  = max(1, (int) ceil($totalOffers / self::PER_PAGE));
        $page        = min($page, $totalPages);
        $offset      = ($page - 1) * self::PER_PAGE;

        // Sıralama
        switch ($selectedSort) {
            case 'salary_desc':
                $query->orderBy('work_offers.salary', 'desc');
                break;
            case 'salary_asc':
                $query->orderBy('work_offers.salary', 'asc');
                break;
            case 'skill_asc':
                $query->orderBy('work_offers.required_skill', 'asc');
                break;
            case 'latest':
                $query->orderBy('work_offers.created_at', 'desc');
                break;
            case 'best':
            default:
                // "En uygun": skill eşleşmesi önce, sonra maaş yüksekten
                $query->orderByRaw(
                    'CASE WHEN work_offers.required_skill <= ? THEN 0 ELSE 1 END ASC, work_offers.salary DESC',
                    [$userSkill]
                );
                break;
        }

        // Sayfalama
        $rawOffers = $query->offset($offset)->limit(self::PER_PAGE)->get();

        // İlanları view için hazırla
        $offers = [];
        foreach ($rawOffers as $row) {
            // Para birimi ülkesini bul
            $currencyCountry = DB::table('countries')
                ->where('currency', $row->currency)
                ->first();

            $jobCountry = DB::table('countries')->where('id', $row->country)->first();
            $skillEnough = $userSkill >= (int) ($row->required_skill ?? 0);
            $needsTravel = $row->country !== $userLocation;

            $offers[] = [
                'id'                  => $row->id,
                'company'             => $row->company,
                'companyName'         => $row->companyTypeName ?? ('Şirket #' . $row->company),
                'companyTypeName'     => $row->companyTypeName ?? 'Genel Tesis',
                'title'               => $row->title ?? ($row->companyTypeName ?? 'İş İlanı'),
                'description'         => $row->description ?? '',
                'salary'              => (float) ($row->salary ?? 0),
                'currency'            => $row->currency ?? '-',
                'currencyCountryName' => $currencyCountry ? $currencyCountry->name : '',
                'currencyColor'       => '#64748b',
                'required_skill'      => (int) ($row->required_skill ?? 0),
                'quality'             => (int) ($row->quality ?? 1),
                'countryName'         => $jobCountry ? $jobCountry->name : '-',
                'skillEnough'         => $skillEnough,
                'needsTravel'         => $needsTravel,
                'canApply'            => !$activeJob && $skillEnough && !$needsTravel,
                'priority'            => (!$activeJob && $skillEnough && !$needsTravel) ? 3
                                        : ((!$activeJob && !$needsTravel) ? 2 : 1),
            ];
        }

        return $this->render('market/workList.html.twig', [
            'offers'          => $offers,
            'job'             => $jobData,
            'countries'       => $countries,
            'selectedCountry' => $selectedCountry,
            'selectedSort'    => $selectedSort,
            'selectedFilter'  => $selectedFilter,
            'searchQuery'     => $searchQuery,
            'currentPage'     => $page,
            'totalPages'      => $totalPages,
            'totalOffers'     => $totalOffers,
            'hasPrevPage'     => $page > 1,
            'hasNextPage'     => $page < $totalPages,
        ]);
    }

    /**
     * İş ilanına başvurur (POST /api/jobs/apply).
     *
     * - users.economic_skill ile required_skill kontrolü yapılır
     * - Aktif iş tespiti doğrudan work_offers tablosundan yapılır
     */
    public function apply()
    {
        $uid  = $this->uid();
        $jobId = (int) $this->input('id', 0);

        if (!$jobId) {
            return $this->error('Geçersiz iş ilanı.');
        }

        // Kullanıcı bilgisi
        $user = DB::table('users')
            ->join('regions', 'users.region', '=', 'regions.id')
            ->select('users.economic_skill', DB::raw('regions.country AS country_id'))
            ->where('users.id', $uid)
            ->first();

        $userSkill    = (int) ($user->economic_skill ?? 1);
        $userLocation = (int) ($user->country_id ?? 0);

        // Zaten aktif iş var mı kontrol et
        $existingJob = DB::table('work_offers')
            ->where('worker', $uid)
            ->first();

        if ($existingJob) {
            return $this->error('Zaten aktif bir iş sözleşmeniz var. Önce mevcut sözleşmenizi feshettirin.');
        }

        // İlan bilgisini al - açık (işçisiz) olmalı
        $offer = DB::table('work_offers')
            ->where('id', $jobId)
            ->whereNull('worker')
            ->first();

        if (!$offer) {
            return $this->error('Bu ilan artık mevcut değil veya dolu.');
        }

        // Yetenek kontrolü
        $requiredSkill = (int) ($offer->required_skill ?? 0);
        if ($userSkill < $requiredSkill) {
            return $this->error(
                sprintf(
                    'Bu iş için en az %d ekonomik yetenek gerekiyor. Mevcut yeteneğiniz: %d.',
                    $requiredSkill,
                    $userSkill
                )
            );
        }

        // İlan ülkesi = kullanıcı konumu kontrolü
        if ($offer->country && $offer->country != $userLocation) {
            return $this->error('Bu iş farklı bir ülkede. Önce o ülkeye gitmeniz gerekiyor.');
        }

        $now = date('Y-m-d H:i:s');

        // Başvuruyu kaydet: worker ve updated_at güncelle
        DB::table('work_offers')
            ->where('id', $jobId)
            ->update([
                'worker'     => $uid,
                'last_work'  => null, // Yeni işe başlarken sıfırla
                'updated_at' => $now,
            ]);

        return $this->success('İş sözleşmesi başarıyla imzalandı. Artık çalışabilirsiniz!');
    }
}
