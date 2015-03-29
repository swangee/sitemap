<?php

use Vedebel\Sitemap\SQLiteLinksStorage;

class SQLiteLinksStorageTest extends \PHPUnit_Framework_TestCase
{
    private $storage;
    private $url;
    protected function setUp()
    {
        $this->url = 'http://coffee2go.com.ua/';
        $this->storage = new SQLiteLinksStorage;
    }

    protected function tearDown()
    {
    }

    // tests
    public function testHasScan()
    {
        $this->assertTrue($this->storage->hasScan($this->url));
    }

    public function testLinkIsScanned()
    {
        $this->assertTrue($this->storage->linkIsScanned($this->url, $this->url . 'contacts'));
    }

    public function testLinkIsNotScannedTest()
    {
        $this->assertFalse($this->storage->linkIsScanned($this->url, $this->url . 'ololo'));
    }
}