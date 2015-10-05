<?php
namespace vedebel\sitemap\crawlers;

interface CrawlerInterface
{
    public function load($html);
    public function getLinks();
    public function getMetaTag($name);
}