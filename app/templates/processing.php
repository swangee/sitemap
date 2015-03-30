<?php 
  error_reporting(E_ALL);
  ini_set('display_erros', 1);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Честный Sitemap генератор</title>
  <link rel="stylesheet" type="text/css" href="/css/bootstrap.min.css">
  <link rel="stylesheet" type="text/css" href="/css/usebootstrap.css">
  <meta name="_csrf" content="<?php echo $csrfToken; ?>">
</head>
<body>

<div class="container">
  <?php if ($process): ?>
    Просканировано <?php echo $process['added']; ?> из <?php echo $process['max']; ?>
  <?php else: ?>
    <h1>Парсинг завершен</h1>
  <?php endif; ?>
</div>

<script type="text/javascript" src="/js/jquery-2.1.3.min.js"></script>
<script type="text/javascript" src="/js/bootstrap.min.js"></script>
<script type="text/javascript" src="/js/verify.notify.min.js"></script>
<script type="text/javascript" src="/js/main.js"></script>
</body>
</html>