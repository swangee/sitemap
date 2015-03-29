<?php

use Cocur\BackgroundProcess\BackgroundProcess;

$app = $container['app'];

$app->get('/', function() use ($app, $container) {
    $message = 'This is home page';
    $csrfToken = base64_encode(openssl_random_pseudo_bytes(32));
    $_SESSION['csrfToken'] = $csrfToken;
    $app->render('index.php', ['message' => $message, 'csrfToken' => $csrfToken]);
});

$app->post('/start', function() use ($app, $container) {
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
        $xmlName = 'tmp/' . md5($url) . '_' . time() . '.xml';
        $id = md5($url);
        $process = new BackgroundProcess(
            'php ' . dirname(__FILE__) . str_replace('/', DIRECTORY_SEPARATOR, '/../private/generate.php') 
            . ' --url=' . $url . ' --dest=' . $xmlName . ' --limit=' . $linksAmount . ' --rescan' .  ' --debug'
        );
        $process->run();
        $sitemap[$id] = [
          'xmlName' => '/' . $xmlName,
          'redirect' => '/processing/' . $id,
          'process' => $process->getPid(),
          'email' => $email,
          'ip' => $app->request->getIp()
        ];
        $container['redis']->set('sitemap', json_encode($sitemap));
      }
      echo json_encode(array('url' => $url, 'email' => $email, 'linksAmount' => $linksAmount, 'redirect' => $sitemap[md5($url)]['redirect']));
    }
});

$app->get('/processing/:url_id', function($url_id) use ($app, $container) {
    $sitemap = json_decode($container['redis']->get('sitemap'), true);
    $app->render('processing.php', ['process' => $sitemap[$url_id]]);
});