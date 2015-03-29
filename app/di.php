<?php
$container = new Pimple\Container();
$container['app'] = function() {
  return new \Slim\Slim();
};
$container['redis'] = function() {
  return new Predis\Client([
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
  ]);
};