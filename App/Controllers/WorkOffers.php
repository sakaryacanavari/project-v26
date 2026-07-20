<?php

namespace App\Controllers;

use App\Models\WorkOffer;
use App\Models\User;
use App\Models\CompanyType;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

class WorkOffers extends Controller
{
    public function showList()
    {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

        $userId = $this->isLogged ? (int) App::user()->getUid() : 0;
        $currentUser = $userId > 0 ? User::find($userId) : null;

        $defaultCountryId = 1;
        if (!empty($currentUser) && !empty($currentUser->location) && !empty($currentUser->location->country_id)) {
            $defaultCountryId = (int)$currentUser->location->country_id;
        }

        $country = isset($_GET['country']) ? (int)$_GET['country'] : $defaultCountryId;
        if ($country < 1) {
            $country = $defaultCountryId;
        }

        $searchQuery = trim((string)($_GET['q'] ?? ''));
        $filter = trim((string)($_GET['filter'] ?? 'all'));
        $sort = trim((string)($_GET['sort'] ?? 'best'));

        $validFilters = ['all', 'available', 'matching', 'local'];
        if (!in_array($filter, $validFilters, true)) {
            $filter = 'all';
        }

        $validSorts = ['best', 'salary_desc', 'salary_asc', 'skill_asc', 'latest'];
        if (!in_array($sort, $validSorts, true)) {
            $sort = 'best';
        }

        $canonicalParams = $this->buildWorkOffersQueryParams($defaultCountryId, [
            'country' => $country,
            'sort' => $sort,
            'filter' => $filter,
            'q' => $searchQuery,
            'page' => $page,
        ]);

        if (!$this->isAjaxRequest()) {
            $currentQuery = (string) $this->req->getUri()->getQuery();
            $canonicalQuery = http_build_query($canonicalParams);

            if ($currentQuery !== $canonicalQuery) {
                App::redirect($this->buildWorkOffersUrl($canonicalParams));
            }
        }

        $limitPerPage = 15;

        $countryRows = DB::table('countries')
            ->select('id', 'name', 'currency', 'minimum_wage', 'color')
            ->orderBy('name', 'asc')
            ->get()
            ->toArray();

        $countryList = [];
        $countryMetaById = [];
        $currencyMetaByCode = [];

        foreach ($countryRows as $countryRow) {
            $countryId = (int)$countryRow->id;
            $currencyCode = strtoupper(trim((string)($countryRow->currency ?? '')));

            $countryData = [
                'id' => $countryId,
                'name' => (string)$countryRow->name,
                'currency' => $currencyCode,
                'minimum_wage' => (float)($countryRow->minimum_wage ?? 0),
                'color' => $countryRow->color,
            ];

            $countryList[] = $countryData;
            $countryMetaById[$countryId] = $countryData;

            if ($currencyCode !== '' && !isset($currencyMetaByCode[$currencyCode])) {
                $currencyMetaByCode[$currencyCode] = [
                    'code' => $currencyCode,
                    'country_name' => (string)$countryRow->name,
                    'color' => $countryRow->color,
                    'minimum_wage' => (float)($countryRow->minimum_wage ?? 0),
                ];
            }
        }

        $userEconomicSkill = 1;
        $userEconomicXp = 0;
        $userCountryId = $defaultCountryId;
        $userLastDailyWorkAt = null;
        $userWorkedToday = false;
        $hasActiveJob = false;
        $activeJobRow = null;

        if (!empty($currentUser)) {
            $userEconomicSkill = max(1, (int)($currentUser->economic_skill ?? 1));
            $userEconomicXp = max(0, (int)($currentUser->economic_xp ?? 0));

            if (!empty($currentUser->location) && !empty($currentUser->location->country_id)) {
                $userCountryId = (int)$currentUser->location->country_id;
            }

            $userLastDailyWorkAt = $this->getUserLastDailyWorkAt($userId);
            $userWorkedToday = !empty($userLastDailyWorkAt)
                ? (date('Y-m-d', strtotime($userLastDailyWorkAt)) === date('Y-m-d'))
                : false;

            if ($this->isLogged && $userId > 0) {
                $activeOfferId = $this->getLatestAssignedWorkOfferId($userId);

                if ($activeOfferId > 0) {
                    $activeJobRow = DB::table('work_offers as wo')
                        ->leftJoin('companies as c', 'c.id', '=', 'wo.company')
                        ->select(
                            'wo.id',
                            'wo.company',
                            'wo.country',
                            'wo.salary',
                            'wo.currency',
                            'wo.required_skill',
                            'wo.title',
                            'wo.description',
                            'wo.last_work',
                            'c.type as company_type',
                            'c.quality as company_quality'
                        )
                        ->where('wo.id', $activeOfferId)
                        ->first();
                }

                $hasActiveJob = !empty($activeJobRow);
            }
        }

        $offersQuery = DB::table('work_offers as wo')
            ->leftJoin('companies as c', 'c.id', '=', 'wo.company')
            ->whereNull('wo.worker');

        if ($country > 0) {
            $offersQuery->where('wo.country', $country);
        }

        if ($searchQuery !== '') {
            $offersQuery->where(function ($query) use ($searchQuery) {
                $query->where('wo.title', 'like', '%' . $searchQuery . '%')
                    ->orWhere('wo.description', 'like', '%' . $searchQuery . '%')
                    ->orWhere('wo.currency', 'like', '%' . $searchQuery . '%');
            });
        }

        $offersRaw = $offersQuery
            ->select(
                'wo.id',
                'wo.company',
                'wo.country',
                'wo.salary',
                'wo.currency',
                'wo.required_skill',
                'wo.title',
                'wo.description',
                'wo.created_at',
                'wo.updated_at',
                'c.type as company_type',
                'c.quality as company_quality'
            )
            ->get()
            ->toArray();

        $offersPrepared = [];

        foreach ($offersRaw as $offer) {
            $companyType = (int)($offer->company_type ?? 0);
            $companyQuality = max(1, (int)($offer->company_quality ?? 1));
            $offerCountryId = (int)($offer->country ?? 0);
            $currencyCode = strtoupper(trim((string)($offer->currency ?? '')));
            $requiredSkill = (int)($offer->required_skill ?? 0);
            $salaryValue = (float)($offer->salary ?? 0);
            $title = trim((string)($offer->title ?? ''));
            $description = trim((string)($offer->description ?? ''));

            $needsTravel = ($userCountryId > 0 && $offerCountryId > 0 && $offerCountryId !== $userCountryId);
            $skillEnough = ($userEconomicSkill >= $requiredSkill);
            $canApply = (!$hasActiveJob && !$needsTravel && $skillEnough);

            $companyName = 'State Company';
            $companyTypeName = 'Genel Tesis';

            if ($companyType > 0 && isset(CompanyType::$types[$companyType]["name"])) {
                $companyTypeName = CompanyType::$types[$companyType]["name"];
                $companyName = CompanyType::$types[$companyType]["name"];
            }

            if ($title !== '') {
                $companyName = $title;
            }

            $currencyMeta = $currencyMetaByCode[$currencyCode] ?? null;
            $countryMeta = $countryMetaById[$offerCountryId] ?? null;

            $priority = 1;
            if ($canApply) {
                $priority = 3;
            } elseif (!$hasActiveJob && !$needsTravel) {
                $priority = 2;
            }

            if ($filter === 'available' && !$canApply) {
                continue;
            }

            if ($filter === 'matching' && !$skillEnough) {
                continue;
            }

            if ($filter === 'local' && $needsTravel) {
                continue;
            }

            $offersPrepared[] = [
                'id' => (int)$offer->id,
                'company' => (int)($offer->company ?? 0),
                'country' => $offerCountryId,
                'required_skill' => $requiredSkill,
                'skill' => $requiredSkill,
                'salary' => $salaryValue,
                'money' => $salaryValue,
                'currency' => $currencyCode !== '' ? $currencyCode : '-',
                'quality' => $companyQuality,
                'companyName' => $companyName,
                'companyTypeName' => $companyTypeName,
                'title' => $title,
                'description' => $description,
                'countryName' => !empty($countryMeta['name']) ? $countryMeta['name'] : '',
                'countryColor' => !empty($countryMeta['color']) ? $countryMeta['color'] : null,
                'countryMinimumWage' => !empty($countryMeta['minimum_wage']) ? (float)$countryMeta['minimum_wage'] : 0,
                'currencyCountryName' => !empty($currencyMeta['country_name']) ? $currencyMeta['country_name'] : '',
                'currencyColor' => !empty($currencyMeta['color']) ? $currencyMeta['color'] : null,
                'currencyMinimumWage' => !empty($currencyMeta['minimum_wage']) ? (float)$currencyMeta['minimum_wage'] : 0,
                'needsTravel' => $needsTravel,
                'skillEnough' => $skillEnough,
                'canApply' => $canApply,
                'priority' => $priority,
                'created_at' => !empty($offer->created_at) ? $offer->created_at : null,
                'updated_at' => !empty($offer->updated_at) ? $offer->updated_at : null,
            ];
        }

        usort($offersPrepared, function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'salary_desc':
                    $salaryCompare = ((float)$b['salary']) <=> ((float)$a['salary']);
                    if ($salaryCompare !== 0) {
                        return $salaryCompare;
                    }
                    return ((int)$a['required_skill']) <=> ((int)$b['required_skill']);

                case 'salary_asc':
                    $salaryCompare = ((float)$a['salary']) <=> ((float)$b['salary']);
                    if ($salaryCompare !== 0) {
                        return $salaryCompare;
                    }
                    return ((int)$a['required_skill']) <=> ((int)$b['required_skill']);

                case 'skill_asc':
                    $skillCompare = ((int)$a['required_skill']) <=> ((int)$b['required_skill']);
                    if ($skillCompare !== 0) {
                        return $skillCompare;
                    }
                    return ((float)$b['salary']) <=> ((float)$a['salary']);

                case 'latest':
                    return ((int)$b['id']) <=> ((int)$a['id']);

                case 'best':
                default:
                    $priorityCompare = ((int)$b['priority']) <=> ((int)$a['priority']);
                    if ($priorityCompare !== 0) {
                        return $priorityCompare;
                    }

                    $salaryCompare = ((float)$b['salary']) <=> ((float)$a['salary']);
                    if ($salaryCompare !== 0) {
                        return $salaryCompare;
                    }

                    $skillCompare = ((int)$a['required_skill']) <=> ((int)$b['required_skill']);
                    if ($skillCompare !== 0) {
                        return $skillCompare;
                    }

                    return ((int)$b['id']) <=> ((int)$a['id']);
            }
        });

        $totalOffers = count($offersPrepared);
        $totalPages = max(1, (int)ceil($totalOffers / $limitPerPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $queryBase = $this->buildWorkOffersQueryParams($defaultCountryId, [
            'country' => $country,
            'sort' => $sort,
            'filter' => $filter,
            'q' => $searchQuery,
            'page' => 1,
        ]);

        unset($queryBase['page']);

        $skillSortTarget = $sort === 'skill_asc' ? 'best' : 'skill_asc';
        $salarySortTarget = $sort === 'salary_desc' ? 'salary_asc' : 'salary_desc';

        $filterUrls = [
            'all' => $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['filter' => 'all']))),
            'available' => $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['filter' => 'available']))),
            'matching' => $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['filter' => 'matching']))),
            'local' => $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['filter' => 'local']))),
        ];

        $sortUrls = [
            'skill' => $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['sort' => $skillSortTarget]))),
            'salary' => $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['sort' => $salarySortTarget]))),
        ];

        $paginationUrls = [
            'prev' => $page > 1 ? $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['page' => $page - 1]))) : null,
            'next' => $page < $totalPages ? $this->buildWorkOffersUrl($this->buildWorkOffersQueryParams($defaultCountryId, array_merge($queryBase, ['page' => $page + 1]))) : null,
        ];

        $offset = max(0, ($page - 1) * $limitPerPage);
        $offers = array_slice($offersPrepared, $offset, $limitPerPage);

        $job = null;

        if (!empty($activeJobRow)) {
            $jobCurrency = strtoupper(trim((string)($activeJobRow->currency ?? '')));
            $jobCurrencyMeta = $currencyMetaByCode[$jobCurrency] ?? null;
            $jobCompanyType = (int)($activeJobRow->company_type ?? 0);
            $jobTitle = trim((string)($activeJobRow->title ?? ''));
            $jobDescription = trim((string)($activeJobRow->description ?? ''));

            $jobCompanyName = 'Aktif Şirket';
            $jobCompanyTypeName = 'Genel Tesis';

            if ($jobCompanyType > 0 && isset(CompanyType::$types[$jobCompanyType]["name"])) {
                $jobCompanyTypeName = CompanyType::$types[$jobCompanyType]["name"];
                $jobCompanyName = CompanyType::$types[$jobCompanyType]["name"];
            }

            if ($jobTitle !== '') {
                $jobCompanyName = $jobTitle;
            }

            $job = [
                'hasJob' => true,
                'hasWorkedToday' => $userWorkedToday,
                'company' => [
                    'id' => (int)($activeJobRow->company ?? 0),
                    'name' => $jobCompanyName,
                ],
                'companyName' => $jobCompanyName,
                'company_title' => $jobCompanyName,
                'title' => $jobTitle !== '' ? $jobTitle : $jobCompanyName,
                'description' => $jobDescription,
                'money' => (float)($activeJobRow->salary ?? 0),
                'salary' => (float)($activeJobRow->salary ?? 0),
                'currency' => $jobCurrency !== '' ? $jobCurrency : '-',
                'last_work' => !empty($userLastDailyWorkAt) ? $userLastDailyWorkAt : (!empty($activeJobRow->last_work) ? $activeJobRow->last_work : null),
                'required_skill' => (int)($activeJobRow->required_skill ?? 0),
                'skill' => (int)($activeJobRow->required_skill ?? 0),
                'quality' => max(1, (int)($activeJobRow->company_quality ?? 1)),
                'companyTypeName' => $jobCompanyTypeName,
                'currencyCountryName' => !empty($jobCurrencyMeta['country_name']) ? $jobCurrencyMeta['country_name'] : '',
                'currencyColor' => !empty($jobCurrencyMeta['color']) ? $jobCurrencyMeta['color'] : null,
            ];
        }

        $myData = null;
        if (!empty($currentUser)) {
            $myData = $currentUser->toArray();
            $myData['economic_skill'] = $userEconomicSkill;
            $myData['economic_xp'] = $userEconomicXp;
        }

        return $this->render('market/workList.html.twig', [
            'offers' => $offers,
            'countryList' => $countryList,
            'my' => $myData,
            'job' => $job,
            'selectedCountry' => $country,
            'currentPage' => $page,
            'totalOffers' => $totalOffers,
            'limitPerPage' => $limitPerPage,
            'totalPages' => $totalPages,
            'hasPrevPage' => $page > 1,
            'hasNextPage' => $page < $totalPages,
            'searchQuery' => $searchQuery,
            'selectedFilter' => $filter,
            'selectedSort' => $sort,
            'userWorkedToday' => $userWorkedToday,
            'userLastWorkedAt' => $userLastDailyWorkAt,
            'resetUrl' => $this->buildWorkOffersUrl([]),
            'filterUrls' => $filterUrls,
            'sortUrls' => $sortUrls,
            'paginationUrls' => $paginationUrls,
            'salarySortTarget' => $salarySortTarget,
            'skillSortTarget' => $skillSortTarget,
        ]);
    }

    public function apply()
    {
        $uid = (int)App::user()->getUid();
        $offerId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        $blocked = ActionRateLimiter::throttle(
            'job_apply',
            'uid:' . $uid,
            10,
            600,
            900,
            'Is basvurulari icin gecici limite ulastiniz. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        if ($offerId < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        if ($this->getLatestAssignedWorkOfferId($uid) > 0) {
            return [
                'error' => 1,
                'message' => 'Yeni bir işe girmeden önce mevcut işinizden ayrılmalısınız.'
            ];
        }

        $offer = DB::table('work_offers')
            ->where('id', $offerId)
            ->whereNull('worker')
            ->first();

        if (empty($offer)) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $currentUser = User::find($uid);
        if (empty($currentUser)) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $userCountryId = 0;
        if (!empty($currentUser->location) && !empty($currentUser->location->country_id)) {
            $userCountryId = (int)$currentUser->location->country_id;
        }

        if (!empty($offer->country) && $userCountryId > 0 && (int)$offer->country !== $userCountryId) {
            return [
                'error' => 1,
                'message' => 'Bu iş ilanı farklı bir ülkeye ait. Önce ilgili ülkeye gitmelisiniz.'
            ];
        }

        $userEconomicSkill = max(1, (int)($currentUser->economic_skill ?? 1));
        $requiredEconomicSkill = max(0, (int)($offer->required_skill ?? 0));

        if ($userEconomicSkill < $requiredEconomicSkill) {
            return [
                'error' => 1,
                'message' => 'Bu iş için minimum ekonomik yetenek gereksinimini karşılamıyorsunuz. Gerekli: ' . $requiredEconomicSkill . ' / Siz: ' . $userEconomicSkill
            ];
        }

        $updated = DB::table('work_offers')
            ->where('id', $offerId)
            ->whereNull('worker')
            ->update([
                'worker' => $uid,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if (!$updated) {
            return [
                'error' => 1,
                'message' => 'İş ilanı artık uygun değil veya başka biri tarafından alındı.'
            ];
        }

        return [
            'error' => 0,
            'message' => 'İş başvurunuz kabul edildi.'
        ];
    }

    public function applySafe()
    {
        $uid = (int) App::user()->getUid();
        $offerId = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        $blocked = ActionRateLimiter::throttle(
            'job_apply',
            'uid:' . $uid,
            10,
            600,
            900,
            'Is basvurulari icin gecici limite ulastiniz. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        if ($offerId < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        DB::beginTransaction();
        try {
            $offer = DB::table('work_offers')
                ->where('id', $offerId)
                ->lockForUpdate()
                ->first();

            if (empty($offer) || !empty($offer->worker)) {
                DB::rollBack();
                throw new AppException(AppException::INVALID_DATA);
            }

            $hasAssignedOffer = DB::table('work_offers')
                ->where('worker', $uid)
                ->lockForUpdate()
                ->exists();

            if ($hasAssignedOffer) {
                DB::rollBack();
                return [
                    'error' => 1,
                    'message' => 'Yeni bir ise girmeden once mevcut isinizden ayrilmalisiniz.'
                ];
            }

            $currentUser = User::find($uid);
            if (empty($currentUser)) {
                DB::rollBack();
                throw new AppException(AppException::ACTION_FAILED);
            }

            $userCountryId = 0;
            if (!empty($currentUser->location) && !empty($currentUser->location->country_id)) {
                $userCountryId = (int) $currentUser->location->country_id;
            }

            if (!empty($offer->country) && $userCountryId > 0 && (int) $offer->country !== $userCountryId) {
                DB::rollBack();
                return [
                    'error' => 1,
                    'message' => 'Bu is ilani farkli bir ulkeye ait. Once ilgili ulkeye gitmelisiniz.'
                ];
            }

            $userEconomicSkill = max(1, (int) ($currentUser->economic_skill ?? 1));
            $requiredEconomicSkill = max(0, (int) ($offer->required_skill ?? 0));

            if ($userEconomicSkill < $requiredEconomicSkill) {
                DB::rollBack();
                return [
                    'error' => 1,
                    'message' => 'Bu is icin minimum ekonomik yetenek gereksinimini karsilamiyorsunuz. Gerekli: ' . $requiredEconomicSkill . ' / Siz: ' . $userEconomicSkill
                ];
            }

            $updated = DB::table('work_offers')
                ->where('id', $offerId)
                ->whereNull('worker')
                ->update([
                    'worker' => $uid,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if (!$updated) {
                DB::rollBack();
                return [
                    'error' => 1,
                    'message' => 'Is ilani artik uygun degil veya baska biri tarafindan alindi.'
                ];
            }

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e instanceof AppException ? $e : new AppException(AppException::ACTION_FAILED);
        }

        return [
            'error' => 0,
            'message' => 'Is basvurunuz kabul edildi.'
        ];
    }

    private function getLatestAssignedWorkOfferId($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return 0;
        }

        $assignedOfferIds = DB::table('work_offers')
            ->where('worker', $uid)
            ->orderBy('id', 'desc')
            ->pluck('id')
            ->toArray();

        if (empty($assignedOfferIds)) {
            return 0;
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

        return $latestOfferId;
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

    private function isAjaxRequest()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function buildWorkOffersUrl(array $params)
    {
        $basePath = $this->app->getContainer()->get('router')->urlFor('workOffers');
        $params = $this->removeEmptyQueryParams($params);

        if (empty($params)) {
            return $basePath;
        }

        return $basePath . '?' . http_build_query($params);
    }

    private function buildWorkOffersQueryParams($defaultCountryId, array $params)
    {
        $normalized = [];

        $country = isset($params['country']) ? (int) $params['country'] : 0;
        if ($country > 0 && $country !== (int) $defaultCountryId) {
            $normalized['country'] = $country;
        }

        $sort = trim((string) ($params['sort'] ?? 'best'));
        if ($sort !== '' && $sort !== 'best') {
            $normalized['sort'] = $sort;
        }

        $filter = trim((string) ($params['filter'] ?? 'all'));
        if ($filter !== '' && $filter !== 'all') {
            $normalized['filter'] = $filter;
        }

        $search = trim((string) ($params['q'] ?? ''));
        if ($search !== '') {
            $normalized['q'] = $search;
        }

        $page = isset($params['page']) ? (int) $params['page'] : 1;
        if ($page > 1) {
            $normalized['page'] = $page;
        }

        return $normalized;
    }

    private function removeEmptyQueryParams(array $params)
    {
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($params[$key]);
            }
        }

        return $params;
    }
}
