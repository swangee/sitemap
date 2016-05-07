<?php
namespace vedebel\sitemap;

/**
 * Class for configuring sitemap generation.
 *
 * Class Config
 * @package vedebel\sitemap
 */
class Config
{
    const DEBUG_FILE = 2;
    const DEBUG_STDOUT = 1;

    const DEFAULT_VALUES = [
        'async' => true,
        'maxDepth' => 3,
        'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.157 Safari/537.36',
        'limits' => [
            'threads' => 3,
            'linksTotal' => 1000,
            'linksPerFile' => 50000,

        ],
        'timeouts' => [
            'request' => 20,
            'sleep' => 0
        ],
        'debug' => [
            'enable' => false,
            'mode' => self::DEBUG_FILE,
            'logFile' => __DIR__ . '/crawl.log',
        ],
        'download' => [
            'enable' => false,
            'directory' => __DIR__ . '/download'
        ],
        'onProgress' => false
    ];

    /**
     * @var array
     */
    private $config;

    /**
     * Constructing config object by passing array with params.
     *
     * Config constructor.
     * @param Url $url
     * @param array $config
     */
    public function __construct(Url $url, array $config)
    {
        $this->config = $config;
        $this->config['url'] = $url;
    }

    /**
     * Common getter for all of the params.
     *
     * @param $name
     * @return mixed
     */
    public function get(string $name)
    {
        $path = explode('.', $name);

        return $this->getByPath($path);
    }

    /**
     * Common getter for all of the params.
     *
     * @param string $name
     * @param $value
     * @return mixed
     */
    public function set(string $name, $value)
    {
        $path = explode('.', $name);

        $this->setInPath($path, $value);
    }

    /**
     * Magic getter that allows access to all of the params through property.
     *
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * Magic getter that allows access to all of the params through getPropertyName methods.
     *
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (strpos($name, 'get') !== 0) {
            throw new \BadMethodCallException(sprintf('There is no method named %d', $name));
        }

        $propertyName = lcfirst(substr($name, 3));

        return $this->get($propertyName);
    }

    /**
     * Retrieves value by its path in config array.
     *
     * @param array $path
     * @param bool $useDefault
     * @return mixed
     */
    private function getByPath(array $path, bool $useDefault = false)
    {
        if ($useDefault) {
            $currentLevel = self::DEFAULT_VALUES;
        } else {
            $currentLevel = $this->config;
        }

        $leftPath = $path;

        do {
            if (!count($leftPath)) {
                break;
            }

            $pathItem = array_shift($leftPath);

            if (!isset($currentLevel[$pathItem])) {
                break;
            }

            $currentLevel = $currentLevel[$pathItem];

            if (count($leftPath) === 0) {
                $value = $currentLevel;
                break;
            }
        } while ($pathItem);

        if (!isset($value)) {
            if ($useDefault) {
                throw new \InvalidArgumentException(sprintf('There is no value for path %s', print_r($path, 1)));
            }

            $value = $this->getByPath($path, true);
        }

        return $value;
    }

    /**
     * @param array $path
     * @param $value
     */
    private function setInPath(array $path, $value)
    {
        $currentLevel = &$this->config;

        do {
            if (!count($path)) {
                break;
            }

            $pathItem = array_shift($path);

            if (!isset($currentLevel[$pathItem])) {
                $currentLevel[$pathItem] = null;
            }

            $currentLevel = &$currentLevel[$pathItem];

            if (count($path) === 0) {
                $currentLevel = $value;
                break;
            }

            $currentLevel = [];
        } while ($pathItem);
    }
}