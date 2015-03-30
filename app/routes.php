<?php

use Cocur\BackgroundProcess\BackgroundProcess;

$app = $container['app'];

$app->get('/', function() use ($app, $container) {
    $message = 'This is home page1';
    $csrfToken = base64_encode(openssl_random_pseudo_bytes(32));
    session_start();
    $_SESSION['csrfToken'] = $csrfToken;
    session_write_close();
    $app->render('index.php', ['message' => $message, 'csrfToken' => $csrfToken]);
});

$app->post('/start', function() use ($app, $container) {
    session_start();
    session_write_close();
    $url = $app->request->post('url');
    $email = $app->request->post('email');
    $rescan = $app->request->post('rescan');
    $linksAmount = $app->request->post('linksAmount');
    $passedToken = $app->request->post('csrfToken');

    if (!isset($_SESSION['csrfToken']) || empty($passedToken) || $passedToken !== $_SESSION['csrfToken']) {
      $app->response->setStatus(400);
      unset($_SESSION['csrfToken']);
      echo json_encode(array('error' => 'Incorrect CSRFToken'));
    } else {
      if (!($sitemap = $container['redis']->get('sitemap'))) {
        $sitemap = [];
      } else {
        $sitemap = json_decode($sitemap, true);
      }
      $sitemap = [];
      if (!isset($sitemap[md5($url)])) {
        $xmlName = dirname(__FILE__) . '/tmp/' . md5($url) . '_' . time() . '.xml';
        $parser = new \PHPHtmlParser\Dom();
        $storage = new \Vedebel\Sitemap\SQLiteLinksStorage();
        $generator = new Vedebel\Sitemap\Sitemap($parser, $storage, $url, ['limit' => $linksAmount]);
        $generator->setLoader(new \Curl\Curl);
        $generator->crawl();
        $generator->saveXml($xmlName);
        $sitemap[md5($url)] = [
          'url' => $url,
          'limit' => $linksAmount,
          'file' => $xmlName,
          'link' => '/tmp/' . md5($url) . '_' . time() . '.xml'
        ];
        $container['redis']->set('sitemap', json_encode($sitemap));
      }
      
      echo json_encode(array('url' => $url, 'email' => $email, 'linksAmount' => $linksAmount, 'id' => md5($url)));
    }
});

$app->post('/processing', function() use ($app, $container) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    session_start();
    session_write_close();
    $url = $app->request->post('url');
    $url_id = md5($url);
    $csrfToken = base64_encode(openssl_random_pseudo_bytes(32));
    $sitemap = json_decode($container['redis']->get('sitemap'), true);
    $sitemap = $sitemap[$url_id];
    if (file_exists($sitemap['file'])) {
      echo json_encode(array('finished' => 1, 'xml' => $sitemap['link']));
    } else {
      $parser = new \PHPHtmlParser\Dom();
      $storage = new \Vedebel\Sitemap\SQLiteLinksStorage();
      $generator = new Vedebel\Sitemap\Sitemap($parser, $storage);
      echo json_encode(array('finished' => 0, 'limit' => $sitemap['limit'], 'added' => $generator->linksAdded()));
    }
});