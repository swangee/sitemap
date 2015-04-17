<?php
namespace Vedebel\Sitemap;

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

  public function __construct(\PHPHtmlParser\Dom $parser, LinksStorage $storage, $url = null, array $options = [])
  {
    $parsed = parse_url($url);

    if (isset($parsed['scheme'])) $this->protocol = $parsed['scheme'];
    if (isset($parsed['host'])) $this->host = $parsed['host'];
    if (isset($parsed['path'])) $this->path = ltrim($parsed['path'], '/');
    if (isset($parsed['query'])) $this->query = $parsed['query'];
    if (isset($parsed['fragment'])) $this->fragment = $parsed['fragment'];

    $this->url = $url;
    $this->limit = (!empty($options['limit']) ? $options['limit'] : 1000);
    $this->errors = [];
    $this->parser = $parser;
    $this->storage = $storage;
    $this->scanned = [];
    $this->maxDepth = (!empty($options['depth']) ? $options['depth'] : 3);

    $this->debug = false;
    $this->logFile = dirname(__FILE__) . '/log.txt';
    if (false === file_put_contents($this->logFile, '')) {
      $this->log('Can\'t create log file', $padding);
    }
    $this->debugMode = (!empty($options['debugMode']) ? $options['debugMode'] : 2);
  }

  public function setLoader(\Curl\Curl $loader)
  {
    $this->loader = $loader;
  }

  public function setUrl($url)
  {
    $this->url = $url;
  }

  public function setLimit($limit)
  {
    $this->limit = $limit;
  }

  public function debug($mode)
  {
    $this->debug = $mode;
  }

  public function crawl()
  {
    if (!$this->storage->hasScan($this->url)) {
      $this->log('Start crawling site');
      try {
        $this->crawlPages($this->url);
      } catch (\stringEncode\Exception $e) {
        $this->errors[] = ['code' => $e->getCode(), 'message' => $e->getMessage()];
      }
    }
  }

  public function rescan()
  {
    $this->storage->clean($this->url);
    $this->crawl();
  }

  public function saveXml($path)
  {
    try {
      $file = new \SplFileObject($path, 'w+');
      $file->fwrite($this->generateXml());
    } catch(\RunTimeException $e) {

    }
  }

  public function getLinks()
  {
    return $this->storage->loadScan($this->url);
  }

  public function linksAdded()
  {
    return $this->storage->countLinks($this->url);
  }

  public function checkLink($link) {
    if (preg_match('@^https?://@', $link) && strpos($link, $this->host) === false) {
      return false;
    } elseif (!empty($this->uri()) && strpos($link, $this->uri()) === false) {
      return false;
    } elseif (strpos($link, 'javascript:') !== false) {
      return false;
    }
    return true;
  }

  private function uri()
  {
    return $this->path . (($this->query) ? ('?' . $this->query) : '') . (($this->fragment) ? ('#' . $this->fragment) : '');
  }

  private function getCorrectLinks()
  {
    $links = (array) $this->parser->find('a');
    $links = array_shift($links);
    $self = $this;
    $links = array_filter($links, function($link) use ($self) {
      $rel = $link->getAttribute('rel');
      if (strtolower($rel) === 'nofollow') {
        return false;
      }
      return $self->checkLink($link->getAttribute('href'));
    });
    $links = array_map(function($link) {
      return $link->getAttribute('href');
    }, $links);
    return array_unique($links);
  }

  private function crawlPage($url)
  {
    $this->loadPage($url);
    $links = $this->getCorrectLinks();
  }

  private function crawlPages($url)
  {
    if (isset($this->scanned[$this->prepare($url)])) {
      continue;
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
    } catch (\PHPHtmlParser\Exceptions\CurlException $e) {
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
    $this->storage->addLink($this->url, $preparedUrl, $this->getPageInfo());
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

  private function loadPage($url)
  {
    if (strpos($url, $this->host) === false) {
      $url = $this->protocol . '://' . $this->host . '/' . ltrim($url, '/');
    }
    $this->loader->get($url);
    if ($this->loader->error) {
      $this->log('Error! Url: ' . $url . ' Status: ' . $this->loader->http_status_code);
      return false;
    }
    $this->parser->load($this->loader->response);
    return true;
  }

  private function prepare($url)
  {
    return $this->protocol . '://' . $this->host . '/' .  urlencode(trim(str_replace([$this->protocol . '://', $this->host], '', $url), '/'));
  }

  private function getUrlDepth($url)
  {
    $url = trim(str_replace([$this->protocol . '://', $this->host], '', $url), '/');
    return count(explode('/', $url));
  }

  private function getPageInfo()
  {
    $canonical = $metaRobots = $modified = $title = $description = null;
    $meta = $this->parser->find('meta');

    foreach ($meta as $element) {
      $name = strtolower($element->getAttribute('name'));
      $content = $element->getAttribute('content');

      if ($canonical && $metaRobots) {
        break;
      }

      if ($name === 'robots') {
        $metaRobots = $content;
      } elseif ($name === 'canonical') {
        $canonical = $content;
      } 
    }

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
          $xml->writeElement ('loc', $linkItem['link']);
          if ($linkItem['modified']) {
            $xml->writeElement ('lastmod', $linkItem['modified']);
          }
          $xml->writeElement ('changefreq', 'monthly');
          $xml->writeElement ('priority', '0.5');
        $xml->endElement();
      }
      $xml->endElement();
    $xml->endDocument();
    return $xml->outputMemory(true);
  }

  private function log($message, $padding = 0)
  {
    if ($this->debug) {
      if ($this->debugMode == 1) {
        echo str_repeat("\t", $padding) . $message . PHP_EOL;
      } elseif ($this->debugMode == 2) {
        file_put_contents($this->logFile, str_repeat("\t", $padding) . $message . PHP_EOL, FILE_APPEND);
      }
    }
  }

}