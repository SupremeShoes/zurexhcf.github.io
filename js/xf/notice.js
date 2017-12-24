!function($, window, document, _undefined)
{
	"use strict";

	XF.Notices = XF.Element.newHandler({
		options: {
			type: 'block',
			target: '.js-notice',
			scrollInterval: 5
		},

		$notices: null,
		slider: null,
		dismissing: false,

		init: function()
		{
			this.updateNoticeList();
			this.filter();
			if (!this.handleNoticeListChange())
			{
				return;
			}

			this.$target.on('click', '.js-noticeDismiss', $.proxy(this, 'dismiss'));

			this.start();
		},

		updateNoticeList: function()
		{
			// clone elements are from lightslider
			this.$notices = this.$target.find(this.options.target).not('.clone');
			return this.$notices;
		},

		handleNoticeListChange: function()
		{
			var length = this.$notices.length;

			if (!length)
			{
				if (this.slider)
				{
					this.slider.destroy();
					this.slider = null;
				}

				this.$target.remove();
			}
			else if (length == 1)
			{
				this.$target.removeClass('notices--isMulti');
			}

			return length;
		},

		filter: function()
		{
			var dismissed = this.getCookies(),
				modified = false;

			this.$notices.each(function()
			{
				var $notice = $(this),
					id = parseInt($notice.data('notice-id'), 10),
					visibility = $notice.data('visibility');

				if (dismissed)
				{
					if (id && $.inArray(id, dismissed) != -1)
					{
						$notice.remove();
						modified = true;
					}
				}

				if (visibility)
				{
					if ($notice.css('display') == 'none')
					{
						$notice.remove();
						modified = true;
					}
					else
					{
						// we only remove these based on the width at load
						$notice.addClass('is-vis-processed');
					}
				}
			});

			if (modified)
			{
				this.updateNoticeList();
			}
		},

		start: function()
		{
			var self = this,
				$notices = this.$notices,
				noticeType = this.options.type;

			if (noticeType == 'floating')
			{
				$notices.each(function()
				{
					var $notice = $(this),
						displayDuration = $notice.data('display-duration'),
						delayDuration = $notice.data('delay-duration'),
						autoDismiss = $notice.data('auto-dismiss');

					if (delayDuration)
					{
						setTimeout(function()
						{
							self.displayFloating($notice, XF.config.speed.normal, displayDuration, autoDismiss);
						}, delayDuration);
					}
					else
					{
						self.displayFloating($notice, XF.config.speed.fast, displayDuration, autoDismiss);
					}
				});
			}
			else if (noticeType == 'scrolling' && this.$notices.length > 1)
			{
				if ($.fn.lightSlider)
				{
					if (!Modernizr.flexbox)
					{
						var height = 0;
						this.$notices.each(function()
						{
							height = Math.max($(this).outerHeight(), height);
						});
						this.$notices.css('height', height);
					}

					this.slider = this.$target.lightSlider({
						item: 1,
						addClass: 'noticeScrollContainer',
						slideMargin: 0,
						galleryMargin: 0,
						controls: false,
						auto: true,
						pause: this.options.scrollInterval * 1000,
						speed: Modernizr.flexbox ? 400 : 0, // older IE has some animation issues
						pauseOnHover: true,
						loop: true,
						rtl: XF.isRtl(),
						enableDrag: false
					});

					$(window).on('resize', $.proxy(this, 'refreshSlider'));
				}
				else
				{
					console.error('Lightslider must be loaded first.');
				}
			}
		},

		displayFloating: function($notice, speed, duration, autoDismiss)
		{
			$notice.xfFadeDown(speed, function()
			{
				if (duration)
				{
					setTimeout(function()
					{
						$notice.xfFadeUp(XF.config.speed.normal);

						if (autoDismiss)
						{
							$notice.find('a.js-noticeDismiss').trigger('click');
						}
					}, duration);
				}
			});
		},

		getCookies: function()
		{
			if (XF.config.userId)
			{
				return;
			}

			var cookieName = 'notice_dismiss',
				cookieValue = XF.Cookie.get(cookieName),
				cookieDismissed = cookieValue ? cookieValue.split(',') : [],
				values = [],
				id;

			for (var i = 0; i < cookieDismissed.length; i++)
			{
				id = parseInt(cookieDismissed[i], 10);
				if (id)
				{
					values.push(id);
				}
			}

			return values;
		},

		dismiss: function(e)
		{
			e.preventDefault();

			if (this.dismissing)
			{
				return;
			}

			this.dismissing = true;

			var t = this,
				$target = $(e.target),
				$notice = $target.parents('.js-notice'),
				noticeId = parseInt($notice.data('notice-id'), 10),
				cookieName = 'notice_dismiss',
				userId = XF.config.userId,
				dismissed = t.getCookies();

			if (!userId)
			{
				if (noticeId && $.inArray(noticeId, dismissed) == -1)
				{
					dismissed.push(noticeId);
					dismissed.sort(function(a, b) { return (a - b); });

					XF.Cookie.set(cookieName, dismissed.join(','));
				}
			}
			else
			{
				XF.ajax(
					'post',
					$target.attr('href'), {},
					function() {},
					{ skipDefault: true }
				);
			}

			this.removeNotice($notice);

			this.dismissing = false;
		},

		removeNotice: function($notice)
		{
			var self = this;

			if (this.slider)
			{
				var total = this.$notices.length,
					current = this.slider.getCurrentSlideCount(),
					removeSlide = function()
					{
						$notice.remove();
						self.updateNoticeList();
						if (self.handleNoticeListChange())
						{
							self.refreshSlider();
							self.slider.goToSlide(current);
						}
					};

				if (total > 1)
				{
					if (current >= self.slider.getTotalSlideCount())
					{
						current = 1;
					}
					this.slider.goToNextSlide();
					setTimeout(removeSlide, 500);
				}
				else
				{
					removeSlide();
				}
			}
			else
			{
				$notice.xfFadeUp(XF.config.speed.fast, function()
				{
					$notice.remove();
					self.updateNoticeList();
					self.handleNoticeListChange();
				});
			}
		},

		refreshSlider: function()
		{
			this.$target.css('height', '');
			this.slider.refresh();
		}
	});

	XF.Element.register('notices', 'XF.Notices');
}
(jQuery, window, document);