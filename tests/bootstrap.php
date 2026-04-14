<?php

$appBasePath = dirname(__DIR__);
$_ENV['APP_BASE_PATH'] = $appBasePath;
$_SERVER['APP_BASE_PATH'] = $appBasePath;
putenv("APP_BASE_PATH={$appBasePath}");

require __DIR__ . '/../vendor/autoload.php';
