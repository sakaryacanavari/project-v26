<?php

namespace App\System;

class I18nService
{
    private $translator;

    public function __construct(SimpleTranslator $translator)
    {
        $this->translator = $translator;
    }

    public function getTranslator()
    {
        return $this->translator;
    }
}
