<?php

namespace App\Config;

interface MtfConfigProviderInterface
{
    /** Retourne la configuration complète (dont validation.context & validation.execution). */
    public function getConfig(): array;
}

