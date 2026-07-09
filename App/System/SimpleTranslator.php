<?php

namespace App\System;

class SimpleTranslator
{
    private static $sharedCatalogues = [];
    private $langManager;
    private $catalogues = [];

    public function __construct(LangManager $langManager)
    {
        $this->langManager = $langManager;
    }

    public function translate($key, array $replace = [], $locale = null)
    {
        $locale = $locale ?: $this->langManager->getLocale();
        $catalogue = $this->loadCatalogue($locale);
        $message = $catalogue[$key] ?? null;

        if ($message === null && $locale !== 'tr') {
            $fallbackCatalogue = $this->loadCatalogue('tr');
            $message = $fallbackCatalogue[$key] ?? null;
        }

        if ($message === null) {
            $message = $key;
        }

        return $this->interpolate($message, $replace);
    }

    public function translatePlural($singular, $plural, $number, array $replace = [], $locale = null)
    {
        $key = ((int) $number === 1) ? $singular : $plural;
        $replace['count'] = $number;

        return $this->translate($key, $replace, $locale);
    }

    private function loadCatalogue($locale)
    {
        $locale = $this->normalizeLocale($locale);

        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }

        if (isset(self::$sharedCatalogues[$locale])) {
            $this->catalogues[$locale] = self::$sharedCatalogues[$locale];
            return $this->catalogues[$locale];
        }

        $path = APP_ROOT . 'lang/' . $locale . '.php';
        if (!is_file($path)) {
            $locale = 'tr';
            $path = APP_ROOT . 'lang/tr.php';
        }

        $catalogue = require $path;
        if (!is_array($catalogue)) {
            $catalogue = [];
        }

        self::$sharedCatalogues[$locale] = $catalogue;
        $this->catalogues[$locale] = $catalogue;

        return $catalogue;
    }

    private function normalizeLocale($locale)
    {
        $locale = strtolower(trim((string) $locale));
        if ($locale === '') {
            return 'tr';
        }

        if (strpos($locale, '-') !== false) {
            $locale = explode('-', $locale, 2)[0];
        }

        if (strpos($locale, '_') !== false) {
            $locale = explode('_', $locale, 2)[0];
        }

        return $locale;
    }

    private function interpolate($message, array $replace)
    {
        if (empty($replace)) {
            return $message;
        }

        $replacements = [];
        foreach ($replace as $key => $value) {
            $normalizedKey = (string) $key;
            if ($normalizedKey === '') {
                continue;
            }

            $replacements[$normalizedKey[0] === ':' ? $normalizedKey : ':' . $normalizedKey] = $value;
        }

        return strtr($message, $replacements);
    }
}
