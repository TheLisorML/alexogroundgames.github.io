var header = $('.header'),
	scrollPrev = 0;

$(window).scroll(function(){
      if ($(this).scrollTop() > 721) {
          $('.header').addClass('headerfixed');
          $('.header').addClass('b-show');
      } else {
          $('.header').removeClass('headerfixed');
          $('.header').removeClass('b-show');
      }
});

$(document).ready(function () {
    $("#howToPlay").click(function () {
        var elementClick = $(this).attr("href");
        var destination = $(elementClick).offset().top;
        if ($.browser.safari) {
            $('body').animate({ scrollTop: destination }, 1100); //1100 - скорость прокрутки
        } else {
            $('html').animate({ scrollTop: destination }, 1100);
        }
        return false; 
    });
});