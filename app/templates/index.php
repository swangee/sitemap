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
  <h1 class="text-center">
    Самый честный генератор карты сайта
  </h1>
  <div class="row">
    <div class="col-lg-8 col-lg-offset-2">
      <div class="well">
        <form class="generator-form form-horizontal" >
          <fieldset>
          <div class="form-group">
              <label class="col-lg-3 control-label" for="email">Email</label>
              <div class="col-lg-9">
                <input type="email" class="form-control" placeholder="Введите Email" id="email" data-validate="required,email">
              </div>
            </div>
            <div class="form-group">
              <label class="col-lg-3 control-label" for="url">URL</label>
              <div class="col-lg-9">
                <input type="url" class="form-control" placeholder="Введите URL" id="url" data-validate="required,url">
              </div>
            </div>
            <div class="form-group">
              <label class="col-lg-3 control-label" for="links-amount">Количество ссылок</label>
              <div class="col-lg-9">
                <select class="form-control" id="links-amount" data-parsley-type="number">
                  <option value>Выбирите количество</option>
                  <option value="100">100</option>
                  <option value="500">500</option>
                  <option value="1000">1000</option>
                  <option value="1500">1500</option>
                  <option value="2000">2000</option>
                  <option value="3000">3000</option>
                </select>
              </div>
            </div>
          </fieldset>
          <input type="submit" class="btn btn-success btn-sm btn-block" value="Начать!">
        </form>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript" src="/js/jquery-2.1.3.min.js"></script>
<script type="text/javascript" src="/js/bootstrap.min.js"></script>
<script type="text/javascript" src="/js/verify.notify.min.js"></script>
<script type="text/javascript" src="/js/main.js"></script>
</body>
</html>