<?php
namespace vedebel\sitemap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;

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
    private $sleepTimeout;

    /**
     * @var Crawler
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
     * @param Crawler $parser
     * @param LinksStorage $storage
     * @param $url
     * @param array $options
     */
    public function __construct(Crawler $parser, LinksStorage $storage, $url, array $options = [])
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
        $this->limit = (!empty($options['limit']) ? (int) $options['limit'] : 1000);
        $this->timeout = (!empty($options['timeout']) ? (int) $options['timeout'] : 5);
        $this->sleepTimeout = 0;
        $this->errors = [];
        $this->parser = $parser;
        $this->storage = $storage;
        $this->scanned = [];
        $this->lastUrlData = [];
        $this->excludeExtension = ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt"];
        $this->maxDepth = (!empty($options['depth']) ? $options['depth'] : 3);

        $this->debug = (!empty($options['debug']) ? (bool) $options['debug'] : false);
        $this->debugMode = (!empty($options['debugMode']) ? (int) $options['debugMode'] : 2);

        $this->logFile = dirname(__FILE__) . '/log.txt';
        if (2 === $this->debugMode && false === file_put_contents($this->logFile, '')) {
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
            try {
                $this->queue[] = $this->prepare($this->url);
                do {
                    $this->crawlPages();
                    $this->executeCallback();
                } while ($this->queue);

                $this->executeCallback(true);
            } catch (\Exception $e) {
                $this->errors[] = ['code' => $e->getCode(), 'message' => $e->getMessage()];
            }
        }
    }

    /**
     * @param $url
     */
    /*private function crawlPage($url)
    {
        $this->loadPage($url);
        $links = $this->getCorrectLinks();
    }*/

    /**
     * @return null
     */
    private function crawlPages()
    {
        if (!($url = array_shift($this->queue))) {
            return;
        }
        if (isset($this->scanned[$url])) {
            return;
        }
        $this->scanned[$url] = true;
        if ($this->getUrlDepth($url) > $this->maxDepth) {
            return;
        }
        $this->log('Start scan page ' . $url, 1);
        try {
            if (!$this->loadPage($url)) {
                if ('/' === substr($url, -1)) {
                    $url = rtrim($url, '/');
                } else {
                    $url .= '/';
                }

                if (!$this->loadPage($url)) {
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->errors[] = ['code' => $e->getCode(), 'message' => $e->getMessage()];
            $this->log("Error while retrieving page {$url}.\n" . print_r(end($this->errors)), 2);
            return;
        }
        $linksAmount = $this->storage->countLinks($this->url);
        if ($this->limit && $linksAmount >= $this->limit) {
            $this->queue = [];
            return;
        }
        $pageInfo = $this->getPageInfo();
        if (false !== strpos($pageInfo['metaRobots'], 'noindex')) {
            $this->log("Page has meta tag noindex\n", 2);
            return;
        }
        $this->storage->addLink($this->url, $url, $pageInfo);
        $this->log('Link ' . $url . ' added', 2);
        if (false !== strpos($pageInfo['metaRobots'], 'nofollow')) {
            $this->log("Page has meta tag nofollow\n", 2);
            return;
        }
        $links = $this->getCorrectLinks();
        foreach ($links as $link) {
            $preparedLink = $this->prepare($link);
            if (isset($this->scanned[$preparedLink])) {
                continue;
            } elseif (in_array($preparedLink, $this->queue)) {
                continue;
            } elseif ($this->getUrlDepth($url) > $this->maxDepth) {
                continue;
            }
            $this->queue[] = $preparedLink;
        }
        $this->log("Page {$url} scanned. Links amount: " . count($links), 1);
        $this->log("Total added/scanned/queue: {$linksAmount}/" . count($this->scanned) . "/" . count($this->queue) . "\n", 1);
    }

    /**
     * @return int|null
     */
    /*public function backgroundCrawl()
    {
        if (!$this->storage->hasScan($this->url)) {
            $processString = dirname(__FILE__) . '/background.php --url ' . $this->url . ' --dest ./sitemap.xml --limit=' . $this->limit;
            if ($this->debug) {
                $processString .= ' --debug';
            }
            $process = new Process('php ' . $processString);
            try {
                $process->start();
            } catch (\RuntimeException $e) {
                $this->log($e->getMessage());
            }
            return $process->getPid();
        }

        return null;
    }*/

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
        if (preg_match('@^https?://@', $link) && strpos($link, $this->host) === false) {
            return false;
        } elseif (!empty($this->uri()) && strpos($link, $this->uri()) === false) {
            return false;
        } elseif (false !== strpos($link, 'javascript:') || false !== strpos($link, 'tel:') || false !== strpos($link, 'mailto:')) {
            return false;
        } elseif (!preg_match('@[\p{Cyrillic}\p{Latin}]+@i', $link)) {
            return false;
        }
        return true;
    }

    /**
     * @param string $path
     */
    public function saveXml($path)
    {
        try {
            if (!file_exists($path)) {
                touch($path);
            }
            $file = new \SplFileObject($path, 'w+');
            $file->fwrite($this->generateXml());
        } catch (\RunTimeException $e) {
            echo $e->getMessage();
        }
    }

    /**
     * @return mixed
     */
    private function uri()
    {
        return $this->path . (($this->query) ? ('?' . $this->query) : '') . (($this->fragment) ? ('#' . $this->fragment) : '');
    }

    /**
     * @return mixed
     */
    private function getCorrectLinks()
    {
        $links = $this->parser->filter('a')->reduce(function(Crawler $link)  {
            $rel = $link->attr('rel');
            $href = $link->attr('href');
            if ('nofollow' === strtolower($rel)) {
                return false;
            }
            if (isset($this->scanned[$this->prepare($href)])) {
                return false;
            }
            return $this->checkLink($href);
        })->each(function(Crawler $link) {
            return $link->attr('href');
        });
        return array_unique($links);
    }

    /**
     * @param string $url
     * @return boolean
     */
    private function loadPage($url)
    {
        if ($this->sleepTimeout) {
            sleep($this->sleepTimeout);
        }

        if (false === strpos($url, $this->host)) {
            $url = $this->protocol . '://' . $this->host . '/' . ltrim($url, '/');
        }

        try {
            $response = $this->client->get($url, ["timeout" => $this->timeout]);

            if ($lastModified = $response->getHeaderLine("Last-Modified")) {
                $lastModified = \DateTime::createFromFormat("D, d M Y H:i:s O", $lastModified)->format('Y-m-d\TH:i:sP');
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

            $this->parser->clear();
            $this->parser->addHtmlContent($response->getBody());
        } catch (RequestException $e) {
            if (429 === (int) $e->getCode()) {
                $this->sleepTimeout += 0.5;
            }
            $this->log('Error! Url: ' . $url . '. ' . $e->getMessage());
            return false;
        }

        return true;
    }

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
        return $this->protocol . '://' . $this->host . '/' . ltrim(str_replace([$this->protocol . '://', $this->host], '', $url), '/');
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
     * @return array
     */
    private function getPageInfo()
    {
        $canonical = $metaRobots = $title = $description = null;

        $this->parser->filter('meta')->each(function(Crawler $node) use (&$metaRobots, &$canonical) {
            $name = strtolower($node->attr('name'));
            $content = $node->attr('content');

            if ($canonical && $metaRobots) {
                return;
            }

            if ('robots' === $name && is_null($metaRobots)) {
                $metaRobots = $content;
            } elseif ('canonical' === $name && is_null($canonical)) {
                $canonical = $content;
            }
        });

        return [
            'status' => $this->lastUrlData["status"],
            'modified' => $this->lastUrlData["lastModified"],
            'canonical' => $canonical,
            'metaRobots' => $metaRobots,

            //'title' => $title,
            //'description' => $description,
            //'h1' => (array) $this->parser->find('h1'),
            //'images' => (array) $this->parser->find('img'),
        ];
    }

    /**
     * @return mixed
     */
    private function generateXml()
    {
        $links = $this->getLinks();
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
     * @param $message
     * @param $padding
     */
    private function log($message, $padding = 0)
    {
        if ($this->debug) {
            if (1 == $this->debugMode) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
            } elseif (2 === $this->debugMode) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
                file_put_contents($this->logFile, str_repeat("\t", $padding) . $message . PHP_EOL, FILE_APPEND);
            }
        }
    }

}
