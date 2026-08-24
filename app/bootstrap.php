<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require APP_ROOT . '/config.php';

date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/diagnostics.php';
require_once __DIR__ . '/layout.php';
