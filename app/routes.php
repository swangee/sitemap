<?php

use Symfony\Component\Process\Process;

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
      $sitemap = [];
      $notProccessing = true;
      if ($notProccessing) {
        $xmlName = dirname(__FILE__) . '/tmp/' . md5($url) . '_' . time() . '.xml';
        $processString = dirname(__FILE__) . '/../private/generate.php --url ' . $url . ' --dest ' . $xmlName . ' --limit' . $linksAmount; // . ' --debug';
        //echo $processString;exit;
        $process = new Process('php ' . $processString);
        $process->start();
        $token = chr(mt_rand(97 ,122)) . substr(md5(time()), 1);
        $sitemap = [
          'url' => $url,
          'file' => $xmlName,
          'limit' => $linksAmount,
          'process' => $process->getPid(),
          'link' => '/tmp/' . md5($url) . '_' . time() . '.xml'
        ];
        $container['redis']->set('generation_' . $token, json_encode($sitemap));
      }
      
      echo json_encode(array('url' => $url, 'email' => $email, 'linksAmount' => $linksAmount, 'process' => $process->getPid(), 'processToken' => $token));
    }
});

$app->post('/processing', function() use ($app, $container) {
    session_start();
    session_write_close();
    $processToken = $app->request->post('processToken');
    $csrfToken = base64_encode(openssl_random_pseudo_bytes(32));
    $sitemap = json_decode($container['redis']->get('generation_' . $processToken), true);
    $process = $sitemap['process'];
    if (file_exists($sitemap['file'])) {
      echo json_encode(array('finished' => 1, 'xml' => $sitemap['link']));
    } else {
      $parser = new \PHPHtmlParser\Dom();
      $storage = new \Vedebel\Sitemap\SQLiteLinksStorage();
      $generator = new Vedebel\Sitemap\Sitemap($parser, $storage, $sitemap['url']);
      echo json_encode(array('finished' => 0, 'limit' => $sitemap['limit'], 'process' => $process, 'added' => $generator->linksAdded()));
    }
});