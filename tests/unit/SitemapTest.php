<?php

use PHPHtmlParser\Dom;
use Vedebel\Sitemap\Sitemap;
use Vedebel\Sitemap\SQLiteLinksStorage;

class SitemapTest extends \PHPUnit_Framework_TestCase
{
    private $url;
    private $options;

    protected function setUp()
    {
        $this->url = 'http://worldoftanks.ru/';
        $this->options = [];
    }

    protected function tearDown()
    {
    }

    // tests
    public function testSitemapClassExists()
    {
        $this->assertTrue(class_exists('Vedebel\Sitemap\Sitemap'));
    }

    public function testSitemapClassHasMethodCrawl()
    {
        $sitemap = new Sitemap(new Dom(), new SQLiteLinksStorage(), $this->url, $this->options);
        $this->assertTrue(method_exists($sitemap, 'crawl'));
    }

    public function testSitemapClassHasMethodGetLinks()
    {
        $sitemap = new Sitemap(new Dom(), new SQLiteLinksStorage(), $this->url, $this->options);
        $this->assertTrue(method_exists($sitemap, 'getLinks'));
    }

    public function testSitemapCrawledLinks()
    {
        $sitemap = new Sitemap(new Dom(), new SQLiteLinksStorage(), $this->url, $this->options);
        $sitemap->crawl();
        $links = $sitemap->getLinks();
        $this->assertTrue((is_array($links) && !empty($links)));
    }

}