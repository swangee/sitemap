<?php
namespace vedebel\sitemap\storages;

use PDO;
use PDOStatement;

class SQLiteLinksStorage implements LinksStorage
{
    /**
     * @var PDO
     */
    private $con;
    /**
     * @var string
     */
    private $table = 'links';
    /**
     * @var null
     */
    private $dbName = null;
    /**
     * @var string
     */
    private $dbFolder;
    /**
     * @var int
     */
    private $linksAmount = 0;
    /**
     * @var bool
     */
    private $loadLinksAttempt = false;

    /**
     * @param string $dbFolder
     */
    public function __construct($dbFolder)
    {
        if (file_exists($dbFolder)) {
            $this->dbFolder = rtrim($dbFolder, "/");
        } else {
            throw new \InvalidArgumentException("Directory {$dbFolder} does not exists");
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        if (file_exists($this->dbName)) {
            if (!@unlink($this->dbName)) {
                throw new \RuntimeException("Can not remove database file {$this->dbFolder}/{$this->dbName}");
            }
        }
    }

    /**
     * @param $siteUrl
     */
    public function clean($siteUrl)
    {
        if (!$this->con) $this->connect($siteUrl);
        $sql = 'DELETE FROM `' . $this->table . '`';
        $this->query($sql);
    }

    /**
     * @param $siteUrl
     * @return bool
     */
    public function hasScan($siteUrl)
    {
        if (!$this->con) $this->connect($siteUrl);
        return !!$this->countLinks($siteUrl);
    }

    /**
     * @param $siteUrl
     * @return array
     */
    public function loadScan($siteUrl)
    {
        if (!$this->con) $this->connect($siteUrl);
        $sql = 'SELECT * FROM `' . $this->table . '`';
        return $this->query($sql)->fetchAll();
    }

    /**
     * @param $siteUrl
     * @return int|string
     */
    public function countLinks($siteUrl)
    {
        if (!$this->con) $this->connect($siteUrl);
        if ($this->linksAmount === 0 && $this->loadLinksAttempt === false) {
            $sql = 'SELECT COUNT(id) FROM `' . $this->table . '`';
            $this->linksAmount = $this->query($sql)->fetchColumn();
            $this->loadLinksAttempt = true;
        }
        return $this->linksAmount;
    }

    /**
     * @param $siteUrl
     * @param $link
     * @return bool
     */
    public function linkIsScanned($siteUrl, $link)
    {
        if (!$this->con) $this->connect($siteUrl);
        $sql = 'SELECT COUNT(id) FROM `' . $this->table . '` WHERE link = ?';
        return !!$this->query($sql, [$link])->fetchColumn();
    }

    /**
     * @param $siteUrl
     * @param $link
     * @param array $data
     * @return PDOStatement
     */
    public function addLink($siteUrl, $link, array $data)
    {
        if (!$this->con) $this->connect($siteUrl);
        if (!array_key_exists('metaRobots', $data) || !array_key_exists('canonical', $data)) {
            throw new \InvalidArgumentException('Can`t create link. Not all of nedded data passed');
        }
        if ($this->loadLinksAttempt) {
            $this->linksAmount++;
        }
        $sql = 'INSERT INTO `' . $this->table . '` (link, modified, meta_robots, canonical) VALUES (?, ?, ?, ?)';
        return $this->query($sql, [$link, $data['modified'], $data['metaRobots'], $data['canonical']], 'while trying to add link');
    }

    /**
     * @param $url
     */
    private function connect($url)
    {
        $this->dbName = "{$this->dbFolder}/{md5($url)}.db";
        $needInit = !file_exists($this->dbName);

        try {
            $this->con = new PDO('sqlite:' . $this->dbName);
        } catch (\PDOException $e) {
            exit($e->getMessage() . " - $this->dbName\n");
        }

        if (is_writable($this->dbName) === false) {
            throw new \RuntimeException('Database ' . $this->dbName . ' doesn`t exist or is not writable');
        }

        if ($needInit) {
            $sql = 'CREATE TABLE `' . $this->table . '` (id INTEGER PRIMARY KEY ASC, link TEXT, modified TEXT, meta_robots TEXT, canonical TEXT)';
            $this->con->exec($sql);
        }
    }

    /**
     * @param $sql
     * @param array $data
     * @param string $message
     * @return PDOStatement
     */
    private function query($sql, array $data = [], $message = '')
    {
        if ($stmt = $this->con->prepare($sql)) {
            if ($stmt->execute($data)) {
                return $stmt;
            }
            throw new \RuntimeException('Could not execute query ' . $message);
        }
        throw new \UnexpectedValueException('Incorrect sql statement ' . $message);
    }
}