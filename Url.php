<?php
/**
 * Created by PhpStorm.
 * User: vedebel
 * Date: 05.05.16
 * Time: 16:24
 */

namespace vedebel\sitemap;


class Url
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

    public function __construct(string $url)
    {
        $this->url = trim($url, '/');

        $parsed = parse_url($url);

        $this->protocol = $parsed['scheme'] ?? '';
        $this->host = $parsed['host'] ?? '';
        $this->path = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';
        $this->query = $parsed['query'] ?? '';
        $this->fragment = $parsed['fragment'] ?? '';
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @return string
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @return string
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * @return string
     */
    public function getUri()
    {
        return $this->path .
        (($this->query) ? ('?' . $this->query) : '') .
        (($this->fragment) ? ('#' . $this->fragment) : '');
    }
}