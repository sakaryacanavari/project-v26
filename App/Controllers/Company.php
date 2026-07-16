<?php

namespace App\Controllers;

use App\Models\CompanyType;
use App\Models\User;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use \App\Models\Company as CompanyModel;
use App\System\Session;
use Illuminate\Database\Capsule\Manager as DB;

class Company extends Controller
{
    private const CREATE_QUALITIES = [1, 3, 5];

    private function prepareCompanyTypes()
    {
        $types = [];

        foreach (CompanyType::$types as $id => $type) {
            $type['display_name'] = $this->getCompanyDisplayName($type['name'] ?? '');
            $types[$id] = $type;
        }

        return $types;
    }

    private function getCompanyDisplayName($name)
    {
        $displayNames = [
            'raw food factory' => 'Ham Gida Tesisi',
            'raw weapon factory' => 'Ham Silah Tesisi',
            'raw house factory' => 'Ham Yapi Tesisi',
            'food factory' => 'Gida Tesisi',
        ];

        $key = strtolower(trim((string) $name));

        return $displayNames[$key] ?? $name;
    }

    public function showMyCompanies()
    {
        $uid = App::session()->getUid();
        $companyTypes = $this->prepareCompanyTypes();

        $list = CompanyModel::where([
            "uid" => $uid
        ])->get()->toArray();

        usort($list, function ($a, $b) {
            $sectorA = CompanyType::$types[$a["type"]]["sector"] ?? 0;
            $sectorB = CompanyType::$types[$b["type"]]["sector"] ?? 0;

            if ($sectorA === $sectorB) {
                return ($a["id"] ?? 0) <=> ($b["id"] ?? 0);
            }

            return ($sectorA > $sectorB) ? -1 : 1;
        });

        $currentUser = User::find($uid);

        $defaultCountryId = (!empty($currentUser) && !empty($currentUser->location) && !empty($currentUser->location->country_id))
            ? (int)$currentUser->location->country_id
            : 1;

        $countryRows = DB::table('countries')
            ->select('id', 'name', 'currency', 'minimum_wage', 'color')
            ->orderBy('name', 'asc')
            ->get()
            ->toArray();

        $countryList = [];
        $currencyMap = [];
        $countryMetaById = [];

        foreach ($countryRows as $country) {
            $countryCurrency = strtoupper(trim((string)($country->currency ?? '')));

            $countryMetaById[(int)$country->id] = [
                'id' => (int)$country->id,
                'name' => $country->name,
                'currency' => $countryCurrency,
                'minimum_wage' => (float)($country->minimum_wage ?? 0),
                'color' => $country->color ?? null,
            ];

            $countryList[] = [
                'id' => (int)$country->id,
                'name' => $country->name,
                'currency' => $countryCurrency,
                'minimum_wage' => (float)($country->minimum_wage ?? 0),
                'color' => $country->color ?? null,
            ];

            if ($countryCurrency !== '' && !isset($currencyMap[$countryCurrency])) {
                $currencyMap[$countryCurrency] = [
                    'code' => $countryCurrency,
                    'name' => $countryCurrency,
                    'minimum_wage' => (float)($country->minimum_wage ?? 0),
                    'country_name' => $country->name,
                    'color' => $country->color ?? null,
                ];
            }
        }

        if (empty($currencyMap)) {
            $currencyMap['EEK'] = [
                'code' => 'EEK',
                'name' => 'EEK',
                'minimum_wage' => 0,
                'country_name' => '',
                'color' => null,
            ];
        }

        $companyIds = array_column($list, 'id');
        $openOffers = [];
        $payrollSummaryMap = [];
        $recentActions = [];
        $companyMap = [];
        $companyOperations = [];
        $totalCapacity = 0;
        $totalWorkersRequired = 0;
        $totalAssignedWorkers = 0;
        $totalVacantPositions = 0;
        $totalOpenOffers = 0;
        $totalOpenOfferPositions = 0;
        $totalOfferGap = 0;
        $usedCurrencyCodes = [];
        $blockedFacilityCount = 0;
        $payrollRiskCount = 0;
        $inventoryByKey = [];

        if (DB::getSchemaBuilder()->hasTable('user_items')) {
            $inventoryRows = DB::table('user_items')
                ->where('uid', $uid)
                ->get();

            foreach ($inventoryRows as $inventoryRow) {
                $inventoryKey = (int) ($inventoryRow->item ?? 0) . ':' . (int) ($inventoryRow->quality ?? 0);
                $inventoryByKey[$inventoryKey] = (int) ($inventoryRow->quantity ?? 0);
            }
        }

        foreach ($list as $companyRow) {
            $companyId = (int) ($companyRow["id"] ?? 0);
            $companyMap[$companyId] = $companyRow;

            $companyType = $companyTypes[(int) ($companyRow['type'] ?? 0)] ?? null;
            $qualityData = !empty($companyType)
                ? ($companyType['qualities'][(int) ($companyRow['quality'] ?? 0)] ?? null)
                : null;

            if (!empty($companyType) && !empty($qualityData)) {
                $requiredWorkers = max(0, (int) ($qualityData['workers'] ?? 0));
                $inputProduct = max(0, (int) ($qualityData['consume_product'] ?? 0));
                $inputRequired = max(0, (int) ($qualityData['consume_amount'] ?? 0));
                $outputAmount = max(0, (int) ($qualityData['product_amount'] ?? 0));
                $inputKey = $inputProduct . ':' . (int) ($companyRow['quality'] ?? 0);

                $companyOperations[$companyId] = [
                    'required_workers' => $requiredWorkers,
                    'assigned_workers' => 0,
                    'open_positions' => 0,
                    'open_offer_count' => 0,
                    'offer_gap' => 0,
                    'capacity' => $outputAmount,
                    'input_product' => $inputProduct,
                    'input_required' => $inputRequired,
                    'input_stock' => $inputProduct > 0 ? ($inventoryByKey[$inputKey] ?? 0) : null,
                    'status' => 'ready',
                ];

                $totalCapacity += $outputAmount;
                $totalWorkersRequired += $requiredWorkers;
            } else {
                $companyOperations[$companyId] = [
                    'required_workers' => 0,
                    'assigned_workers' => 0,
                    'open_positions' => 0,
                    'open_offer_count' => 0,
                    'offer_gap' => 0,
                    'capacity' => 0,
                    'input_product' => 0,
                    'input_required' => 0,
                    'input_stock' => null,
                    'status' => 'control_required',
                ];
            }

            if (!empty($companyRow["created_at"])) {
                $companyTypeName = $companyTypes[(int) ($companyRow["type"] ?? 0)]["display_name"] ?? ("Tesis #" . $companyId);
                $recentActions[] = [
                    "type" => "Tesis kuruldu",
                    "label" => $companyTypeName,
                    "timestamp" => $companyRow["created_at"],
                ];
            }
        }

        if (!empty($companyIds) && DB::getSchemaBuilder()->hasTable('work_offers')) {
            $offers = DB::table('work_offers')
                ->whereIn('company', $companyIds)
                ->orderBy('id', 'desc')
                ->get();

            foreach ($offers as $offer) {
                $offerCountryId = (int)($offer->country ?? 0);
                $offerCurrency = strtoupper(trim((string)($offer->currency ?? '')));

                if ($offerCurrency !== '') {
                    $usedCurrencyCodes[$offerCurrency] = true;
                }

                $offerCompanyId = (int) ($offer->company ?? 0);
                if (isset($companyOperations[$offerCompanyId])) {
                    if ($offer->worker === null) {
                        $companyOperations[$offerCompanyId]['open_offer_count']++;
                        $companyOperations[$offerCompanyId]['open_positions']++;
                    } else {
                        $companyOperations[$offerCompanyId]['assigned_workers']++;
                        $totalAssignedWorkers++;
                    }
                }

                $offerData = [
                    "id" => (int)$offer->id,
                    "company" => (int)$offer->company,
                    "country" => $offerCountryId,
                    "salary" => (float)($offer->salary ?? 0),
                    "required_skill" => (int)($offer->required_skill ?? 0),
                    "currency" => $offerCurrency !== '' ? $offerCurrency : 'EEK',
                    "title" => $offer->title ?? null,
                    "description" => $offer->description ?? null,
                    "last_work" => $offer->last_work ?? null,

                    // Geriye dönük Twig uyumu
                    "money" => (float)($offer->salary ?? 0),
                    "skill" => (int)($offer->required_skill ?? 0),
                ];

                if ($offerCountryId > 0 && isset($countryMetaById[$offerCountryId])) {
                    $offerData["country_name"] = $countryMetaById[$offerCountryId]["name"];
                    $offerData["minimum_wage"] = (float)$countryMetaById[$offerCountryId]["minimum_wage"];
                    $offerData["country_color"] = $countryMetaById[$offerCountryId]["color"];
                } else {
                    $offerData["country_name"] = '';
                    $offerData["minimum_wage"] = 0;
                    $offerData["country_color"] = null;
                }

                if (isset($currencyMap[$offerData["currency"]])) {
                    $offerData["currency_country_name"] = $currencyMap[$offerData["currency"]]["country_name"];
                    $offerData["currency_minimum_wage"] = (float)$currencyMap[$offerData["currency"]]["minimum_wage"];
                    $offerData["currency_color"] = $currencyMap[$offerData["currency"]]["color"];
                } else {
                    $offerData["currency_country_name"] = '';
                    $offerData["currency_minimum_wage"] = 0;
                    $offerData["currency_color"] = null;
                }

                $companyRow = $companyMap[(int)$offer->company] ?? null;
                $companyTypeName = (!empty($companyRow) && isset($companyTypes[(int) ($companyRow["type"] ?? 0)]["display_name"]))
                    ? $companyTypes[(int) $companyRow["type"]]["display_name"]
                    : ("Tesis #" . (int)$offer->company);
                $offerData["company_name"] = $companyTypeName;
                $offerData["company_quality"] = !empty($companyRow) ? (int)($companyRow["quality"] ?? 0) : 0;

                $actionTimestamp = $offer->updated_at ?? $offer->created_at ?? null;
                if (!empty($actionTimestamp)) {
                    $recentActions[] = [
                        "type" => (($offer->updated_at ?? null) && ($offer->created_at ?? null) && $offer->updated_at !== $offer->created_at)
                            ? "İlan güncellendi"
                            : "İlan açıldı",
                        "label" => $companyTypeName,
                        "timestamp" => $actionTimestamp,
                    ];
                }

                if ($offer->worker === null) {
                    $totalOpenOffers++;
                    if (!isset($payrollSummaryMap[$offerData["currency"]])) {
                        $payrollSummaryMap[$offerData["currency"]] = [
                            "code" => $offerData["currency"],
                            "country_name" => $offerData["currency_country_name"],
                            "color" => $offerData["currency_color"],
                            "offer_count" => 0,
                            "total_salary" => 0.0,
                        ];
                    }

                    $payrollSummaryMap[$offerData["currency"]]["offer_count"]++;
                    $payrollSummaryMap[$offerData["currency"]]["total_salary"] += (float) $offerData["salary"];

                    $openOffers[(int)$offer->company] = $offerData;
                }
            }
        }

        foreach ($companyOperations as &$operation) {
            if (($operation['status'] ?? 'ready') === 'control_required') {
                $blockedFacilityCount++;
                continue;
            }

            $operation['open_positions'] = max(
                (int) ($operation['required_workers'] ?? 0) - (int) ($operation['assigned_workers'] ?? 0),
                0
            );
            $operation['offer_gap'] = max(
                (int) ($operation['open_positions'] ?? 0) - (int) ($operation['open_offer_count'] ?? 0),
                0
            );
            $totalVacantPositions += $operation['open_positions'];
            $totalOpenOfferPositions += (int) ($operation['open_offer_count'] ?? 0);
            $totalOfferGap += (int) ($operation['offer_gap'] ?? 0);

            if (($operation['input_required'] ?? 0) > 0 && ($operation['input_stock'] ?? 0) < $operation['input_required']) {
                $operation['status'] = 'input_missing';
                $blockedFacilityCount++;
            } elseif (($operation['required_workers'] ?? 0) > ($operation['assigned_workers'] ?? 0)) {
                $operation['status'] = 'workforce_missing';
                $blockedFacilityCount++;
            } else {
                $operation['status'] = 'ready';
            }
        }
        unset($operation);

        $priorityAction = null;
        $priorityRank = PHP_INT_MAX;
        foreach ($list as $companyRow) {
            $companyId = (int) ($companyRow['id'] ?? 0);
            $operation = $companyOperations[$companyId] ?? null;
            if (empty($operation)) {
                continue;
            }

            $companyType = $companyTypes[(int) ($companyRow['type'] ?? 0)] ?? [];
            $companyName = $companyType['display_name'] ?? ('Tesis #' . $companyId);
            $action = null;
            $actionRank = PHP_INT_MAX;

            if (($operation['status'] ?? '') === 'input_missing') {
                $action = ['key' => 'marketplace', 'label' => 'Eksik girdiyi markette ara', 'icon' => 'fa-store'];
                $actionRank = 1;
            } elseif (($operation['status'] ?? '') === 'workforce_missing' || ($operation['offer_gap'] ?? 0) > 0) {
                $action = ['key' => 'work_offers', 'label' => 'İş gücünü tamamla', 'icon' => 'fa-briefcase'];
                $actionRank = 2;
            } elseif (($operation['status'] ?? '') === 'control_required') {
                $action = ['key' => 'none', 'label' => 'Tesis verisini kontrol et', 'icon' => 'fa-triangle-exclamation'];
                $actionRank = 3;
            }

            if ($action !== null && $actionRank < $priorityRank) {
                $priorityAction = $action + ['company_name' => $companyName];
                $priorityRank = $actionRank;
            }
        }

        if ($priorityAction === null && empty($list)) {
            $priorityAction = [
                'key' => 'create_company',
                'label' => 'İlk tesisini kur',
                'icon' => 'fa-plus',
                'company_name' => '',
            ];
        }

        ksort($usedCurrencyCodes);
        ksort($currencyMap);
        $currencyList = array_values($currencyMap);
        ksort($payrollSummaryMap);

        $moneyModel = App::session()->getMoney();
        $moneyData = !empty($moneyModel) ? $moneyModel->toArray() : [];
        $currencyBalances = [];

        foreach ($currencyList as $currencyMeta) {
            $balanceKey = strtolower((string) ($currencyMeta["code"] ?? ""));
            $currencyCode = strtoupper((string) ($currencyMeta["code"] ?? ""));
            if ($currencyCode === "") {
                continue;
            }

            $currencyBalances[$currencyCode] = isset($moneyData[$balanceKey]) ? (float) $moneyData[$balanceKey] : 0.0;
        }

        foreach ($payrollSummaryMap as &$summary) {
            $balanceKey = strtolower((string) $summary["code"]);
            $balance = isset($moneyData[$balanceKey]) ? (float) $moneyData[$balanceKey] : 0.0;
            $summary["balance"] = $balance;
            $summary["is_sufficient"] = $balance >= (float) $summary["total_salary"];
            $summary["coverage_days"] = (float) $summary["total_salary"] > 0
                ? floor($balance / (float) $summary["total_salary"])
                : 0;
            if (!$summary["is_sufficient"]) {
                $payrollRiskCount++;
            }
        }
        unset($summary);

        $payrollSummary = array_values($payrollSummaryMap);
        usort($recentActions, function ($a, $b) {
            return strcmp((string)($b["timestamp"] ?? ""), (string)($a["timestamp"] ?? ""));
        });
        $recentActions = array_slice($recentActions, 0, 5);
        $firstOpenOffer = null;

        if (!empty($openOffers)) {
            $firstOpenOffer = reset($openOffers);
        }

        return $this->render('user/companies.html.twig', [
            "companies" => $list,
            "companyTypes" => $companyTypes,
            "companyOperations" => $companyOperations,
            "openOffers" => $openOffers,
            "defaultCountryId" => $defaultCountryId,
            "countryList" => $countryList,
            "currencyList" => $currencyList,
            "currencyBalances" => $currencyBalances,
            "payrollSummary" => $payrollSummary,
            "recentActions" => $recentActions,
            "firstOpenOffer" => $firstOpenOffer,
            "totalCompanies" => count($list),
            "totalOpenOffers" => $totalOpenOffers,
            "totalCapacity" => $totalCapacity,
            "totalWorkersRequired" => $totalWorkersRequired,
            "totalAssignedWorkers" => $totalAssignedWorkers,
            "totalVacantPositions" => $totalVacantPositions,
            "totalOpenOfferPositions" => $totalOpenOfferPositions,
            "totalOfferGap" => $totalOfferGap,
            "usedCurrencyCount" => count($usedCurrencyCodes),
            "blockedFacilityCount" => $blockedFacilityCount,
            "payrollRiskCount" => $payrollRiskCount,
            "priorityAction" => $priorityAction,
            "totalDailyPayroll" => array_reduce($payrollSummary, function ($carry, $item) {
                return $carry + (float) ($item["total_salary"] ?? 0);
            }, 0.0),
        ]);
    }

    public function showCreate()
    {
        $list = [];
        $availableQualities = array_fill_keys(self::CREATE_QUALITIES, true);
        foreach ($this->prepareCompanyTypes() as $company) {
            $company['qualities'] = array_intersect_key($company['qualities'] ?? [], $availableQualities);
            $list[$company["sector"]][] = $company;
        }

        return $this->render('user/create_company.html.twig', [
            "sectors" => $list,
        ]);
    }

    public function create()
    {
        $uid = (int) App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'company_create',
            'uid:' . $uid,
            3,
            1800,
            3600,
            'Kisa surede cok fazla sirket kurma denemesi yaptiniz. Lutfen sonra tekrar deneyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $id = isset($_POST["id"]) ? (int)$_POST["id"] : 0;
        $quality = isset($_POST["quality"]) ? (int)$_POST["quality"] : 0;

        if ($id < 1 || !in_array($quality, self::CREATE_QUALITIES, true)) {
            return ["error" => true, "message" => "Sistem Hatası: Geçersiz tesis veya kalite parametresi."];
        }

        try {
            $companyDetails = CompanyType::getInfo($id, $quality);
            if (empty($companyDetails)) {
                return ["error" => true, "message" => "Bu şirket modeli sistemde bulunamadı."];
            }
        } catch (\Exception $e) {
            return ["error" => true, "message" => "Veri Hatası: Şirket modeli yüklenemedi."];
        }

        DB::beginTransaction();
        try {
            if (DB::getSchemaBuilder()->hasTable('user_money')) {
                DB::table('user_money')
                    ->where('uid', $uid)
                    ->lockForUpdate()
                    ->first();
            }

            if (!App::user()->buy($companyDetails["price"], $companyDetails["currency"], Session::PURCHASE_TYPE_COMPANY)) {
                DB::rollBack();

                return [
                    "error" => true,
                    "message" => "Bakiyeniz yetersiz. Kurulum tamamlanamadı."
                ];
            }

            $created = CompanyModel::create([
                "uid" => App::user()->getUid(),
                "type" => $id,
                "quality" => $quality,
            ]);

            if ($created) {
                DB::commit();
                return ["success" => true, "message" => "Tesis inşaatı tamamlandı!"];
            }
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($e->getCode() === AppException::NO_ENOUGH_MONEY) {
                return ["error" => true, "message" => "Bakiyeniz yetersiz. Kurulum tamamlanamadı."];
            }

            return ["error" => true, "message" => "Tesis kurulumu tamamlanamadı. Lütfen tekrar deneyin."];
        }

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        return ["error" => true, "message" => "İnşaat sırasında bilinmeyen bir hata oluştu."];
    }

    public function createWorkOffer()
    {
        $uid = App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'company_offer_create',
            'uid:' . (int) $uid,
            10,
            600,
            900,
            'Is ilani olusturma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $companyId = isset($_POST["company_id"]) ? (int)$_POST["company_id"] : 0;
        $salary = isset($_POST["salary"]) ? (float)$_POST["salary"] : (isset($_POST["money"]) ? (float)$_POST["money"] : 0);
        $requiredSkill = isset($_POST["required_skill"]) ? (int)$_POST["required_skill"] : (isset($_POST["skill"]) ? (int)$_POST["skill"] : 0);
        $currency = isset($_POST["currency"]) ? strtoupper(trim(strip_tags($_POST["currency"]))) : "";

        if ($companyId < 1 || $salary <= 0 || $requiredSkill < 0 || $currency === "") {
            return ["error" => true, "message" => "İlan verileri geçersiz."];
        }

        $company = CompanyModel::where([
            "id" => $companyId,
            "uid" => $uid
        ])->first();

        if (empty($company)) {
            return ["error" => true, "message" => "Şirket bulunamadı."];
        }

        $existingOffer = DB::table('work_offers')
            ->where('company', $companyId)
            ->whereNull('worker')
            ->first();

        if (!empty($existingOffer)) {
            return ["error" => true, "message" => "Bu şirket için zaten aktif bir iş ilanı var."];
        }

        $countryByCurrency = DB::table('countries')
            ->select('id', 'name', 'currency', 'minimum_wage', 'color')
            ->whereRaw('UPPER(currency) = ?', [$currency])
            ->orderBy('id', 'asc')
            ->first();

        if (empty($countryByCurrency)) {
            return ["error" => true, "message" => "Geçersiz para birimi seçildi."];
        }

        $minimumWage = (float)($countryByCurrency->minimum_wage ?? 0);

        if ($salary < $minimumWage) {
            return [
                "error" => true,
                "message" => "Günlük maaş minimum ücretin altında olamaz. Minimum: " . number_format($minimumWage, 2, '.', '') . " " . $currency
            ];
        }

        $currentUser = User::find($uid);
        $countryId = (!empty($currentUser) && !empty($currentUser->location) && !empty($currentUser->location->country_id))
            ? (int)$currentUser->location->country_id
            : (int)$countryByCurrency->id;

        try {
            DB::table('work_offers')->insert([
                "company" => $companyId,
                "salary" => $salary,
                "currency" => $currency,
                "country" => $countryId,
                "required_skill" => $requiredSkill,
                "worker" => null,
                "title" => null,
                "description" => null,
                "last_work" => null,
                "created_at" => date('Y-m-d H:i:s'),
                "updated_at" => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            return ["error" => true, "message" => "İş ilanı oluşturulamadı: " . $e->getMessage()];
        }

        return [
            "success" => true,
            "message" => "İş ilanı başarıyla yayınlandı."
        ];
    }

    public function updateWorkOffer()
    {
        $uid = App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'company_offer_update',
            'uid:' . (int) $uid,
            20,
            600,
            900,
            'Is ilani guncelleme denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $companyId = isset($_POST["company_id"]) ? (int)$_POST["company_id"] : 0;
        $salary = isset($_POST["salary"]) ? (float)$_POST["salary"] : (isset($_POST["money"]) ? (float)$_POST["money"] : 0);
        $requiredSkill = isset($_POST["required_skill"]) ? (int)$_POST["required_skill"] : (isset($_POST["skill"]) ? (int)$_POST["skill"] : 0);
        $currency = isset($_POST["currency"]) ? strtoupper(trim(strip_tags($_POST["currency"]))) : "";

        if ($companyId < 1 || $salary <= 0 || $requiredSkill < 0 || $currency === "") {
            return ["error" => true, "message" => "Güncellenecek ilan verileri geçersiz."];
        }

        $company = CompanyModel::where([
            "id" => $companyId,
            "uid" => $uid
        ])->first();

        if (empty($company)) {
            return ["error" => true, "message" => "Şirket bulunamadı."];
        }

        $countryByCurrency = DB::table('countries')
            ->select('id', 'name', 'currency', 'minimum_wage', 'color')
            ->whereRaw('UPPER(currency) = ?', [$currency])
            ->orderBy('id', 'asc')
            ->first();

        if (empty($countryByCurrency)) {
            return ["error" => true, "message" => "Geçersiz para birimi seçildi."];
        }

        $minimumWage = (float)($countryByCurrency->minimum_wage ?? 0);

        if ($salary < $minimumWage) {
            return [
                "error" => true,
                "message" => "Günlük maaş minimum ücretin altında olamaz. Minimum: " . number_format($minimumWage, 2, '.', '') . " " . $currency
            ];
        }

        $offer = DB::table('work_offers')
            ->where('company', $companyId)
            ->whereNull('worker')
            ->first();

        if (empty($offer)) {
            return ["error" => true, "message" => "Güncellenecek aktif iş ilanı bulunamadı."];
        }

        try {
            DB::table('work_offers')
                ->where('id', $offer->id)
                ->update([
                    "salary" => $salary,
                    "currency" => $currency,
                    "required_skill" => $requiredSkill,
                    "updated_at" => date('Y-m-d H:i:s'),
                ]);
        } catch (\Exception $e) {
            return ["error" => true, "message" => "İş ilanı güncellenemedi: " . $e->getMessage()];
        }

        return [
            "success" => true,
            "message" => "İş ilanı başarıyla güncellendi."
        ];
    }

    public function cancelWorkOffer()
    {
        $uid = App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'company_offer_cancel',
            'uid:' . (int) $uid,
            12,
            600,
            900,
            'Is ilani kaldirma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $companyId = isset($_POST["company_id"]) ? (int)$_POST["company_id"] : 0;

        if ($companyId < 1) {
            return ["error" => true, "message" => "Geçersiz şirket."];
        }

        $company = CompanyModel::where([
            "id" => $companyId,
            "uid" => $uid
        ])->first();

        if (empty($company)) {
            return ["error" => true, "message" => "Şirket bulunamadı."];
        }

        $offer = DB::table('work_offers')
            ->where('company', $companyId)
            ->whereNull('worker')
            ->first();

        if (empty($offer)) {
            return ["error" => true, "message" => "Aktif iş ilanı bulunamadı."];
        }

        try {
            DB::table('work_offers')
                ->where('company', $companyId)
                ->whereNull('worker')
                ->delete();
        } catch (\Exception $e) {
            return ["error" => true, "message" => "İş ilanı kaldırılamadı: " . $e->getMessage()];
        }

        return [
            "success" => true,
            "message" => "İş ilanı kaldırıldı."
        ];
    }

    public function workAsManager()
    {
        return [
            "error" => true,
            "message" => "Toplu yönetici üretimi kaldırıldı. Üretim artık işçi günlük mesai yaptığında otomatik gerçekleşir."
        ];
    }

    public function createWorkOfferSafe()
    {
        $uid = (int) App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'company_offer_create',
            'uid:' . $uid,
            10,
            600,
            900,
            'Is ilani olusturma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $companyId = isset($_POST["company_id"]) ? (int) $_POST["company_id"] : 0;
        $salary = isset($_POST["salary"]) ? round((float) $_POST["salary"], 2) : (isset($_POST["money"]) ? round((float) $_POST["money"], 2) : 0);
        $requiredSkill = isset($_POST["required_skill"]) ? (int) $_POST["required_skill"] : (isset($_POST["skill"]) ? (int) $_POST["skill"] : 0);
        $currency = isset($_POST["currency"]) ? strtoupper(trim(strip_tags($_POST["currency"]))) : "";

        if ($companyId < 1 || $salary <= 0 || $salary > 1000000 || $requiredSkill < 0 || $requiredSkill > 100 || $currency === "") {
            return ["error" => true, "message" => "Ilan verileri gecersiz."];
        }

        DB::beginTransaction();
        try {
            $company = DB::table('companies')
                ->where('id', $companyId)
                ->where('uid', $uid)
                ->lockForUpdate()
                ->first();

            if (empty($company)) {
                DB::rollBack();
                return ["error" => true, "message" => "Sirket bulunamadi."];
            }

            $existingOffer = DB::table('work_offers')
                ->where('company', $companyId)
                ->whereNull('worker')
                ->lockForUpdate()
                ->first();

            if (!empty($existingOffer)) {
                DB::rollBack();
                return ["error" => true, "message" => "Bu sirket icin zaten aktif bir is ilani var."];
            }

            $countryByCurrency = DB::table('countries')
                ->select('id', 'minimum_wage')
                ->whereRaw('UPPER(currency) = ?', [$currency])
                ->orderBy('id', 'asc')
                ->first();

            if (empty($countryByCurrency)) {
                DB::rollBack();
                return ["error" => true, "message" => "Gecersiz para birimi secildi."];
            }

            $minimumWage = (float) ($countryByCurrency->minimum_wage ?? 0);
            if ($salary < $minimumWage) {
                DB::rollBack();
                return [
                    "error" => true,
                    "message" => "Gunluk maas minimum ucretin altinda olamaz. Minimum: " . number_format($minimumWage, 2, '.', '') . " " . $currency
                ];
            }

            $currentUser = User::find($uid);
            $countryId = (!empty($currentUser) && !empty($currentUser->location) && !empty($currentUser->location->country_id))
                ? (int) $currentUser->location->country_id
                : (int) $countryByCurrency->id;

            DB::table('work_offers')->insert([
                "company" => $companyId,
                "salary" => $salary,
                "currency" => $currency,
                "country" => $countryId,
                "required_skill" => $requiredSkill,
                "worker" => null,
                "title" => null,
                "description" => null,
                "last_work" => null,
                "created_at" => date('Y-m-d H:i:s'),
                "updated_at" => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return ["error" => true, "message" => "Is ilani olusturulamadi."];
        }

        return [
            "success" => true,
            "message" => "Is ilani basariyla yayinlandi."
        ];
    }

    public function updateWorkOfferSafe()
    {
        $uid = (int) App::user()->getUid();
        $blocked = ActionRateLimiter::throttle(
            'company_offer_update',
            'uid:' . $uid,
            20,
            600,
            900,
            'Is ilani guncelleme denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $companyId = isset($_POST["company_id"]) ? (int) $_POST["company_id"] : 0;
        $salary = isset($_POST["salary"]) ? round((float) $_POST["salary"], 2) : (isset($_POST["money"]) ? round((float) $_POST["money"], 2) : 0);
        $requiredSkill = isset($_POST["required_skill"]) ? (int) $_POST["required_skill"] : (isset($_POST["skill"]) ? (int) $_POST["skill"] : 0);
        $currency = isset($_POST["currency"]) ? strtoupper(trim(strip_tags($_POST["currency"]))) : "";

        if ($companyId < 1 || $salary <= 0 || $salary > 1000000 || $requiredSkill < 0 || $requiredSkill > 100 || $currency === "") {
            return ["error" => true, "message" => "Guncellenecek ilan verileri gecersiz."];
        }

        DB::beginTransaction();
        try {
            $company = DB::table('companies')
                ->where('id', $companyId)
                ->where('uid', $uid)
                ->lockForUpdate()
                ->first();

            if (empty($company)) {
                DB::rollBack();
                return ["error" => true, "message" => "Sirket bulunamadi."];
            }

            $countryByCurrency = DB::table('countries')
                ->select('id', 'minimum_wage')
                ->whereRaw('UPPER(currency) = ?', [$currency])
                ->orderBy('id', 'asc')
                ->first();

            if (empty($countryByCurrency)) {
                DB::rollBack();
                return ["error" => true, "message" => "Gecersiz para birimi secildi."];
            }

            $minimumWage = (float) ($countryByCurrency->minimum_wage ?? 0);
            if ($salary < $minimumWage) {
                DB::rollBack();
                return [
                    "error" => true,
                    "message" => "Gunluk maas minimum ucretin altinda olamaz. Minimum: " . number_format($minimumWage, 2, '.', '') . " " . $currency
                ];
            }

            $offer = DB::table('work_offers')
                ->where('company', $companyId)
                ->whereNull('worker')
                ->lockForUpdate()
                ->first();

            if (empty($offer)) {
                DB::rollBack();
                return ["error" => true, "message" => "Guncellenecek aktif is ilani bulunamadi."];
            }

            DB::table('work_offers')
                ->where('id', $offer->id)
                ->update([
                    "salary" => $salary,
                    "currency" => $currency,
                    "required_skill" => $requiredSkill,
                    "updated_at" => date('Y-m-d H:i:s'),
                ]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return ["error" => true, "message" => "Is ilani guncellenemedi."];
        }

        return [
            "success" => true,
            "message" => "Is ilani basariyla guncellendi."
        ];
    }
}
