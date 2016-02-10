<?php
namespace vedebel\sitemap\storages;

/**
 * Class BasicLinksStorage
 * @package vedebel\sitemap\storages
 */
class BasicLinksStorage implements LinksStorage
{
    /**
     * @var array
     */
    private $storage = [];

    /**
     * @param $siteUrl
     */
    public function clean($siteUrl)
    {
        $this->storage = [];
    }

    /**
     * @param $siteUrl
     * @return bool
     */
    public function hasScan($siteUrl)
    {
        return count($this->storage) > 0;
    }

    /**
     * @param $siteUrl
     * @return array
     */
    public function loadScan($siteUrl)
    {
        return $this->storage;
    }

    /**
     * @param $siteUrl
     * @return int
     */
    public function countLinks($siteUrl)
    {
        return count($this->storage);
    }

    /**
     * @param $siteUrl
     * @param $link
     * @return bool
     */
    public function linkIsScanned($siteUrl, $link)
    {
        return isset($this->storage[$link]);
    }

    /**
     * @param $siteUrl
     * @param $link
     * @param array $data
     */
    public function addLink($siteUrl, $link, array $data)
    {
        $data["link"] = $link;
        $this->storage[$link] = $data;
    }
}
