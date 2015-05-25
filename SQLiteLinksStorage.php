<?php
namespace vedebel\sitemap;

class SQLiteLinksStorage implements LinksStorage
{
  private $con;
  private $table = 'links';
  private $dbName = null;
  private $linksAmount = 0;
  private $loadLinksAttempt = false;

  public function __destruct()
  {
    if (file_exists($this->dbName)) {
      unlink($this->dbName);
    }
  }

  public function clean($siteUrl)
  {
    if (!$this->con) $this->connect($siteUrl);
    $sql = 'DELETE FROM `' . $this->table . '`';
    $this->query($sql);
  }

  public function hasScan($siteUrl)
  {
    if (!$this->con) $this->connect($siteUrl);
    return !!$this->countLinks($siteUrl);
  }

  public function loadScan($siteUrl)
  {
    if (!$this->con) $this->connect($siteUrl);
    $sql = 'SELECT * FROM `' . $this->table . '`';
    return $this->query($sql)->fetchAll();
  }

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

  public function linkIsScanned($siteUrl, $link)
  {
    if (!$this->con) $this->connect($siteUrl);
    $sql = 'SELECT COUNT(id) FROM `' . $this->table . '` WHERE link = ?';
    return !!$this->query($sql, [$link])->fetchColumn();
  }

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

  private function connect($url)
  {
    $this->dbName = dirname(__FILE__) . '/tmp/' . md5($url) . '.db';
    $needInit = !file_exists($this->dbName);

    if (!file_exists(dirname(__FILE__) . '/tmp/')) {
      mkdir(dirname(__FILE__) . '/tmp/');
    }

    try {
      $this->con = new \PDO('sqlite:' . $this->dbName);
    } catch (\PDOException $e) {
      exit($e->getMessage() . " - $dbName\n");
    }

    if (is_writable($this->dbName) === false) {
      throw new \RuntimeException('Database ' . $this->dbName . ' doesn`t exist or is not writable');
    }

    if ($needInit) {
      $sql = 'CREATE TABLE `' . $this->table . '` (id INTEGER PRIMARY KEY ASC, link TEXT, modified TEXT, meta_robots TEXT, canonical TEXT)';
      $this->con->exec($sql);
    }
  }

  private function query($sql, array $data = [], $message = '')
  {
    if ($stmt = $this->con->prepare($sql)) {
      if ($stmt->execute($data)) {
        return $stmt;
      }
      throw new \RuntimeException('Couldn`t execute query ' . $message);
    }
    throw new \UnexpectedValueException('Incorrect sql statement ' . $message);
  }
}