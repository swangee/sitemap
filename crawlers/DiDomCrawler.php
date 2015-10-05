<?php
namespace vedebel\sitemap\crawlers;

use DiDom\Document;
use DiDom\Element;

class DiDomCrawler implements CrawlerInterface
{
    /**
     * @var Document $crawler
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

    public function __construct(Document $crawler)
    {
        $this->crawler = $crawler;
    }

    public function load($html)
    {
        $metaTags = [
            'canonical' => '',
            'robots' => ''
        ];
        $this->crawler->loadHtml((string) $html);

        foreach ($this->crawler->find('meta') as $meta) {
            /** @var Element $meta */
            $name = strtolower($meta->attr('name'));
            $content = $meta->attr('content');

            $metaTags[$name] = $content;
        }

        $links = [];
        foreach ($this->crawler->find('a') as $link) {
            /** @var Element $link */
            $rel = $link->attr('rel');
            $href = $link->attr('href');

            if ('nofollow' === strtolower($rel)) {
                continue;
            }

            $links[] = $href;
        }
        $this->links = array_unique($links);
        $this->metaTags = $metaTags;

        return ['links' => $links, 'meta' => $metaTags];
    }

    public function getMetaTag($name)
    {
        if (isset($this->metaTags[$name])) {
            return $this->metaTags[$name];
        }

        return null;
    }

    public function getLinks()
    {
        return $this->links;
    }
}