<?php
namespace vedebel\sitemap\crawlers;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Class SymfonyDomCrawler
 * @package vedebel\sitemap\crawlers
 */
class SymfonyDomCrawler implements CrawlerInterface
{
    /**
     * @var Crawler $crawler
     */
    private $crawler;

    /**
     * @var array
     */
    private $metaTags;

    /**
     * @var array
     */
    private $links;

    /**
     * @param Crawler $crawler
     */
    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;
    }

    /**
     * @param $html
     * @return array
     */
    public function load($html)
    {
        $metaTags = [];
        $this->crawler->clear();
        $this->crawler->addHtmlContent($html);

        $this->crawler->filter('meta')->each(function(Crawler $node) {
            $name = strtolower($node->attr('name'));
            $content = $node->attr('content');

            $metaTags[$name] = $content;
        });

        $links = [];

        $this->crawler->filter('a')->each(function(Crawler $link) use (&$links) {
            $rel = $link->attr('rel');
            if ('nofollow' === strtolower($rel)) {
                return false;
            }
            $links[] = $link->attr('href');
        });

        $this->links = array_unique($links);
        $this->metaTags = $metaTags;

        return ['links' => $this->links, 'meta' => $metaTags];
    }

    /**
     * @param $name
     * @return null
     */
    public function getMetaTag($name)
    {
        if (isset($this->metaTags[$name])) {
            return $this->metaTags[$name];
        }

        return null;
    }

    /**
     * @return array
     */
    public function getLinks()
    {
        return $this->links;
    }
}