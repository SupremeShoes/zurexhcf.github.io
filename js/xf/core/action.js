/** @param {jQuery} $ jQuery Object */
!function($, window, document, _undefined)
{
	"use strict";

	// ################################## ATTRIBUTION HANDLER ###########################################

	XF.AttributionClick = XF.Click.newHandler({
		eventNameSpace: 'XFAttributionClick',
		options: {
			contentSelector: null
		},

		init: function()
		{
		},

		click: function(e)
		{
			var hash = this.options.contentSelector,
				$content = $(hash);

			if ($content.length)
			{
				try
				{
					var top = $content.offset().top;

					if ("pushState" in window.history)
					{
						window.history.pushState({}, '', window.location.toString().replace(/#.*$/, '') + hash);
					}

					$('html, body').animate({ scrollTop: top }, XF.config.speed.normal, function()
					{
						if (!window.history.pushState)
						{
							window.location.hash = hash;
						}
					});
				}
				catch (e)
				{
					window.location.hash = hash;
				}

				e.preventDefault();
			}
		}
	});

	// ################################## LIKE HANDLER ###########################################

	XF.LikeClick = XF.Click.newHandler({
		eventNameSpace: 'XFLikeClick',
		options: {
			likeList: null
		},

		processing: false,

		init: function()
		{
		},

		click: function(e)
		{
			e.preventDefault();

			if (this.processing)
			{
				return;
			}
			this.processing = true;

			var href = this.$target.attr('href'),
				self = this;

			XF.ajax('POST', href, {}, $.proxy(this, 'handleAjax'), {skipDefaultSuccess: true})
				.always(function()
				{
					setTimeout(function()
					{
						self.processing = false;
					}, 250);
				});
		},

		handleAjax: function(data)
		{
			var $target = this.$target;

			if (data.addClass)
			{
				$target.addClass(data.addClass);
			}
			if (data.removeClass)
			{
				$target.removeClass(data.removeClass);
			}
			if (data.text)
			{
				var $label = $target.find('.label');
				if (!$label.length)
				{
					$label = $target;
				}
				$label.text(data.text);
			}

			var $likeList = this.options.likeList ? XF.findRelativeIf(this.options.likeList, $target) : $([]);

			if (typeof data.html !== 'undefined' && $likeList.length)
			{
				if (data.html.content)
				{
					XF.setupHtmlInsert(data.html, function($html, container)
					{
						$likeList.html($html).addClassTransitioned('is-active');
					});
				}
				else
				{
					$likeList.removeClassTransitioned('is-active', function()
					{
						$likeList.empty();
					});
				}
			}
		}
	});

	// ################################## PREVIEW CLICK ###########################################

	XF.PreviewClick = XF.Click.newHandler({
		eventNameSpace: 'XFPreviewClick',
		options: {
			previewUrl: ''
		},

		$form: null,
		$container: null,
		href: null,
		loading: false,

		init: function()
		{
			var $form = this.$target.closest('form'),
				href = $form.data('preview-url') || this.options.previewUrl || this.$target.attr('href');

			if (!href)
			{
				console.error('Preview button must have a href');
				return;
			}

			this.$container = $form.find('.js-previewContainer').first();
			if (!this.$container.length)
			{
				console.error('Preview form must have a .js-previewContainer element');
				return;
			}

			this.href = href;
			this.$form = $form.on('preview:hide', $.proxy(this, 'onPreviewHide'));
		},

		click: function(e)
		{
			if (!this.href)
			{
				return;
			}

			e.preventDefault();

			if (this.loading)
			{
				return;
			}

			var self = this,
				formData = XF.getDefaultFormData(this.$form);

			XF.ajax('post', this.href, formData, $.proxy(this, 'onLoad'))
				.always(function() { self.loading = false; });
		},

		onLoad: function(data)
		{
			if (!data.html)
			{
				return;
			}

			var self = this;

			if (data.html.content)
			{
				XF.setupHtmlInsert(data.html, function($html, container, onComplete)
				{
					self.$container.removeClassTransitioned('is-active', function()
					{
						self.$container.replaceWith($html);
						onComplete();
						$html.addClassTransitioned('is-active');
						self.$container = $html;
					});

					return false;
				});
			}
			else
			{
				$container.xfFadeUp(XF.config.speed.fast);
			}
		},

		onPreviewHide: function(e)
		{
			this.$container.removeClassTransitioned('is-active');
		}
	});

	// ################################## SWITCH HANDLER ###########################################

	XF.handleSwitchResponse = function($target, data, allowRedirect)
	{
		if (data.switchKey)
		{
			var switchActions = $target.data('sk-' + data.switchKey);

			if (switchActions)
			{
				var match, value;
				while (match = switchActions.match(/^(\s*,)?\s*(addClass|removeClass):([^,]+),/))
				{
					switchActions = switchActions.substring(match[0].length);

					value = $.trim(match[3]);
					if (value.length)
					{
						switch (match[2])
						{
							case 'addClass': $target.addClass(value); break;
							case 'removeClass': $target.removeClass(value); break;
						}
					}
				}

				switchActions = $.trim(switchActions);

				if (switchActions.length && !data.text)
				{
					data.text = switchActions;
				}
			}
		}

		if (data.addClass)
		{
			$target.addClass(data.addClass);
		}
		if (data.removeClass)
		{
			$target.removeClass(data.removeClass);
		}

		if (data.text)
		{
			var $label = $target.find($target.data('label'));
			if (!$label.length)
			{
				$label = $target;
			}
			$label.text(data.text);
		}

		if (data.message)
		{
			var doRedirect = (allowRedirect && data.redirect),
				flashLength = doRedirect ? 1000 : 3000;

			XF.flashMessage(data.message, flashLength, function()
			{
				if (doRedirect)
				{
					XF.redirect(data.redirect);
				}
			});
		}
	};

	XF.ScrollToClick = XF.Click.newHandler({
		eventNameSpace: 'XFScrollToClick',
		options: {
			target: null, // specify a target to which to scroll, when href is not available
			silent: false, // if true and no scroll
			hash: null, // override history hash - off by default, use true to use target's ID or string for arbitrary hash value
			speed: 300 // scroll animation speed
		},

		$scroll: null,

		init: function()
		{
			var $scroll,
				hash = this.options.hash,
				targetHref = this.$target.attr('href');

			if (this.options.target)
			{
				$scroll = XF.findRelativeIf(this.options.target, this.$target);
			}
			if (!$scroll || !$scroll.length)
			{
				if (targetHref && targetHref.length && targetHref.charAt(0) == '#')
				{
					$scroll = $(targetHref);
				}
				else if (this.options.silent)
				{
					// don't let an error happen here, just silently ignore
					return;
				}
			}

			if (!$scroll || !$scroll.length)
			{
				console.error('No scroll target could be found');
				return;
			}

			this.$scroll = $scroll;

			if (hash === true || hash === 'true')
			{
				var id = $scroll.attr('id');
				this.options.hash = (id && id.length) ? id : null;
			}
			else if (hash === false || hash === 'false')
			{
				this.options.hash = null;
			}
		},

		click: function(e)
		{
			if (!this.$scroll)
			{
				return;
			}

			e.preventDefault();

			var hash = this.options.hash;

			$('html, body').animate(
				{ scrollTop: this.$scroll.offset().top },
				this.options.speed,
				null,
				function()
				{
					if (hash)
					{
						window.location.hash = hash;
					}
				}
			);
		}
	});

	XF.SwitchClick = XF.Click.newHandler({
		eventNameSpace: 'XFSwitchClick',
		options: {
			redirect: false,
			label: '.js-label'
		},

		processing: false,

		init: function()
		{
			this.$target.data('label', this.options.label);
		},

		click: function(e)
		{
			e.preventDefault();

			if (this.processing)
			{
				return;
			}
			this.processing = true;

			var href = this.$target.attr('href'),
				self = this;

			XF.ajax('POST', href, {}, $.proxy(this, 'handleAjax'), {skipDefaultSuccess: true})
				.always(function()
				{
					setTimeout(function()
					{
						self.processing = false;
					}, 250);
				});
		},

		handleAjax: function(data)
		{
			var $target = this.$target,
				event = $.Event('switchclick:complete');

			$target.trigger(event, data, this);
			if (event.isDefaultPrevented())
			{
				return;
			}

			XF.handleSwitchResponse($target, data, this.options.redirect);
		}
	});

	XF.SwitchOverlayClick = XF.Click.newHandler({
		eventNameSpace: 'XFSwitchOverlayClick',
		options: {
			redirect: false
		},

		overlay: null,

		init: function()
		{
		},

		click: function(e)
		{
			e.preventDefault();

			if (this.overlay)
			{
				this.overlay.show();
				return;
			}

			var href = this.$target.attr('href');

			XF.loadOverlay(href, {
				cache: false,
				init: $.proxy(this, 'setupOverlay')
			});
		},

		setupOverlay: function(overlay)
		{
			this.overlay = overlay;

			var $form = overlay.getOverlay().find('form');

			$form.on('ajax-submit:response', $.proxy(this, 'handleOverlaySubmit'));

			var t = this;
			overlay.on('overlay:hidden', function() { t.overlay = null; });

			return overlay;
		},

		handleOverlaySubmit: function(e, data)
		{
			if (data.status == 'ok')
			{
				e.preventDefault();

				var overlay = this.overlay;
				if (overlay)
				{
					overlay.hide();
				}

				XF.handleSwitchResponse(this.$target, data, this.options.redirect);
			}
		}
	});

	// ################################## DRAFT HANDLER ###########################################

	XF.Draft = XF.Element.newHandler({
		options: {
			draftAutosave: 60,
			draftName: 'message',
			draftUrl: null,

			saveButton: '.js-saveDraft',
			deleteButton: '.js-deleteDraft',
			actionIndicator: '.draftStatus'
		},

		lastActionContent: null,
		autoSaveRunning: false,

		init: function()
		{
			if (!this.options.draftUrl)
			{
				console.error('No draft URL specified.');
				return;
			}

			var self = this;
			this.$target.on(this.options.saveButton, 'click', function(e)
			{
				e.preventDefault();
				self.triggerSave();
			});
			this.$target.on(this.options.deleteButton, 'click', function(e)
			{
				e.preventDefault();
				self.triggerDelete();
			});

			var proxySync = $.proxy(this, 'syncState');

			// set the default value and check it after other JS loads
			this.syncState();
			setTimeout(proxySync, 500);

			this.$target.on('draft:sync', proxySync);

			setInterval($.proxy(this, 'triggerSave'), this.options.draftAutosave * 1000);
		},

		triggerSave: function()
		{
			if (XF.isRedirecting)
			{
				// we're unloading the page, don't try to save any longer
				return;
			}

			var event = $.Event('draft:beforesave');

			this.$target.trigger(event);
			if (event.isDefaultPrevented())
			{
				return;
			}

			this._executeDraftAction(this.getSaveData());
		},

		triggerDelete: function()
		{
			// prevent re-saving the content until it's changed
			this.lastActionContent = this.getSaveData();

			this._sendDraftAction('delete=1');
		},

		_executeDraftAction: function(data)
		{
			if (data == this.lastActionContent)
			{
				return;
			}
			if (this.autoSaveRunning)
			{
				return false;
			}

			this.lastActionContent = data;
			this._sendDraftAction(data);
		},

		_sendDraftAction: function(data)
		{
			this.autoSaveRunning = true;

			var self = this;

			return XF.ajax(
				'post',
				this.options.draftUrl,
				data,
				$.proxy(this, 'completeAction'),
				{ skipDefault: true, skipError: true, global: false }
			).always(
				function() { self.autoSaveRunning = false; }
			);
		},

		completeAction: function(data)
		{
			var event = $.Event('draft:complete');
			this.$target.trigger(event, data);
			if (event.isDefaultPrevented())
			{
				return;
			}

			var $complete = this.$target.find(this.options.actionIndicator);

			$complete.removeClass('is-active').text(data.complete).addClass('is-active');
			setTimeout(function()
			{
				$complete.removeClass('is-active');
			}, 2000);
		},

		syncState: function()
		{
			this.lastActionContent = this.getSaveData();
		},

		getSaveData: function()
		{
			var $target = this.$target;

			$target.trigger('draft:beforesync');
			return $target.serialize()
				.replace(/(^|&)_xfToken=[^&]+(?=&|$)/g, '')
				.replace(/^&+/, '');
		}
	});

	// ################################## FOCUS TRIGGER HANDLER ###########################################

	XF.FocusTrigger = XF.Element.newHandler({
		options: {
			display: null,
			activeClass: 'is-active'
		},

		init: function()
		{
			if (this.$target.attr('autofocus'))
			{
				this.trigger();
			}
			else
			{
				this.$target.one('focusin', $.proxy(this, 'trigger'));
			}
		},

		trigger: function()
		{
			var display = this.options.display;
			if (display)
			{
				var $display = XF.findRelativeIf(display, this.$target);
				if ($display.length)
				{
					$display.addClassTransitioned(this.options.activeClass);
				}
			}
		}
	});

	// ################################## POLL BLOCK HANDLER ###########################################

	XF.PollBlock = XF.Element.newHandler({
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

			if (data.redirect)
			{
				XF.redirect(data.redirect);
			}

			var self = this;
			XF.setupHtmlInsert(data.html, function($html, container)
			{
				$html.hide();
				$html.insertAfter(self.$target);

				self.$target.xfFadeUp(null, function()
				{
					self.$target.remove();

					$html.xfFadeDown();
				});
			});
		}
	});

	// ################################## PREVIEW HANDLER ###########################################

	XF.Preview = XF.Element.newHandler({
		options: {
			previewUrl: null,
			previewButton: 'button.js-previewButton'
		},

		previewing: null,

		init: function()
		{
			var $form = this.$target,
				$button = XF.findRelativeIf(this.options.previewButton, $form);

			if (!this.options.previewUrl)
			{
				console.warn('Preview form has no data-preview-url: %o', $form);
				return;
			}

			if (!$button.length)
			{
				console.warn('Preview form has no preview button: %o', $form);
				return;
			}

			$button.on({
				click: $.proxy(this, 'preview')
			});
		},

		preview: function(e)
		{
			e.preventDefault();

			if (this.previewing)
			{
				return false;
			}
			this.previewing = true;

			var t = this;
			XF.ajax('post', this.options.previewUrl, this.$target.serializeArray(), function(data)
			{
				if (data.html)
				{
					XF.setupHtmlInsert(data.html, function ($html, container, onComplete)
					{
						XF.overlayMessage(container.title, $html);
					});
				}
			}).always(function()
			{
				t.previewing = false;
			});
		}
	});

	XF.ShareButtons = XF.Element.newHandler({
		options: {
			buttons: '.shareButtons-button',
			iconic: '.shareButtons--iconic'
		},

		pageTitle: null,
		pageDesc: null,
		pageUrl: null,

		clipboard: null,

		init: function()
		{
			var buttonSel = this.options.buttons,
				iconic = this.options.iconic;

			this.$target
				.on('focus mouseenter', buttonSel, $.proxy(this, 'focus'))
				.on('click', buttonSel, $.proxy(this, 'click'));

			if (typeof iconic == 'string')
			{
				iconic = this.$target.is(iconic);
			}
			this.$target.find(buttonSel).each(function()
			{
				var $el = $(this);
				if (iconic)
				{
					XF.Element.applyHandler($el, 'element-tooltip', {
						element: '> span'
					});
				}
				if ($el.data('clipboard'))
				{
					if (Clipboard.isSupported())
					{
						$el.removeClass('is-hidden');
					}
				}
			});
		},

		setupPageData: function()
		{
			this.pageTitle = $('meta[property="og:title"]').attr('content');
			if (!this.pageTitle)
			{
				this.pageTitle = $('title').text();
			}

			this.pageUrl = $('meta[property="og:url"]').attr('content');
			if (!this.pageUrl)
			{
				this.pageUrl = window.location.href;
			}

			this.pageDesc = $('meta[property="og:description"]').attr('content');
			if (this.pageDesc)
			{
				this.pageDesc = $('meta[name=description]').attr('content') || '';
			}
		},

		focus: function(e)
		{
			var $el = $(e.currentTarget);

			if ($el.attr('href'))
			{
				return;
			}

			if (!this.pageUrl)
			{
				this.setupPageData();
			}

			var href = $el.data('href');
			if (!href)
			{
				if (!$el.data('clipboard'))
				{
					console.error('No data-href on share button %o', e.currentTarget);
				}
				else
				{
					// this sets a new click handler

					if (!this.clipboard)
					{
						var self = this;

						this.clipboard = new Clipboard($el[0], {
							text: function(trigger)
							{
								return $(trigger).data('clipboard')
									.replace('{url}', self.pageUrl)
									.replace('{title}', self.pageTitle);
							}
						});

						this.clipboard.on('success', function()
						{
							XF.flashMessage(XF.phrase('link_copied_to_clipboard'), 3000);
						});
					}
				}
				return;
			}

			href = href.replace('{url}', encodeURIComponent(this.pageUrl))
				.replace('{title}', encodeURIComponent(this.pageTitle));

			$el.attr('href', href);
		},

		click: function(e)
		{
			var $el = $(e.currentTarget),
				href = $el.attr('href');

			if (!href)
			{
				return;
			}
			if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey)
			{
				return;
			}

			if (href.match(/^https?:/i))
			{
				e.preventDefault();

				var popupWidth = 600,
					popupHeight = 400,
					popupLeft = (screen.width - popupWidth) / 2,
					popupTop = (screen.height - popupHeight) / 2;

				window.open(href, 'share',
					'toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes'
					+ ',width=' + popupWidth + ',height=' + popupHeight
					+ ',left=' + popupLeft + ',top=' + popupTop
				);
			}
		}
	});

	XF.ShareInput = XF.Element.newHandler({
		options: {
			button: '.js-shareButton',
			input: '.js-shareInput'
		},

		init: function()
		{
			var $button = this.$target.find(this.options.button),
				$input = this.$target.find(this.options.input);

			if (Clipboard.isSupported())
			{
				$button.removeClass('is-hidden');
			}

			var clipboard = new Clipboard($button[0], {
				target: function(trigger)
				{
					return $input[0];
				}
			});
			clipboard.on('success', $.proxy(this, 'success'));

			$input.on('click', $.proxy(this, 'click'));
		},

		success: function()
		{
			XF.flashMessage(XF.phrase('text_copied_to_clipboard'), 3000);
		},

		click: function(e)
		{
			$(e.target).select();
		}
	});

	XF.Click.register('attribution', 'XF.AttributionClick');
	XF.Click.register('like', 'XF.LikeClick');
	XF.Click.register('preview-click', 'XF.PreviewClick');
	XF.Click.register('scroll-to', 'XF.ScrollToClick');
	XF.Click.register('switch', 'XF.SwitchClick');
	XF.Click.register('switch-overlay', 'XF.SwitchOverlayClick');

	XF.Element.register('draft', 'XF.Draft');
	XF.Element.register('focus-trigger', 'XF.FocusTrigger');
	XF.Element.register('poll-block', 'XF.PollBlock');
	XF.Element.register('preview', 'XF.Preview');
	XF.Element.register('share-buttons', 'XF.ShareButtons');
	XF.Element.register('share-input', 'XF.ShareInput');
}
(jQuery, window, document);