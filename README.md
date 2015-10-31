# Sitemap generator
This is the small library, which can help you to generate the XML sitemap.

Usage is very simple. Here is the example:

```php
$client = new GuzzleHttp\Client();
$parser = new Symfony\Component\DomCrawler\Crawler();
$storage = new vedebel\sitemap\BasicLinksStorage();

$generator = new vedebel\sitemap\Sitemap($parser, $storage, $url, ['limit' => 200, 'debug' => 1]);
$generator->setLoader($client);
$generator->crawl();
$generator->saveXml('sitemap.xml');
```

If you want to monitor process while site is crawling, you can add callback, which will be called every n scanned links. Default is 10.
```php
$generator->setCallback(function(array $scanned, array $added, array $queue) {
    echo "This is message form callback.\nScanned: "
        . count($scanned) . "\nAdded: " . count($added) . "\nQueue: " . count($queue) . "\n";
}, 10);
```
