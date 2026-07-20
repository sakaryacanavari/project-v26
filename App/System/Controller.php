<?php

/**
 * Abstract controller associated a user request
 * @package App\System
 */
namespace App\System;

use Psr\Http\Message\ServerRequestInterface;

abstract class Controller
{
    /**
     * @var \Slim\App
     */
    public $app = null;

    /**
     * @var \Slim\Container
     */
    public $container = null;

    /**
     * Shortcut to the request functionalities
     *
     * @var ServerRequestInterface
     */
    public $req = null;

    // Shortcut to know whether I'm logged or not.
    public $isLogged = null;

    /**
     * @var \Psr\Http\Message\ResponseInterface
     */
    public $response = null;

    public function __construct($app = null, $response = null)
    {

        if (empty($app)) {
            $app = App::getInstance();
        }

        $this->app = $app;
        $this->container = $app->getContainer();
        $this->response = $response;

        $this->req = $this->container->get("request");

        $this->isLogged = $this->container->get('session')->isLogged();
    }

    /**
     * Renders a template
     * @param string $file
     * @param array $vars
     * @return mixed
     */
    public function render ($file, $vars = [])
    {
        $vars["controller"] = str_replace('App\\Controllers\\', "", get_class($this));
        $vars["csrf_token"] = $this->container->get('session')->getCsrfToken();
        $vars["lang"] = App::getLang();
        $vars["availableLocales"] = App::container()->get('langManager')->getAvailableLocales();

        if (App::session()->isLogged())
        {
            $user = App::session()->getUser();
            $money = App::session()->getMoney();

            $user["money"] = $money ? $money->toArray() : [];
            unset($user["money"]["uid"], $user["money"]["created_at"], $user["money"]["updated_at"]);

            try {
                $user["location"] = App::session()->getLocation();
            } catch (\Throwable $e) {
                $user["location"] = [];
            }

            $bread = null;
            $weapon = null;
            $hudItems = \App\Models\UserItem::where('uid', (int)($user["id"] ?? 0))
                ->whereIn('item', [4, 5])
                ->get(['item', 'quality', 'quantity']);

            foreach ($hudItems as $hudItem) {
                if ((int) $hudItem->item === 4 && (int) $hudItem->quality === 1) {
                    $bread = $hudItem;
                } elseif ((int) $hudItem->item === 5 && (int) $hudItem->quality === 5) {
                    $weapon = $hudItem;
                }
            }

            $user["inventory"] = [
                "bread" => (int)($bread->quantity ?? 0),
                "weapons" => (int)($weapon->quantity ?? 0),
            ];

            $user["xp"] = (int)($user["xp"] ?? ($user["exp"] ?? 0));
            $user["energy_display"] = (int)($user["energy"] ?? ($user["health"] ?? ($user["stamina"] ?? 0)));
            $user["iq"] = (int)($user["iq"] ?? 100);
            $user["medals"] = (int)($user["medals"] ?? 0);
            $vars["my"] = $user;
            $vars["sidebarHud"] = $this->buildSidebarHud($user);
            $vars["gameExperienceSettings"] = GameExperience::getPreferences((int)($user["id"] ?? 0));
        }

        return $this->container->get("view")->render($this->response, $file, $vars);
    }

    private function buildSidebarHud(array $user)
    {
        $uid = (int)($user["id"] ?? 0);
        $location = is_array($user["location"] ?? null) ? $user["location"] : [];
        $country = is_array($location["country"] ?? null) ? $location["country"] : [];
        $money = is_array($user["money"] ?? null) ? $user["money"] : [];
        $inventory = is_array($user["inventory"] ?? null) ? $user["inventory"] : [];

        $energy = max(0, (int)($user["energy_display"] ?? 0));
        $energyMax = 100;
        $energyPercent = min(100, $energyMax > 0 ? (int)round(($energy / $energyMax) * 100) : 0);

        $economicSkill = max(1, (int)($user["economic_skill"] ?? 1));
        $economicXp = max(0, (int)($user["economic_xp"] ?? 0));
        $economicRequiredXp = max(1, $economicSkill * 3);
        $economicPercent = min(100, (int)round(($economicXp / $economicRequiredXp) * 100));

        $job = null;
        $jobStatus = [
            "hasJob" => false,
            "workedToday" => false,
            "title" => \t('base.sidebar.active_contract_missing'),
            "company" => \t('base.sidebar.company_info_missing'),
            "salary" => 0.0,
            "currency" => "",
            "status" => \t('base.sidebar.no_active_job'),
        ];

        try {
            $job = App::session()->getJob();
        } catch (\Throwable $e) {
            $job = null;
        }

        if ($job) {
            $jobStatus["hasJob"] = true;
            $jobStatus["workedToday"] = method_exists($job, "hasWorkedToday") ? (bool)$job->hasWorkedToday() : false;
            $jobStatus["title"] = !empty($job->title) ? (string)$job->title : \t('base.sidebar.work_status');
            $jobStatus["salary"] = round((float)($job->salary ?? 0), 2);
            $jobStatus["currency"] = strtoupper((string)($job->currency ?? ($country["currency"] ?? "")));
            $jobStatus["status"] = $jobStatus["workedToday"] ? \t('base.sidebar.shift_completed') : \t('base.sidebar.shift_waiting');

            try {
                $company = \Illuminate\Database\Capsule\Manager::table("companies")
                    ->where("id", (int)($job->company ?? 0))
                    ->first(["type", "quality"]);
                if ($company && class_exists("\\App\\Models\\CompanyType")) {
                    $types = \App\Models\CompanyType::$types;
                    $typeId = (int)($company->type ?? 0);
                    $jobStatus["company"] = (string)($types[$typeId]["name"] ?? $jobStatus["company"]);
                }
            } catch (\Throwable $e) {
                $jobStatus["company"] = \t('base.sidebar.company_info_missing');
            }
        }

        $trainedToday = false;
        try {
            if ($uid > 0 && class_exists("\\App\\Models\\UserGym")) {
                $trainedToday = \App\Models\UserGym::hasTrainingTodayForUser($uid);
            }
        } catch (\Throwable $e) {
            $trainedToday = false;
        }

        $dailyCompleted = ($trainedToday ? 1 : 0) + (!empty($jobStatus["workedToday"]) ? 1 : 0);

        $currency = strtolower((string)($country["currency"] ?? ""));
        $localBalance = $currency !== "" && array_key_exists($currency, $money) ? (float)$money[$currency] : 0.0;

        return [
            "user" => [
                "id" => $uid,
                "nick" => (string)($user["nick"] ?? \t('base.sidebar.citizen')),
                "avatar" => (string)($user["avatar"] ?? ""),
                "level" => max(1, (int)($user["level"] ?? 1)),
                "xp" => max(0, (int)($user["xp"] ?? 0)),
                "strength" => (float)($user["strength"] ?? 0),
                "energy" => $energy,
                "energyPercent" => $energyPercent,
                "economicSkill" => $economicSkill,
                "economicXp" => $economicXp,
                "economicRequiredXp" => $economicRequiredXp,
                "economicPercent" => $economicPercent,
            ],
            "daily" => [
                "trained" => $trainedToday,
                "worked" => !empty($jobStatus["workedToday"]),
                "completed" => $dailyCompleted,
                "total" => 2,
                "percent" => (int)round(($dailyCompleted / 2) * 100),
            ],
            "location" => [
                "region" => (string)($location["name"] ?? ""),
                "country" => (string)($country["name"] ?? ""),
                "countryId" => (int)($country["id"] ?? 0),
                "currency" => $currency,
            ],
            "job" => $jobStatus,
            "inventory" => [
                "bread" => (int)($inventory["bread"] ?? 0),
                "weapons" => (int)($inventory["weapons"] ?? 0),
            ],
            "money" => [
                "gold" => (float)($money["gold"] ?? 0),
                "local" => $localBalance,
                "localCurrency" => strtoupper($currency),
            ],
        ];
    }

    protected function getCsrfToken()
    {
        return $this->container->get('session')->getCsrfToken();
    }

    protected function validateCsrf($token)
    {
        return $this->container->get('session')->validateCsrfToken($token);
    }

    /**
     * Executes a controller method (check routes.php)
     * @return mixed
     * @throws \Exception
     */
    private function run()
    {
        $numargs = func_num_args();
        $args = func_get_args();

        if ($numargs == 0) {
            throw new \Exception("The run() method requires at least one parameter, which is the method name to execute.");
        }

        $method = array_shift($args);
        return call_user_func_array(array($this, $method), $args);
    }

    /**
     * Execute a method from a controller, and display its output
     *
     * NOTE: A possible use of this wrapper function could be to determine if the request is a regular HTTP one or an AJAX one, and pass this variable to the view.
     *
     */
    public function exec(): \Psr\Http\Message\ResponseInterface
    {
        $result = call_user_func_array(array($this, "run"), func_get_args());
        return $result instanceof \Psr\Http\Message\ResponseInterface ? $result : $this->response;
    }

    /**
     * Print the return value of the controller function as JSON (and previously set the content-type header)
     */
    public function json()
    {
        $result = call_user_func_array(array($this, "run"), func_get_args());

        if (Utils::isAPIcall() && gettype($result) != "object")
        {
            $response = new \stdClass();
            $response->error = 0;
            $response->result = $result;
            $result = $response;
        }

        Utils::jsonResponse($result);
    }
}
