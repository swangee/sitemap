<?php
namespace vedebel\sitemap;

class BasicLinksStorage implements LinksStorage
{
    private $storage = [];

    public function clean($siteUrl)
    {
        $this->storage = [];
    }

    public function hasScan($siteUrl)
    {
        return count($this->storage) > 0;
    }

    public function loadScan($siteUrl)
    {
        return $this->storage;
    }

    public function countLinks($siteUrl)
    {
        return count($this->storage);
    }

    public function linkIsScanned($siteUrl, $link)
    {
        return isset($this->storage[$link]);
    }

    public function addLink($siteUrl, $link, array $data)
    {
        $data["link"] = $link;
        $this->storage[$link] = $data;
    }
}
