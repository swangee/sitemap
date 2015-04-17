<?php
ini_set('max_execution_time', 60 * 60);
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../vendor/autoload.php';

require_once 'di.php';
require_once 'routes.php';

$app = $container['app'];

$app->run();