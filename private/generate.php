<?php

require_once dirname(__FILE__) . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$options = getopt('', [
  'url:',
  'dest:',
  'limit::',
  'debug::',
  'rescan::'
]);

if (!$options['url'] || !$options['dest']) {
  exit('Url or File Name is not specified');
}

$url = $options['url'];
$curl = new Curl\Curl;
$curl->setUserAgent('Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36');
$limit = isset($options['limit']) ? $options['limit'] : 100;
$dest = $options['dest'];
$dest = dirname(__FILE__) . '/' . $options['dest'];
$debug = isset($options['debug']);
$parser = new \PHPHtmlParser\Dom();
$storage = new \Vedebel\Sitemap\SQLiteLinksStorage();

$generator = new Vedebel\Sitemap\Sitemap($parser, $storage, $url, ['limit' => $limit]);
$generator->debug($debug);
$generator->setLoader($curl);
if (isset($options['rescan'])) {
  $generator->rescan();
} else {
  $generator->crawl();
}
$generator->saveXml($dest);