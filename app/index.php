<?php
ini_set('max_execution_time', 60 * 60);
require_once '../vendor/autoload.php';

require_once 'di.php';
require_once 'routes.php';

$app = $container['app'];

$app->run();