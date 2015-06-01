<?php
namespace vedebel\sitemap;

use \Curl\Curl;
use \stringEncode\Exception as StringException;
use \Symfony\Component\Process\Process;
use \Symfony\Component\DomCrawler\Crawler;

class Sitemap
{
    private $url;
    private $options;

    private $uri;
    private $host;
    private $path;
    private $query;
    private $protocol;
    private $fragment;
    private $linksCache;

    private $logFile;
    private $debug;
    private $debugMode;

    private $limit;
    private $errors;
    private $scanned;
    private $maxDepth;

    private $loader;
    private $parser;
    private $storage;

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
        $this->limit = (!empty($options['limit']) ? $options['limit'] : 1000);
        $this->errors = [];
        $this->parser = $parser;
        $this->storage = $storage;
        $this->scanned = [];
        $this->maxDepth = (!empty($options['depth']) ? $options['depth'] : 3);

        $this->debug = true;
        $this->debugMode = (!empty($options['debugMode']) ? $options['debugMode'] : 2);

        $this->logFile = dirname(__FILE__) . '/log.txt';
        if (false === file_put_contents($this->logFile, '')) {
            $this->log('Can\'t create log file');
        }
    }

    /**
     * @param Curl $loader
     */
    public function setLoader(Curl $loader)
    {
        $this->loader = $loader;
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
     * @param $mode
     */
    public function debug($mode)
    {
        $this->debug = $mode;
    }

    public function crawl()
    {
        if (!$this->storage->hasScan($this->url)) {
            $this->log('Start crawling site');
            if (empty($this->url)) {
                throw new \RuntimeException('Root Url cannot be empty');
            }
            try {
                $this->crawlPages($this->url);
            } catch (StringException $e) {
                $this->errors[] = ['code' => $e->getCode(), 'message' => $e->getMessage()];
            }
        }
    }

    /**
     * @param $url
     */
    private function crawlPage($url)
    {
        $this->loadPage($url);
        $links = $this->getCorrectLinks();
    }

    /**
     * @param $url
     * @return null
     */
    private function crawlPages($url)
    {
        usleep(500000);
        if (isset($this->scanned[$this->prepare($url)])) {
            return;
        }
        if ($this->getUrlDepth($url) > $this->maxDepth) {
            return;
        }
        try {
            if (!$this->loadPage($url)) {
                if (substr($url, -1) === '/') {
                    $url = rtrim($url, '/');
                } else {
                    $url .= '/';
                }

                if (!$this->loadPage($url)) {
                    return;
                }
            }
        } catch (CurException $e) {
            $this->errors[] = ['code' => $e->getCode(), 'message' => $e->getMessage()];
            return;
        }
        if (count($this->scanned) >= $this->limit) {
            return;
        }
        $preparedUrl = $this->prepare($url);
        $this->scanned[$preparedUrl] = true;
        $pageInfo = $this->getPageInfo();
        if (strpos($pageInfo['metaRobots'], 'noindex') !== false) {
            return;
        }
        $this->storage->addLink($this->url, $preparedUrl, $pageInfo);
        $this->log('Link ' . $this->prepare($url) . ' added', 1);
        if (count($this->scanned) >= $this->limit) {
            return;
        }
        if (strpos($pageInfo['metaRobots'], 'nofollow') !== false) {
            return;
        }
        $links = $this->getCorrectLinks();
        $this->log('Page ' . $preparedUrl . ' scanned. Links amount: ' . count($links), 1);
        foreach ($links as $link) {
            $linksAdded = $this->storage->countLinks($this->url);
            if (count($this->scanned) >= $this->limit) {
                return;
            } elseif (isset($this->scanned[$this->prepare($link)])) {
                continue;
            }
            $this->log('Total added/scanned: ' . $linksAdded . '/' . count($this->scanned), 1);
            $this->log('Page ' . $this->prepare($url) . ' scanned. Links amount: ' . count($links), 1);
            $this->crawlPages($link);
        }
    }

    public function backgroundCrawl()
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
    }

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
     * @param $link
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
     * @param $path
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
        $self = $this;
        $links = $this->parser->filter('a')->reduce(function($link, $i) use ($self)  {
            $rel = $link->attr('rel');
            $href = $link->attr('href');
            if (strtolower($rel) === 'nofollow') {
                return false;
            }
            if (isset($self->scanned[$self->prepare($href)])) {
                return false;
            }
            return $self->checkLink($href);
        })->each(function($link) {
            return $link->attr('href');
        });
        return array_unique($links);
    }

    /**
     * @param $url
     */
    private function loadPage($url)
    {
        if (strpos($url, $this->host) === false) {
            $url = $this->protocol . '://' . $this->host . '/' . ltrim($url, '/');
        }

        $this->loader->get($url);

        if ($this->loader->error) {
            $this->log('Error! Url: ' . $url . ' Status: ' . $this->loader->http_status_code);
            return false;
        } elseif (200 !== (int) $this->loader->http_status_code) {
            $this->log('Error! Url: ' . $url . ' Status: ' . $this->loader->http_status_code);
            return false;
        }
        $this->parser->clear();
        $this->parser->addHtmlContent($this->loader->response);
        return true;
    }

    /**
     * @param $url
     * @return mixed
     */
    private function prepare($url)
    {
        return $this->protocol . '://' . $this->host . '/' . trim(str_replace([$this->protocol . '://', $this->host], '', $url), '/');
    }

    /**
     * @param $url
     */
    private function getUrlDepth($url)
    {
        $url = trim(str_replace([$this->protocol . '://', $this->host], '', $url), '/');
        return count(explode('/', $url));
    }

    private function getPageInfo()
    {
        $canonical = $metaRobots = $modified = $title = $description = null;
        $meta = $this->parser->filter('meta')->each(function($node) use (&$metaRobots, &$canonical) {
            $name = strtolower($node->attr('name'));
            $content = $node->attr('content');

            if ($canonical && $metaRobots) {
                break;
            }

            if ('robots' === $name && is_null($metaRobots)) {
                $metaRobots = $content;
            } elseif ('canonical' === $name && is_null($canonical)) {
                $canonical = $content;
            }
        });

        return [
            'status' => 200,
            'modified' => $modified,
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
            if ($linkItem['modified']) {
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
            if ($this->debugMode == 1) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
            } elseif ($this->debugMode == 2) {
                echo str_repeat("\t", $padding) . $message . PHP_EOL;
                file_put_contents($this->logFile, str_repeat("\t", $padding) . $message . PHP_EOL, FILE_APPEND);
            }
        }
    }

}
