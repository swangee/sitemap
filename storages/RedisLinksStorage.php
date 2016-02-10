<?php
namespace vedebel\sitemap\storages;

/**
 * Class RedisLinksStorage
 * @package vedebel\sitemap\storages
 */
class RedisLinksStorage implements LinksStorage
{
    /**
     * @var
     */
    private $ns;

    /**
     * @var \Redis
     */
    private $redis;

    /**
     * @var
     */
    private $siteUrl;

    /**
     * RedisLinksStorage constructor.
     * @param \Redis $redis
     * @param string $ns
     */
    public function __construct(\Redis $redis, $ns)
    {
        $this->ns = ':' . $ns;
        $this->redis = $redis;
    }

    /**
     *
     */
    public function __destruct()
    {
        if (!$this->siteUrl) return;

        foreach ($this->getSitesKeys($this->siteUrl) as $url) {
            $this->redis->delete($this->getPageKey($this->siteUrl, $url));
        }

        $this->redis->delete($this->getSiteKey($this->siteUrl));
    }

    /**
     * @param $siteUrl
     */
    public function clean($siteUrl)
    {
        $this->redis->delete($this->getSiteKey($siteUrl));
    }

    /**
     * @param $siteUrl
     * @return bool
     */
    public function hasScan($siteUrl)
    {
        return $this->countLinks($siteUrl) > 0;
    }

    /**
     * @param $siteUrl
     * @return array
     */
    public function loadScan($siteUrl)
    {
        $links = [];

        foreach ($this->getSitesKeys($siteUrl) as $pageUrl) {
            if ($link = $this->redis->hGetAll($this->getPageKey($siteUrl, $pageUrl))) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * @param $siteUrl
     * @return int
     */
    public function countLinks($siteUrl)
    {
        return $this->redis->sCard($this->getSiteKey($siteUrl));
    }

    /**
     * @param $siteUrl
     * @param $link
     * @return bool
     */
    public function linkIsScanned($siteUrl, $link)
    {
        return $this->redis->sContains($this->getSiteKey($siteUrl), $link) &&
        $this->redis->exists($this->getPageKey($siteUrl, $link));
    }

    /**
     * @param $siteUrl
     * @param $link
     * @param array $data
     */
    public function addLink($siteUrl, $link, array $data)
    {
        if (!$this->siteUrl) {
            $this->siteUrl = $siteUrl;
        }

        $data['link'] = $link;
        $this->redis->sAdd($this->getSiteKey($siteUrl), $link);
        $this->redis->hMset($this->getPageKey($siteUrl, $link), $data);
    }

    /**
     * @param $link
     * @return mixed
     */
    private function cleanLink($link)
    {
        return str_replace(['http://', 'https://'], '', $link);
    }

    /**
     * @param $siteUrl
     * @return array
     */
    private function getSitesKeys($siteUrl)
    {
        return $this->redis->sMembers($this->getSiteKey($siteUrl));
    }

    /**
     * @param $siteUrl
     * @return mixed
     */
    private function getSiteId($siteUrl)
    {
        $url = parse_url($siteUrl);
        return $url['host'];
    }

    /**
     * @param $siteUrl
     * @return mixed
     */
    private function getSiteKey($siteUrl)
    {
        return $this->ns . $this->getSiteId($siteUrl);
    }

    /**
     * @param $siteUrl
     * @param $pageUrl
     * @return string
     */
    private function getPageKey($siteUrl, $pageUrl)
    {
        return $this->getSiteKey($siteUrl) . ':' . $this->cleanLink($pageUrl);
    }
}
