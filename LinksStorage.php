<?php
namespace Vedebel\Sitemap;

interface LinksStorage
{
  public function clean($siteUrl);
  public function hasScan($siteUrl);
  public function loadScan($siteUrl);
  public function countLinks($siteUrl);
  public function linkIsScanned($siteUrl, $link);
  public function addLink($siteUrl, $link, array $data);
}