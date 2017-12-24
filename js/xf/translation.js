!function($, window, document, _undefined)
{
	"use strict";

	XF.TranslateSubmit = XF.Element.newHandler({
		options: {},

		init: function()
		{
			this.$target.on('ajax-submit:response', $.proxy(this, 'afterSubmit'));
		},

		afterSubmit: function(e, data)
		{
			if (data.errors || data.exception)
			{
				return;
			}

			e.preventDefault();

			if (data.message)
			{
				XF.flashMessage(data.message, 2000);
			}

			var self = this;
			XF.setupHtmlInsert(data.html, function($html, container, onComplete)
			{
				$html.hide();
				self.$target.xfFadeUp(XF.config.speed.normal, function()
				{
					self.$target.replaceWith($html);
					$html.xfFadeDown(XF.config.speed.normal);
				});
			});
		}
	});

	XF.Element.register('translate-submit', 'XF.TranslateSubmit');
}
(jQuery, window, document);