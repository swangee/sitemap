<?php
$container = new Pimple();
$container['app'] = function() {
  return new \Slim\Slim();
};