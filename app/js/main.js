(function($) {

  $('.generator-form [type=submit]').on('click', function(e) {
    e.preventDefault();

    $('.generator-form').validate(function(form) {
      var form = $('.generator-form');
      var url = form.find('#url').val(),
         data = {},
         email = form.find('#email').val(),
         linksAmount = form.find('#links-amount').val();

        data.url = url;
        data.email = email;
        data.linksAmount = linksAmount;
        data.csrfToken = $('meta[name=_csrf]').attr('content');

        $.ajax({
          url: '/start',
          type: 'POST',
          data: data,
          dataType: 'json',
          success: function(response) {
            
          }
        });

        getState(data.url);
    });
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