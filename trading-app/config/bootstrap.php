<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!isset($_SERVER['APP_ENV'])) {
    if (is_readable(dirname(__DIR__) . '/.env')) {
        (new Dotenv())->usePutenv()->loadEnv(dirname(__DIR__) . '/.env');
    }
}

