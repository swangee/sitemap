(function($) {

  $('.generator-form [type=submit]').on('click', function(e) {
    e.preventDefault();

    var url = $('.generator-form #url').val();
    var limit = $('.generator-form #links-amount').val();
    var error = false;
    var email = $('.generator-form #email').val();

    if (url.indexOf('.') === -1 || url.length < 5) {
      $('.generator-form #url').parent().addClass('has-error');
      error = true;
    } else {
      $('.generator-form #url').parent().removeClass('has-error');
      url = $('.generator-form #protocol').val() + url.replace('http://', '').replace('https://', '');
    }
    if (email.indexOf('@') === -1 || email.length < 4) {
      $('.generator-form #email').parent().addClass('has-error');
      error = true;
    } else {
      $('.generator-form #email').parent().removeClass('has-error');
    }

    if (!error) {
      $.ajax({
        url: '/start',
        type: 'POST',
        data: {
          url: url,
          email: email,
          csrfToken: $('meta[name=_csrf]').attr('content'),
          linksAmount: limit
        },
        dataType: 'json',
        success: function(response) {
          localStorage.setItem('processToken', response.processToken);
          console.log(response);
        }
      })
    }
  });

  $('.protocol-select li').on('click', function() {
    $('.generator-form #protocol').val($(this).text());
  });

  $('.check-btn').on('click', function() {
    if (token = localStorage.getItem('processToken')) {
      $.ajax({
        url: '/processing',
        type: 'POST',
        data: {
          processToken: token
        },
        dataType: 'json',
        success: function(response) {
          console.log(response);
        }
      })
    }
  });

  function getState(url) {
    var finished = false;
    var html = '<h3>Процесс парсинга</h3>';
    html += '<p class="message"></p>';
    $('.generator-form').replaceWith(html);
    var interval = setInterval(function() {
      $.ajax({
        url: '/processing',
        type: 'POST',
        data: {
          url: url
        },
        dataType: 'json',
        success: function(response) {
          if (response.finished) {
            finished = true;
            $('.message').html('Завершено. Ссылка на XML: <a href="' + response.xml + '">открыть</a>');
            clearInterval(interval);
          } else {
            $('.message').html('Просканировано ' + response.added + ' из ' + response.limit + ' ссылок');
          }
        }
      });
    }, 3000);
  }

})(jQuery);