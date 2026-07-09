<?php

namespace App\System;

class LangManager
{
    private $app;
    private $session;
    private $locale;

    private $supportedLocales = [
        'tr' => [
            'name' => 'Turkce',
            'native' => 'Turkce',
            'flag' => 'TR',
        ],
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'flag' => 'EN',
        ],
    ];

    public function __construct($app, Session $session)
    {
        $this->app = $app;
        $this->session = $session;
        $this->locale = $this->resolveLocale();
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
        $locale = $this->normalizeLocale($locale);
        $this->locale = $locale;
        $this->session->set('locale', $locale);
        $this->session->setUserField('language', $locale);

        return $this->locale;
    }

    public function getAvailableLocales()
    {
        return $this->supportedLocales;
    }

    public function isSupported($locale)
    {
        return isset($this->supportedLocales[$this->normalizeLocale($locale)]);
    }

    private function resolveLocale()
    {
        $user = $this->session->getUser();
        $userLocale = is_array($user) ? ($user['language'] ?? '') : '';
        if ($this->isSupported($userLocale)) {
            return $this->normalizeLocale($userLocale);
        }

        $sessionLocale = $this->session->get('locale');
        if ($this->isSupported($sessionLocale)) {
            return $this->normalizeLocale($sessionLocale);
        }

        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach ($this->parseAcceptLanguage($header) as $candidate) {
            if ($this->isSupported($candidate)) {
                return $this->normalizeLocale($candidate);
            }
        }

        return 'tr';
    }

    private function parseAcceptLanguage($header)
    {
        $locales = [];
        foreach (explode(',', strtolower($header)) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $locale = explode(';', $part, 2)[0];
            $locales[] = $this->normalizeLocale($locale);
        }

        return array_values(array_unique(array_filter($locales)));
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
}
