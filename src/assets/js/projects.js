window.addEventListener('load', function () {
	$('.project-coming-soon').hover(function () {
		$(this).find('.project-coming-soon-ribbon').fadeIn()
	}, function () {
		$(this).find('.project-coming-soon-ribbon').hide()
	}), $('.project-logo').click(function () {
		null !== $(this).attr('data-bind-href') && window.open($(this).attr('data-bind-href'))
	})
});
