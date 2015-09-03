<?php

require_once 'vendor/autoload.php';

error_reporting(E_ALL);
ini_set("display_errors", 1);

$start = microtime(true);

echo "====================Script started at " . date("H:i:s") . "============================\n\n";
$options = getopt('', [
    'url:',
    'dest:',
    'limit::',
    'debug::',
    'rescan::',
]);

if (empty($options['url']) || empty($options['dest'])) {
    exit('Url or File Name is not specified');
}

$url = $options['url'];

$limit = isset($options['limit']) ? $options['limit'] : 100;

$dest = $options['dest'];
//$dest = dirname(__FILE__) . '/' . $options['dest'];

$debug = isset($options['debug']);

$parser = new Symfony\Component\DomCrawler\Crawler();

$storage = new vedebel\sitemap\BasicLinksStorage();

$generator = new vedebel\sitemap\Sitemap($parser, $storage, $url, ['limit' => $limit, 'debug' => 1]);
$generator->setLoader(new GuzzleHttp\Client());
$generator->setCallback(function(array $scanned, array $added, array $queue) {
    echo "This is message form callback.\nScanned: "
        . count($scanned) . "\nAdded: " . count($added) . "\nQueue: " . count($queue) . "\n";
});
if (isset($options['rescan'])) {
    $generator->rescan();
} else {
    $generator->crawl();
}
$generator->saveXml($dest);

echo "====================Script ended at " . date("H:i:s") . "============================\n";
echo "- Execution time: " . round((microtime(true) - $start) / 60, 2) . " minutes\n";
echo "- Url: {$url}\n";
echo "- Limit: {$limit}\n";