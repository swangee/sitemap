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
            alert(response.sitemap)
          }
        });
    });
  });

})(jQuery);