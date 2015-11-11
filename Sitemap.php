<?php
namespace vedebel\sitemap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use vedebel\sitemap\crawlers\CrawlerInterface;
use vedebel\sitemap\storages\LinksStorage;

/**
 * Class Sitemap
 * @package vedebel\sitemap
 */
class Sitemap
{
    /**
     * @var string
     */
    private $url;

    /**
     * @var bool
     */
    private $async;

    /**
     * @var string
     */
    private $host;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $query;

    /**
     * @var string
     */
    private $protocol;
    /**
     * @var string
     */
    private $fragment;

    /**
     * @var array
     */
    private $queue;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @var int
     */
    private $callbackFrequency;

    /**
     * @var string
     */
    private $logFile;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var int
     */
    private $debugMode;

    /**
     * @var int
     */
    private $limit;

    /**
     * @var array
     */
    private $scanned;

    /**
     * @var array
     */
    private $lastUrlData;

    /**
     * @var array
     */
    private $errors;

    /**
     * @var int
     */
    private $maxDepth;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var float
     */
    private $timeout;

    /**
     * @var int
     */
    private $threadsLimit;

    /**
     * @var string
     */
    private $userAgent;

    /**
     * @var int
     */
    private $sleepTimeout;

    /**
     * @var CrawlerInterface
     */
    private $parser;

    /**
     * @var LinksStorage
     */
    private $storage;

    /**
     * @var array
     */
    private $excludeExtension;

    /**
     * @var array
     */
    private $excludePatterns;

    /**
     * @var int
     */
    private $fileLinksLimit;

    /**
     * @param CrawlerInterface $parser
     * @param LinksStorage $storage
     * @param $url
     * @param array $options
     */
    public function __construct(CrawlerInterface $parser, LinksStorage $storage, $url, array $options = [])
    {
        $parsed = parse_url($url);

        if (isset($parsed['scheme'])) {
            $this->protocol = $parsed['scheme'];
        }

        if (isset($parsed['host'])) {
            $this->host = $parsed['host'];
        }

        if (isset($parsed['path'])) {
            $this->path = ltrim($parsed['path'], '/');
        }

        if (isset($parsed['query'])) {
            $this->query = $parsed['query'];
        }

        if (isset($parsed['fragment'])) {
            $this->fragment = $parsed['fragment'];
        }

        $this->url = trim($url, '/');
        $this->queue = [];
        $this->async = (!empty($options['async']) ? (bool) $options['async'] : false);
        $this->limit = (!empty($options['limit']) ? (int) $options['limit'] : 1000);
        $this->timeout = (!empty($options['timeout']) ? (int) $options['timeout'] : 20);
        $this->threadsLimit = (!empty($options['threadsLimit']) ? (int) $options['threadsLimit'] : 5);
        $this->sleepTimeout = 0;
        $this->errors = [];
        $this->parser = $parser;
        $this->storage = $storage;
        $this->scanned = [];
        $this->lastUrlData = [];
        $this->excludePatterns = [];
        $this->fileLinksLimit = 50000;
        $this->excludeExtension = ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt"];
        $this->maxDepth = (!empty($options['depth']) ? $options['depth'] : 3);
        $this->userAgent = "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.157 Safari/537.36";

        $this->debug = (!empty($options['debug']) ? (bool) $options['debug'] : false);
        $this->debugMode = (!empty($options['debugMode']) ? (int) $options['debugMode'] : 2);

        $this->logFile = (!empty($options['logDir']) ? rtrim($options['logDir'], '/') : dirname(__FILE__)) . '/log.txt';
        if (2 === $this->debugMode && !is_writable($this->logFile)) {
            $this->debugMode = 1;
            $this->log('Can\'t create log file');
        }
    }

    /**
     * @param Client $client
     */
    public function setLoader(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * @param $limit
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;
    }

    /**
     * @param string $useAgent
     */
    public function setUserAgent($useAgent)
    {
        $this->userAgent = $useAgent;
    }

    /**
     * @param callable $callback
     * @param int $frequency
     */
    public function setCallback($callback, $frequency = 10)
    {
        if (is_callable($callback)) {
            $this->callback = $callback;
            $this->callbackFrequency = $frequency;
        }
    }

    /**
     * @param $mode
     */
    public function debug($mode)
    {
        $this->debug = $mode;
    }

    /**
     *
     */
    public function crawl()
    {
        if (!$this->storage->hasScan($this->url)) {
            $this->log('Start crawling site');
            if (empty($this->url)) {
                throw new \RuntimeException('Root Url cannot be empty');
            }
            $this->loadRobots();
            try {
                $this->queue[] = $this->prepare($this->url);

                do {
                    $queueLength = count($this->queue);
                    $threads = ($queueLength < $this->threadsLimit) ? $queueLength : $this->threadsLimit;
                    $this->crawlPages($threads);
                } while ($queueLength);

                $this->log("Queue is empty");
                $this->executeCallback(true);
            } catch (\Exception $e) {
                $this->errors[] = [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'exception' => get_class($e)
                ];
                var_dump($this->errors);
            }
        }
    }

    /**
     * @param $threads
     */
    private function crawlPages($threads)
    {
        $promises = [];
        for ($i = 0; $threads < count($promises) || $i < $threads; $i++) {

            if (!($url = array_shift($this->queue))) {
                break;
            }
            if (isset($this->scanned[$url])) {
                continue;
            }
            $this->scanned[$url] = true;
            if ($this->getUrlDepth($url) > $this->maxDepth) {
                continue;
            }
            $this->log('Start scan page ' . $url, 1);
            $promise = $this->loadPage($url, true);


            $promise->then(function(ResponseInterface $response) use ($url) {
                if ($lastModified = $response->getHeaderLine("Last-Modified")) {
                    $lastModified = \DateTime::createFromFormat("D, d M Y H:i:s O", $lastModified);
                    $lastModified = $lastModified ? $lastModified->format('Y-m-d\TH:i:sP') : null;
                } else {
                    $lastModified = null;
                }

                if (200 !== (int)$response->getStatusCode()) {
                    $this->log('Error! Url: ' . $url . ' Status: ' . $response->getStatusCode());
                    return false;
                }

                $linksAmount = $this->storage->countLinks($this->url);

                if ($this->limit && $linksAmount >= $this->limit) {
                    $this->queue = [];
                    return false;
                }

                $result = $this->parser->load($response->getBody());

                $pageInfo = [
                    'canonical' => $result['meta']['canonical'],
                    'metaRobots' => $result['meta']['robots'],

                    "status" => $response->getStatusCode(),
                    "lastModified" => $lastModified
                ];

                if (false !== strpos($pageInfo['metaRobots'], 'noindex')) {
                    $this->log("Page has meta tag noindex\n", 2);
                    return false;
                }
                $this->storage->addLink($this->url, $url, $pageInfo);
                $this->log('Link ' . $url . ' added', 2);
                if (false !== strpos($pageInfo['metaRobots'], 'nofollow')) {
                    $this->log("Page has meta tag nofollow\n", 2);
                    return false;
                }
                $links = $this->getCorrectLinks($result['links']);

                foreach ($links as $link) {
                    $preparedLink = $this->prepare($link);
                    if (isset($this->scanned[$preparedLink])) {
                        continue;
                    } elseif (in_array($preparedLink, $this->queue)) {
                        continue;
                    } elseif ($this->getUrlDepth($preparedLink) > $this->maxDepth) {
                        continue;
                    }
                    $this->queue[] = $preparedLink;
                }

                return true;
            }, function (RequestException $e) use ($url) {
                if (0 === $e->getCode()) {
                    $this->queue[] = $url;
                }
                $this->log("Failed to load resource $url\n", 2);
            });

            $promises[] = $promise;
        }
        try {
            \GuzzleHttp\Promise\unwrap($promises);
        } catch (ClientException $e) {
            $this->log("Client error while resolving promise: " . $e->getMessage());
        } catch (ServerException $e) {
            $this->log("Server error while resolving promise: " . $e->getMessage());
        } catch (ConnectException $e) {
            $this->log("Connection error while resolving promise: " . $e->getMessage());
        }
        $this->executeCallback(true);
    }

    /**
     *
     */
    public function rescan()
    {
        $this->storage->clean($this->url);
        $this->crawl();
    }

    /**
     * @return mixed
     */
    public function getLinks()
    {
        return $this->storage->loadScan($this->url);
    }

    /**
     * @return mixed
     */
    public function linksAdded()
    {
        return $this->storage->countLinks($this->url);
    }

    /**
     * @param string $link
     * @return boolean
     */
    public function checkLink($link)
    {
        if (preg_match('@^https?://@', $link) && false === strpos($link, $this->host)) {
            $this->log("Link {$link} has incorrect host. Needed {$this->host}", 4);
            return false;
        } elseif (!empty($this->uri()) && false === strpos($link, $this->uri())) {
            $this->log("Link {$link} is doesn't have {$this->uri()} part", 4);
            return false;
        } elseif (false !== strpos($link, 'javascript:') || false !== strpos($link, 'tel:') || false !== strpos($link, 'mailto:')) {
            return false;
        } elseif (!preg_match('@[\p{Cyrillic}\p{Latin}]+@i', $link)) {
            $this->log("Link {$link} has bad symbols", 4);
            return false;
        } else {
            foreach ($this->excludePatterns as $pattern) {
                if (preg_match('@^' . $pattern . '$@', $link)) {
                    $this->log("Link {$link} is incorrect according to robots rule {$pattern}", 4);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param string $path
     * @return array|boolean
     */
    public function saveXml($path)
    {
        try {
            if (!is_dir($path)) {
                throw new \InvalidArgumentException($path . " is not a directory");
            }

            $links = $this->storage->loadScan($this->url);
            $chunks = array_chunk($links, $this->fileLinksLimit);

            $sitemap = [];
            $sitemapPath = null;

            foreach ($chunks as $key => $chunk) {
                if (1 === count($chunks)) {
                    if ($this->async) {
                        $sitemapPath = $path . "/sitemap_a.xml";
                    } else {
                        $sitemapPath = $path . "/sitemap.xml";
                    }
                } else {
                    $index = $key + 1;
                    $sitemapPath = $path . "/sitemap_{$index}.xml";
                }

                if (!file_exists($sitemapPath)) {
                    if (!touch($sitemapPath)) {
                        throw new \RuntimeException("Can't create file {$sitemapPath}");
                    }
                } elseif (!is_writable($sitemapPath)) {
                    throw new \RuntimeException("Do not have permissions to create " . $sitemapPath);
                }

                $file = new \SplFileObject($sitemapPath, 'w+');
                $file->fwrite($this->generateXml($chunk));

                $sitemap[] = $sitemapPath;
            }

            if (1 < count($sitemap)) {
                $sitemaps = count($sitemap);
                $sitemapsIndexPath = $path . '/sitemaps_index.xml';
                $sitemapsIndex = $this->generateSitemapsIndex($sitemaps);

                $file = new \SplFileObject($sitemapsIndexPath, 'w+');
                $file->fwrite($sitemapsIndex);
            }

            return (isset($sitemapsIndexPath) ?
                ['index' => $sitemapsIndexPath, 'sitemaps' => $sitemap] :
                ['sitemap' => $sitemapPath]);

        } catch (\RunTimeException $e) {
            echo $e->getMessage();

            return false;
        }
    }

    /**
     * @return mixed
     */
    private function uri()
    {
        return $this->path .
            (($this->query) ? ('?' . $this->query) : '') .
            (($this->fragment) ? ('#' . $this->fragment) : '');
    }

    /**
     * @param array $links
     * @return mixed
     */
    private function getCorrectLinks($links = null)
    {
        if (is_null($links)) {
            $links = [];
            foreach ($this->parser->getLinks() as $link) {
                if (isset($this->scanned[$this->prepare($link)])) {
                    continue;
                }

                if ($this->checkLink($link)) {
                    $links[] = $link;
                }
            }
        }

        return array_unique($links);
    }

    /**
     * @param string $url
     * @param bool $async
     * @return bool|PromiseInterface
     */
    private function loadPage($url, $async = false)
    {
        if ($this->sleepTimeout) {
            sleep($this->sleepTimeout);
        }

        if (false === strpos($url, $this->host)) {
            $url = $this->protocol . '://' . $this->host . '/' . ltrim($url, '/');
        }

        try {
            $options = [
                "timeout" => $this->timeout,
                'allow_redirects' => false,
                "headers" => [
                    "User-Agent" => $this->userAgent
                ]
            ];

            if ($async) {
                $promise = $this->client->getAsync($url, $options);
                return $promise;
            }

            $response = $this->client->get($url, $options);

            if ($lastModified = $response->getHeaderLine("Last-Modified")) {
                $lastModified = \DateTime::createFromFormat("D, d M Y H:i:s O", $lastModified);
                $lastModified = $lastModified ? $lastModified->format('Y-m-d\TH:i:sP') : null;
            } else {
                $lastModified = null;
            }

            $this->lastUrlData = [
                "status" => $response->getStatusCode(),
                "lastModified" => $lastModified
            ];

            if (200 !== (int)$response->getStatusCode()) {
                $this->log('Error! Url: ' . $url . ' Status: ' . $response->getStatusCode());
                return false;
            }

            $this->parser->load($response->getBody());
        } catch (RequestException $e) {
            if (429 === (int) $e->getCode()) {
                $this->sleepTimeout += 0.5;
            }
            $this->log('Error! Url: ' . $url . '. ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param bool|false $force
     */
    private function executeCallback($force = false)
    {
        if ($this->scanned && $this->callback && $this->callbackFrequency) {
            if (0 === (count($this->scanned) % $this->callbackFrequency) || $force) {
                call_user_func(
                    $this->callback,
                    $this->scanned,
                    $this->storage->loadScan($this->url),
                    $this->queue
                );
            }
        }
    }

    /**
     * @param $url
     * @return mixed
     */
    private function prepare($url)
    {
        if (0 === strpos($url, '.')) {
            $url = substr($url, 1);
        }
        $host = $this->protocol . '://' . $this->host;
        return $host . '/' . ltrim(str_replace([$this->protocol . '://', $this->host], '', $url), '/');
    }

    /**
     * @param $url
     * @return integer
     */
    private function getUrlDepth($url)
    {
        $url = trim(str_replace([$this->protocol . '://', $this->host], '', $url), '/');
        return count(explode('/', $url));
    }

    /**
     *
     */
    private function loadRobots()
    {
        try {
            $response = $this->client->get($this->url . '/robots.txt', [
                "headers" => [
                    "User-Agent" => $this->userAgent
                ]
            ]);

            $robots = $response->getBody();
            preg_match('@user-agent:\s?\*(.+)(user-agent|$).*@isU', $robots, $matches);

            if (isset($matches[1])) {
                $commonRules = explode("\n", trim($matches[1]));
                foreach ($commonRules as $rule) {
                    if (empty($rule) || false === strpos($rule, ":")) {
                        continue;
                    }
                    $rule = explode(":", $rule);
                    if (preg_match("@^disallow$@i", $rule[0])) {
                        $regex = trim(str_replace("*", ".*", $rule[1]));
                        $regex = str_replace("?", '\?', $regex);
                        $this->excludePatterns[] = $regex;
                    }
                }
            }
        } catch (RequestException $e) {
        }
    }

    /**
     * @param array $links
     * @return mixed
     */
    private function generateXml(array $links)
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument();
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($links as $linkItem) {
            $xml->startElement('url');
            $xml->writeElement('loc', urldecode(urldecode($linkItem['link'])));
            if (!empty($linkItem['modified'])) {
                $xml->writeElement('lastmod', $linkItem['modified']);
            }
            $xml->writeElement('changefreq', 'monthly');
            $xml->writeElement('priority', '0.5');
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory(true);
    }

    /**
     * @param $amount
     * @return string
     */
    private function generateSitemapsIndex($amount)
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument();
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        while ($amount--) {
            $index = $amount + 1;
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $this->host . "/sitemap_{$index}.xml");
            $xml->writeElement('lastmod', date('Y-m-d\TH:i:sP'));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory(true);
    }

    /**
     * @param $message
     * @param $padding
     */
    private function log($message, $padding = 0)
    {
        if ($this->debug) {
            if (is_array($message)) {
                $message = print_r($message, true);
            } elseif (is_object($message)) {
                $message = var_export($message);
            }
            if (1 == $this->debugMode) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
            } elseif (2 === $this->debugMode) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
                if (is_writable($this->logFile)) {
                    file_put_contents($this->logFile, str_repeat("\t", $padding) . $message . PHP_EOL, FILE_APPEND);
                }
            }
        }
    }

}
