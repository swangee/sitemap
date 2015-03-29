<?php
require_once '../vendor/autoload.php';

require_once 'di.php';
require_once 'routes.php';

$app = $container['app'];

session_cache_limiter(false);
session_start();

$app->run();