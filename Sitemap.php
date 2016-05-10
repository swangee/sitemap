<?php
namespace vedebel\sitemap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
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
     * @var array
     */
    private $queue;

    /**
     * @var Config
     */
    private $config;

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
     * @var Client
     */
    private $client;

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
     * @var array
     */
    private $contentExcludePatterns;

    /**
     * @param CrawlerInterface $parser
     * @param LinksStorage $storage
     * @param Config $config
     */
    public function __construct(CrawlerInterface $parser, LinksStorage $storage, Config $config)
    {
        $this->config = $config;
        
        $this->queue = [];
        $this->errors = [];
        $this->parser = $parser;
        $this->storage = $storage;
        $this->scanned = [];
        $this->lastUrlData = [];
        $this->excludePatterns = [];
        $this->contentExcludePatterns = [];
        $this->excludeExtension = ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt"];
    }

    /**
     * @param Client $client
     */
    public function setLoader(Client $client)
    {
        $this->client = $client;
    }

    public function addExcludePatterns(array $patterns)
    {
        foreach ($patterns as $key => $pattern) {
            $pattern = trim(str_replace("*", ".*", $pattern));
            $pattern = str_replace("?", '\?', $pattern);
            $patterns[$key] = $pattern;
        }

        $patterns = array_merge($this->excludePatterns, $patterns);
        $this->excludePatterns = array_unique($patterns);
    }

    public function addContentExcludePatterns(array $patterns)
    {
        foreach ($patterns as $key => $pattern) {
            $pattern = trim(str_replace("*", ".*", $pattern));
            $pattern = str_replace("?", '\?', $pattern);
            $patterns[$key] = $pattern;
        }

        $patterns = array_merge($this->contentExcludePatterns, $patterns);
        $this->contentExcludePatterns = array_unique($patterns);
    }

    public function addExcludeExtensions(array $extensions)
    {
        $extensions = array_merge($this->excludeExtension, $extensions);
        $this->excludeExtension = array_unique($extensions);
    }

    /**
     *
     */
    public function crawl()
    {
        /**
         * @var Url $url
         */
        $url = $this->config->get('url');
        $link = $url->getUrl();

        if (!$this->storage->hasScan($link)) {
            $this->log('Start crawling site');
            $this->loadRobots();
            try {
                $this->queue[] = $this->prepare($link);

                $threadsLimit = $this->config->get('limits.threads');

                do {
                    $queueLength = count($this->queue);
                    $threads = ($queueLength < $threadsLimit) ? $queueLength : $threadsLimit;
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
            if ($this->getUrlDepth($url) > $this->config->get('maxDepth')) {
                continue;
            }
            $this->log('Start scan page ' . $url, 1);

            try {
                $promise = $this->loadPage($url, true);
            } catch (TooManyRedirectsException $e) {
                $this->log("Failed to load resource $url with error: " . $e->getMessage() . "\n", 2);
                continue;
            }

            $promise->then(function(ResponseInterface $response) use ($url, $threads) {
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

                /**
                 * @var $pageUrl Url
                 */
                $pageUrl = $this->config->get('url');
                $linksAmount = $this->storage->countLinks($pageUrl->getUrl());

                if ($linksAmount >= $this->config->get('limits.linksTotal')) {
                    $this->queue = [];
                    return false;
                }

                $html = (string)$response->getBody();

                $result = $this->parser->load($html);

                $pageInfo = [
                    'canonical' => $result['meta']['canonical'],
                    'metaRobots' => $result['meta']['robots'],

                    "status" => $response->getStatusCode(),
                    "lastModified" => $lastModified
                ];

                if (false === stripos($pageInfo['metaRobots'], 'noindex') && $this->checkContent($html)) {
                    if ($this->config->get('download.enable')) {
                        file_put_contents($this->config->get('download.directory')
                            . '/' . urlencode($url) . '.tmp.html', $html);
                    }
                    $this->storage->addLink($pageUrl->getUrl(), $url, $pageInfo);
                    $this->log('Link ' . $url . ' added', 2);
                }

                if (false !== stripos($pageInfo['metaRobots'], 'nofollow')) {
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
                    } elseif ($this->getUrlDepth($preparedLink) > $this->config->get('maxDepth')) {
                        continue;
                    }
                    $this->queue[] = $preparedLink;
                }

                return true;
            }, function (\Exception $e) use ($url, $threads) {
                if (0 === $e->getCode()) {
                    $this->queue[] = $url;
                } elseif (in_array((int) $e->getCode(), [429, 503])) {
                    $this->queue[] = $url;
                }
                $this->log("Failed to load resource $url with error: " . $e->getMessage() . "\n", 2);
            });

            $promises[] = $promise;
        }
        try {
            \GuzzleHttp\Promise\unwrap($promises);
        } catch (TooManyRedirectsException $e) {
            $this->log("Client error while resolving promise: " . $e->getMessage());
        } catch (ClientException $e) {
            $this->log("Client error while resolving promise: " . $e->getMessage());
        } catch (ServerException $e) {
            $this->log("Server error while resolving promise: " . $e->getMessage());

            if (in_array((int) $e->getCode(), [429, 503])) {
                $threads = $this->config->get('limits.threads');

                if ($threads > 1) {
                    $this->config->set('limits.threads', $threads - 1);
                }

                $sleepTimeout = $this->config->get('timeout.sleep');
                $this->config->set('limits.sleep', $sleepTimeout + 0.5);

                $this->log(sprintf('Sleep for 5 minutes. Threads limit is decremented. Now is %d', $threads));

                sleep(300);
            }
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
        /**
         * @var $pageUrl Url
         */
        $pageUrl = $this->config->get('url');

        $this->storage->clean($pageUrl->getUrl());
        $this->crawl();
    }

    /**
     * @return mixed
     */
    public function getLinks()
    {
        /**
         * @var $pageUrl Url
         */
        $pageUrl = $this->config->get('url');

        return $this->storage->loadScan($pageUrl->getUrl());
    }

    /**
     * @return mixed
     */
    public function linksAdded()
    {
        /**
         * @var $pageUrl Url
         */
        $pageUrl = $this->config->get('url');
        return $this->storage->countLinks($pageUrl->getUrl());
    }

    /**
     * @param string $link
     * @return boolean
     */
    public function checkLink($link)
    {
        /**
         * @var $pageUrl Url
         */
        $pageUrl = $this->config->get('url');

        if (preg_match('@^https?://@', $link) && false === strpos($link, $pageUrl->getHost())) {
            $this->log("Link {$link} has incorrect host. Needed {$pageUrl->getHost()}", 4);
            return false;
        } elseif (!empty($pageUrl->getUri()) && false === strpos($link, $pageUrl->getUri())) {
            $this->log("Link {$link} is doesn't have {$pageUrl->getUri()} part", 4);
            return false;
        } elseif (false !== strpos($link, 'javascript:') || false !== strpos($link, 'tel:') || false !== strpos($link, 'mailto:')) {
            return false;
        } elseif (!preg_match('@[\p{Cyrillic}\p{Latin}]+@i', $link)) {
            $this->log("Link {$link} has bad symbols", 4);
            return false;
        } else {
            foreach ($this->excludePatterns as $pattern) {
                if (preg_match('@' . preg_quote($pattern, '@') . '@', $link)) {
                    $this->log("Link {$link} is incorrect according to rule {$pattern}", 4);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param $html
     * @return bool
     */
    private function checkContent($html)
    {
        foreach ($this->contentExcludePatterns as $pattern) {
            if (preg_match('@' . preg_quote($pattern, '@') . '@iu', $html)) {
                return false;
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

            /**
             * @var $pageUrl Url
             */
            $pageUrl = $this->config->get('url');

            $links = $this->storage->loadScan($pageUrl->getUrl());
            $chunks = array_chunk($links, $this->config->get('limits.linksPerFile'));

            $sitemap = [];
            $sitemapPath = null;

            foreach ($chunks as $key => $chunk) {
                if (1 === count($chunks)) {
                    $sitemapPath = $path . "/sitemap.xml";
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

        } catch (\RuntimeException $e) {
            echo $e->getMessage();

            return false;
        }
    }

    /**
     * @param array $links
     * @return mixed
     */
    private function getCorrectLinks($links = null)
    {
        $correctLinks = [];

        if (is_null($links)) {
            $links = $this->parser->getLinks();
        }

        foreach ($links as $link) {
            if (isset($this->scanned[$this->prepare($link)])) {
                continue;
            }

            if ($this->checkLink($link)) {
                $correctLinks[] = $link;
            }
        }

        return array_unique($correctLinks);
    }

    /**
     * @param string $url
     * @param bool $async
     * @return bool|PromiseInterface
     */
    private function loadPage($url, $async = false)
    {
        /** @var Url $pageUrl */
        $pageUrl = $this->config->get('url');

        if (false === strpos($url, $pageUrl->getHost())) {
            $url = $pageUrl->getProtocol() . '://' . $pageUrl->getHost() . '/' . ltrim($url, '/');
        }

        if ($sleepTimeout = $this->config->get('timeouts.sleep')) {
            sleep($sleepTimeout);
        }

        try {
            $options = [
                'timeout' => $this->config->get('timeouts.request'),
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => $this->config->get('userAgent')
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
        } catch (TooManyRedirectsException $e) {
            $this->log('Error! Url: ' . $url . '. ' . $e->getMessage());
            return false;
        } catch (RequestException $e) {
            if (429 === (int) $e->getCode()) {
                $sleepTimeout = $this->config->get('timeout.sleep');
                $this->config->set('limits.sleep', $sleepTimeout + 0.5);
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
        if ($this->scanned && $this->config->get('onProgress')) {
            if (0 === (count($this->scanned) % $this->config->get('onProgress.frequency')) || $force) {
                /** @var Url $siteUrl */
                $siteUrl = $this->config->get('url');
                $callable = $this->config->get('onProgress.callable');

                call_user_func(
                    $callable,
                    $this->scanned,
                    $this->storage->loadScan($siteUrl->getUrl()),
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
        /** @var Url $siteUrl */
        $siteUrl = $this->config->get('url');

        $host = $siteUrl->getProtocol() . '://' . $siteUrl->getHost();

        return $host . '/' . ltrim(str_replace([$siteUrl->getProtocol() . '://', $siteUrl->getHost()], '', $url), '/');
    }

    /**
     * @param $url
     * @return integer
     */
    private function getUrlDepth($url)
    {
        /** @var Url $siteUrl */
        $siteUrl = $this->config->get('url');

        $url = trim(str_replace([$siteUrl->getProtocol() . '://', $siteUrl->getHost()], '', $url), '/');
        return count(explode('/', $url));
    }

    /**
     *
     */
    private function loadRobots()
    {
        try {
            /** @var Url $siteUrl */
            $siteUrl = $this->config->get('url');

            $response = $this->client->get($siteUrl->getUrl() . '/robots.txt', [
                'headers' => [
                    'User-Agent' => $this->config->get('userAgent')
                ]
            ]);

            $robots = $response->getBody();
            preg_match('@user-agent:\s?\*(.+)(user-agent|$).*@isU', $robots, $matches);

            $patterns = [];

            if (isset($matches[1])) {
                $commonRules = explode("\n", trim($matches[1]));
                foreach ($commonRules as $rule) {
                    if (empty($rule) || false === strpos($rule, ":")) {
                        continue;
                    }
                    $rule = explode(':', $rule);
                    if (preg_match("@^disallow$@i", $rule[0])) {
                        $patterns[] = $rule[1];
                    }
                }
            }

            $this->addExcludePatterns($patterns);
        } catch (RequestException $e) {
            $this->log('No robots file found');
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
            $xml->writeElement('loc', $linkItem['link']);
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
        /** @var Url $siteUrl */
        $siteUrl = $this->config->get('url');
        $host = $siteUrl->getHost();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument();
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $sitemapIndex = 1;

        while ($amount--) {
            $index = $sitemapIndex++;
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $host . "/sitemap_{$index}.xml");
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
        if ($this->config->get('debug.enable')) {
            if (is_array($message)) {
                $message = print_r($message, true);
            } elseif (is_object($message)) {
                $message = var_export($message);
            }
            if ($this->config->get('debug.mode') === Config::DEBUG_STDOUT) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
            } elseif ($this->config->get('debug.mode') === Config::DEBUG_FILE) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;

                $logFile = $this->config->get('debug.logFile');

                if (is_writable($logFile)) {
                    file_put_contents($logFile, str_repeat("\t", $padding) . $message . PHP_EOL, FILE_APPEND);
                }
            }
        }
    }

}
