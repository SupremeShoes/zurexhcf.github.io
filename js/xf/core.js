var XF = window.XF || {};

if (window.jQuery === undefined) jQuery = $ = {};

!function($, window, document)
{
	"use strict";

	// already loaded, don't load this twice
	if (XF.activate)
	{
		console.error('XF core has been double loaded');
		return;
	}

	if (!XF.browser)
	{
		XF.browser = {
			browser: '',
			version: 0,
			os: '',
			osVersion: null
		};
	}

	// ################################# JQUERY EXTENSIONS #######################################

	(function()
	{
		// approach inspired by Bootstrap / http://blog.alexmaccaw.com/css-transitions
		var support = (function()
		{
			var el = document.createElement('fake'),
				transitions = {
					WebkitTransition: 'webkitTransitionEnd',
					MozTransition: 'transitionend',
					OTransition: 'oTransitionEnd otransitionend',
					transition: 'transitionend'
				};

			for (var name in transitions)
			{
				if (el.style[name] !== undefined)
				{
					return {end: transitions[name] };
				}
			}

			return false;
		})();

		$.support.transition = support;
		if ($.support.transition)
		{
			$.event.special.xfTransitionEnd = {
				bindType: support.end,
				delegateType: support.end,
				handle: function(e)
				{
					if ($(e.target).is(this))
					{
						return e.handleObj.handler.apply(this, arguments);
					}
				}
			};
		}

		var dir = $('html').attr('dir'),
			isRtl = (dir && dir.toUpperCase() == 'RTL'),
			scrollLeftType = 'normal';

		if (isRtl)
		{
			var $tester = $('<div style="width: 80px; height: 40px; font-size: 30px; overflow: scroll; white-space: nowrap; word-wrap: normal; position: absolute; top: -1000px; visibility: hidden; pointer-events: none">MMMMMMMMMM</div>'),
				tester = $tester[0];

			$tester.appendTo('body');

			if (tester.scrollLeft > 0)
			{
				// max value at start, scrolls towards 0
				scrollLeftType = 'inverted';
			}
			else
			{
				tester.scrollLeft = -1;
				if (tester.scrollLeft == -1)
				{
					// 0 at start, scrolls towards negative values
					scrollLeftType = 'negative';
				}
				// else normal: 0 at start, scrolls towards positive values
			}

			$tester.remove();
		}

		$.support.scrollLeftType = scrollLeftType;
	})();

	$.fn.extend(
	{
		/**
		 * Allows an element to respond to an event fired by a parent element, such as the containing tab being un-hidden
		 *
		 * @param eventType
		 * @param callback
		 * @param once  bool    True if you want this to execute once only
		 * @returns {onWithin}
		 */
		onWithin: function(eventType, callback, once)
		{
			var $this = this;

			$(document).on(eventType, function(e)
			{
				if ($(e.target).has($this).length)
				{
					if (once)
					{
						$(document).off(e);
					}
					callback(e);
				}
			});

			return this;
		},

		oneWithin: function(eventType, callback)
		{
			return this.onWithin(eventType, callback, true);
		},

		onPassive: function(eventType, callback, dontUseThis)
		{
			if (typeof eventType == 'object')
			{
				for (var type in eventType)
				{
					this.onPassive(type, eventType[type]);
				}
				return this;
			}

			if (typeof eventType != 'string' || typeof callback != 'function' || typeof dontUseThis != 'undefined')
			{
				console.warn('$.onPassive failure for %s.on%s, check parameters', this.get(0), eventType);
				return this;
			}

			if (eventType.indexOf('.') !== -1)
			{
				console.warn('$.onPassive does not support namespaced events %s.on%s', this.get(0), eventType);
				return false;
			}

			if (Modernizr.passiveeventlisteners)
			{
				this.get(0).addEventListener(eventType, callback);
				return this;
			}
			else
			{
				return this.on(eventType, callback);
			}
		},

		offPassive: function(eventType, callback)
		{
			this.get(0).removeEventListener(eventType, callback);
			return this;
		},

		xfFadeDown: function(speed, callback)
		{
			this.filter(':hidden').hide().css('opacity', 0);

			speed = speed || XF.config.speed.normal;

			this.find('.is-sticky').addClass('was-sticky').removeClass('is-sticky');

			this
				.slideDown({
					duration: speed,
					easing: 'swing',
					complete: function()
					{
						$(this).find('.was-sticky').addClass('is-sticky').removeClass('was-sticky');
						if (callback)
						{
							callback();
						}
						XF.layoutChange();
					},
					queue: false
				})
				.animate(
					{ opacity: 1 },
					{
						duration: speed,
						queue: false
					}
				);

			return this;
		},

		xfFadeUp: function(speed, callback)
		{
			speed = speed || XF.config.speed.normal;

			this.find('.is-sticky').addClass('was-sticky').removeClass('is-sticky');

			this
				.slideUp({
					duration: Math.max(speed, speed),
					easing: 'swing',
					complete: function()
					{
						$(this).find('.was-sticky').addClass('is-sticky').removeClass('was-sticky');
						if (callback)
						{
							callback();
						}
						XF.layoutChange();
					},
					queue: false
				})
				.animate(
					{ opacity: 0 },
					{
						duration: speed,
						queue: false
					}
				);
		},

		xfUniqueId: function()
		{
			var id = this.attr('id');
			if (!id)
			{
				id = 'js-XFUniqueId' + XF.counter;
				XF.counter++;
				this.attr('id', id);
			}

			return id;
		},

		findExtended: function(selector)
		{
			var match;
			if (typeof selector === 'string' && (match = selector.match(/^<([^|]+)(\|([\s\S]+))?$/)))
			{
				var lookUp = $.trim(match[1]),
					innerMatch,
					i,
					relativeLookup = {
						up: 'parent',
						next: 'next',
						prev: 'prev'
					},
					move,
					$newBase = this;

				do
				{
					if (innerMatch = lookUp.match(/^:(up|next|prev)(\((\d+)\))?/))
					{
						if (!innerMatch[2])
						{
							innerMatch[3] = 1;
						}

						move = relativeLookup[innerMatch[1]];

						for (i = 0; i < innerMatch[3]; i++)
						{
							$newBase = $newBase[move]();
							if (!$newBase)
							{
								$newBase = $();
							}
						}

						lookUp = $.trim(lookUp.substr(innerMatch[0].length));
					}
				}
				while (innerMatch);

				if (lookUp.length)
				{
					$newBase = $newBase.closest(lookUp);
				}

				if (!$newBase.length)
				{
					$newBase = $();
				}

				selector = match[2] ? $.trim(match[3]) : '';

				if (selector.length)
				{
					return $newBase.find(selector);
				}
				else
				{
					return $newBase;
				}
			}

			return this._find(selector);
		},

		dimensions: function(outer, outerWithMargin)
		{
			var dims = this.offset(),
				dimensions = {
					top: dims.top,
					left: dims.left
					// offset may return a ClientRect object which is read only
				};

			outerWithMargin = outerWithMargin ? true : false;

			dimensions.width = outer ? this.outerWidth(outerWithMargin) : this.width();
			dimensions.height = outer ? this.outerHeight(outerWithMargin) : this.height();
			dimensions.right = dimensions.left + dimensions.width;
			dimensions.bottom = dimensions.top + dimensions.height;

			return dimensions;
		},

		viewport: function(outer, outerWithMargin)
		{
			var vp = {
				width: outer ? this.outerWidth(outerWithMargin) : this.width(),
				height: outer ? this.outerHeight(outerWithMargin) : this.height(),
				left: this.scrollLeft(),
				top: this.scrollTop(),
				right: 0,
				bottom: 0,
				docWidth: $(document).width(),
				docHeight: $(document).height()
			};

			vp.bottom = vp.top + vp.height;
			vp.right = vp.left + vp.width;

			return vp;
		},

		hasFixableParent: function()
		{
			var fixableParent = false;

			this.parents().each(function()
			{
				var $el = $(this);

				switch ($el.css('position'))
				{
					case 'fixed':
					case 'sticky':
					case '-webkit-sticky':
						fixableParent = $el;
						return false;
				}

				if ($el.data('sticky_kit'))
				{
					fixableParent = $el;
					return false;
				}
			});

			return fixableParent;
		},

		hasFixedParent: function()
		{
			var fixedParent = false;

			this.parents().each(function()
			{
				var $el = $(this);

				switch ($el.css('position'))
				{
					case 'fixed':
					{
						fixedParent = $el;
						return false;
					}

					case 'sticky':
					case '-webkit-sticky':
					{
						var elDimensions = $el.dimensions(true),
							viewport = $(window).viewport(),
							stickyTop = $el.css('top'),
							stickyBottom = $el.css('bottom'),
							edgeDiff;

						// If an element is sticky, it is 'stuck' when its "interesting" edge is exactly at its offset position.
						// This code currently supports only sticky/top and sticky/bottom

						if (stickyTop !== 'auto')
						{
							// iOS seems to have half a pixel inconsistency in reporting, so give a little leeway
							edgeDiff = (elDimensions.top - viewport.top) - parseInt(stickyTop, 10);
							if (edgeDiff <= 0.5 && edgeDiff >= -0.5)
							{
								fixedParent = $el;
								return false;
							}
						}

						if (stickyBottom !== 'auto')
						{
							// iOS seems to have half a pixel inconsistency in reporting, so give a little leeway
							edgeDiff = (elDimensions.bottom - viewport.bottom) - parseInt(stickyBottom, 10);
							if (edgeDiff <= 0.5 && edgeDiff >= -0.5)
							{
								fixedParent = $el;
								return false;
							}
						}
					}
				}
			});

			return fixedParent;
		},

		onTransitionEnd: function(duration, callback)
		{
			var called = false,
				$el = this,
				f = function()
				{
					if (called)
					{
						return;
					}

					called = true;
					return callback.apply(this, arguments);
				};

			this.one('xfTransitionEnd', f);
			setTimeout(function()
			{
				if (!called)
				{
					$el.trigger('xfTransitionEnd');
				}
			}, duration + 10);

			return this;
		},

		autofocus: function()
		{
			var $input = $(this);

			if (XF.isIOS())
			{
				if (!$input.is(':focus'))
				{
					$input.addClass('is-focused');
					$input.on('blur', function()
					{
						$input.removeClass('is-focused');
					});
				}
			}
			else
			{
				$input.focus();
			}

			return this;
		},

		normalizedScrollLeft: function(newLeft)
		{
			var type = $.support.scrollLeftType;

			if (typeof newLeft !== 'undefined')
			{
				for (var i = 0; i < this.length; i++)
				{
					(function(el, newValue)
					{
						switch (type)
						{
							case 'negative':
								newValue = newValue > 0 ? -newValue : 0;
								break;

							case 'inverted':
								newValue = el.scrollWidth - el.offsetWidth - newValue;

							// otherwise don't need to change
						}

						el.scrollLeft = newValue;
					})(this[i], newLeft);
				}

				return this;
			}

			var el = this[0];
			if (!el)
			{
				return 0;
			}

			var scrollLeft = el.scrollLeft;

			switch (type)
			{
				case 'negative':
					return scrollLeft < 0 ? -scrollLeft : 0;

				case 'inverted':
					var calc = el.scrollWidth - scrollLeft - el.offsetWidth;
					return (calc < 0.5 ? 0 : calc); // avoid rounding issues

				case 'normal':
				default:
					return scrollLeft;
			}
		}
	});

	(function()
	{
		function getCssTransitionDuration($el)
		{
			if (!$.support.transition)
			{
				return 0;
			}

			var el = $el[0];
			if (!el || !(el instanceof window.Element))
			{
				return 0;
			}

			var durationCss = $el.css('transition-duration'), duration = 0;
			if (durationCss && durationCss.match(/^(\+|-|)([0-9]*\.[0-9]+|[0-9]+)(ms|s)/i))
			{
				duration = (RegExp.$1 == '-' ? -1 : 1) * parseFloat(RegExp.$2) * (RegExp.$3.toLowerCase() == 'ms' ? 1 : 1000);
			}

			return duration;
		}

		function getClassDiff($el, checkClassList, getMissing)
		{
			var diff = [];

			if ($.isFunction(checkClassList))
			{
				checkClassList = checkClassList.call($el[0], 0, $el[0].className);
			}

			var checkClasses = $.trim(checkClassList).split(/\s+/),
				classes = " " + $el[0].className + " ",
				present;
			for (var i = 0; i < checkClasses.length; i++)
			{
				present = (classes.indexOf(" " + checkClasses[i] + " ") >= 0);
				if ((present && !getMissing) || (!present && getMissing))
				{
					diff.push(checkClasses[i]);
				}
			}

			return diff.join(" ");
		}

		var mappedAttrs = {
			height: ['height', 'padding-top', 'padding-bottom', 'margin-top', 'margin-bottom', 'border-top-width', 'border-bottom-width'],
			width: ['width', 'padding-left', 'padding-right', 'margin-left', 'margin-right', 'border-right-width', 'border-left-width']
		};

		function adjustClasses($el, isAdding, className, onTransitionEnd, instant)
		{
			var duration = instant ? 0 : getCssTransitionDuration($el),
				mainFunc = isAdding ? 'addClass' : 'removeClass',
				inverseFunc = isAdding ? 'removeClass' : 'addClass',
				getMissing = isAdding ? true : false,
				adjustClasses = getClassDiff($el, className, getMissing),
				el = $el[0],
				transitioningClass = 'is-transitioning',
				transitionEndFakeCall = function()
				{
					if (onTransitionEnd)
					{
						setTimeout(function()
						{
							onTransitionEnd.call(el, $.Event('xfTransitionEnd'));
						}, 0);
					}
				};

			if (!adjustClasses.length)
			{
				transitionEndFakeCall();
				return;
			}

			if (duration <= 0)
			{
				$el[mainFunc](adjustClasses);
				transitionEndFakeCall();
				return;
			}

			if ($el.hasClass(transitioningClass))
			{
				$el.trigger('xfTransitionEnd');
			}

			$el.addClass(transitioningClass);

			if ($el.css('transition-property').match(/(^|\s|,)-xf-(width|height)($|\s|,)/))
			{
				var attr = RegExp.$2,
					relatedAttrs = mappedAttrs[attr],
					curCssValues = $el.css(relatedAttrs),
					curCssValue = curCssValues[attr],
					storeCurStyle = "transition." + attr,
					curStyleValues = $el.data(storeCurStyle),
					style = el.style,
					previousTransition = style['transition']
						|| style['-webkit-transition']
						|| style['-moz-transition']
						|| style['-o-transition']
						|| '',
					i;

				if (curStyleValues === undefined)
				{
					curStyleValues = {};
					for (i = 0; i < relatedAttrs.length; i++)
					{
						curStyleValues[relatedAttrs[i]] = style[relatedAttrs[i]] || '';
					}
				}

				if ($el[attr]() == 0)
				{
					curCssValue = '0';

					for (i in curCssValues)
					{
						if (curCssValues.hasOwnProperty(i))
						{
							curCssValues[i] = '0';
						}
					}
				}

				$el.data(storeCurStyle, curStyleValues)
					.css('transition', 'none')
					[mainFunc](adjustClasses);

				var newCssValues = $el.css(relatedAttrs),
					newCssValue = newCssValues[attr];

				if ($el[attr]() == 0)
				{
					newCssValue = '0';
					for (i in newCssValues)
					{
						if (newCssValues.hasOwnProperty(i))
						{
							newCssValues[i] = '0';
						}
					}
				}

				$el[inverseFunc](adjustClasses);

				if (curCssValue != newCssValue)
				{
					var originalCallback = onTransitionEnd;

					$el.css(curCssValues);
					el.offsetWidth; // this is needed to force a redraw; must be before the transition restore line
					$el.css('transition', previousTransition)
						.css(newCssValues);

					onTransitionEnd = function()
					{
						$el.css($el.data(storeCurStyle))
							.removeData(storeCurStyle);

						if (originalCallback)
						{
							originalCallback.apply(this, arguments);
						}
					}
				}
				else
				{
					$el.css('transition', previousTransition);
				}
			}

			$el.onTransitionEnd(duration, function()
			{
				$el.removeClass(transitioningClass);

				if (onTransitionEnd)
				{
					onTransitionEnd.apply(this, arguments);
				}
			});
			$el[mainFunc](className);
		}

		$.fn.addClassTransitioned = function(className, onTransitionEnd, instant)
		{
			var len = this.length;
			for (var i = 0; i < len; i++)
			{
				adjustClasses($(this[i]), true, className, onTransitionEnd, instant)
			}

			return this;
		};

		$.fn.removeClassTransitioned = function(className, onTransitionEnd, instant)
		{
			var len = this.length;
			for (var i = 0; i < len; i++)
			{
				adjustClasses($(this[i]), false, className, onTransitionEnd, instant)
			}

			return this;
		};

		$.fn.toggleClassTransitioned = function(className, state, onTransitionEnd, instant)
		{
			if (typeof state !== 'boolean' && typeof onTransitionEnd === 'undefined')
			{
				onTransitionEnd = state;
				state = null;
			}

			var useState = (typeof state === 'boolean'),
				len = this.length;

			for (var i = 0; i < len; i++)
			{
				var $el = $(this[i]),
					add;

				if (useState)
				{
					add = state;
				}
				else
				{
					add = $el.hasClass(className) ? false : true;
				}

				adjustClasses($el, add, className, onTransitionEnd, instant);
			}

			return this;
		};
	})();

	// ################################# BASE HELPERS ############################################

	$.extend(XF, {
		config: {
			userId: null,
			ignored: [],
			csrf: null,
			time: {
				now: 0,
				today: 0,
				todayDow: 0
			},
			cookie: {
				path: '/',
				domain: '',
				prefix: 'xf_'
			},
			url: {
				fullBase: '/',
				basePath: '/',
				css: '',
				keepAlive: ''
			},
			css: {},
			js: {},
			jsState: {},
			speed: {
				xxfast: 50,
				xfast: 100,
				fast: 200,
				normal: 400,
				slow: 600
			},
			job: {
				manualUrl: ''
			},
			borderSizeFeature: '3px',
			enableRtnProtect: true,
			enableFormSubmitSticky: true,
			visitorCounts: {
				conversations_unread: 0,
				alerts_unread: 0,
				title_count: false,
				icon_indicator: false
			},
			uploadMaxFilesize: null
		},

		counter: 1,

		phrases: {},

		getApp: function()
		{
			return $('html').data('app') || null;
		},

		getKeyboardInputs: function()
		{
			return 'input:not([type=radio], [type=checkbox], [type=submit], [type=reset]), textarea';
		},

		onPageLoad: function()
		{
			$(document).trigger('xf:page-load-start');

			XF.NavDeviceWatcher.initialize();
			XF.ActionIndicator.initialize();
			XF.DynamicDate.initialize();
			XF.KeepAlive.initialize();
			XF.LinkWatcher.initLinkProxy();
			XF.LinkWatcher.initExternalWatcher();
			XF.BbBlockExpand.watch();
			XF.ScrollButtons.initialize();
			XF.KeyboardShortcuts.initialize();
			XF.IgnoreWatcher.initializeHash();
			XF.Click.watch();

			XF.config.jsState = XF.applyJsState({}, XF.config.jsState);

			XF.activate(document);

			$(document).on('ajax:complete', function(e, xhr, status)
			{
				var data = xhr.responseJSON;
				if (!data)
				{
					return;
				}

				if (data.visitor)
				{
					XF.updateVisitorCounts(data.visitor, true);
				}
			});

			$(document).on('ajax:before-success', function(e, data, status, xhr)
			{
				var data = xhr.responseJSON;
				if (!data)
				{
					return;
				}

				if (data && data.job)
				{
					var job = data.job;
					if (job.manual)
					{
						XF.JobRunner.runManual(job.manual);
					}
					if (job.auto)
					{
						setTimeout(XF.JobRunner.runAuto, 0);
					}
				}
			});
			$(document).on('keyup', 'a:not([href])', function(e)
			{
				if (e.which == 13)
				{
					$(e.currentTarget).click();
				}
			});

			if ($('html[data-run-jobs]').length)
			{
				setTimeout(XF.JobRunner.runAuto, 100);
			}

			XF.updateVisitorCounts(XF.config.visitorCounts, true);

			XF.CrossTab.on('visitorCounts', function(counts)
			{
				XF.updateVisitorCounts(counts, false);
			});

			XF.pageLoadScrollFix();

			setTimeout(function()
			{
				$('[data-load-auto-click]').first().click();
			}, 100);

			$(document).trigger('xf:page-load-complete');
		},

		addExtraPhrases: function(el)
		{
			$(el).find('script.js-extraPhrases').each(function()
			{
				var $script = $(this),
					phrases;

				try
				{
					phrases = $.parseJSON($script.html()) || {};
					$.extend(XF.phrases, phrases);
				}
				catch (e)
				{
					console.error(e);
				}

				$script.remove();
			});
		},

		phrase: function(name, vars, fallback)
		{
			var phrase = XF.phrases[name];
			if (vars)
			{
				phrase = XF.stringTranslate(phrase, vars);
			}
			return phrase || fallback || name;
		},

		_isRtl: null,

		isRtl: function()
		{
			if (XF._isRtl === null)
			{
				var dir = $('html').attr('dir');
				XF._isRtl = (dir && dir.toUpperCase() == 'RTL');
			}
			return XF._isRtl;
		},

		rtlFlipKeyword: function(keyword)
		{
			if (!XF.isRtl())
			{
				return keyword;
			}

			var lower  = keyword.toLowerCase();
			switch (lower)
			{
				case 'left': return 'right';
				case 'right': return 'left';
				default: return keyword;
			}
		},

		isMac: function()
		{
			return navigator.userAgent.indexOf('Mac OS') != -1;
		},

		isIOS: function()
		{
			return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
		},

		log: function()
		{
			if (!console.log || !console.log.apply)
			{
				return;
			}

			console.log.apply(console, arguments);
		},

		findRelativeIf: function(selector, $base)
		{
			if (!selector)
			{
				throw new Error('No selector provided');
			}

			var match;
			if (match = selector.match(/^(<|>|\|)/))
			{
				if (match[1] == '<')
				{
					return $base.findExtended(selector);
				}

				if (match[1] == '|')
				{
					selector = selector.substr(1);
				}

				return $base.find(selector);
			}
			else
			{
				return $(selector);
			}
		},

		/**
		 * Simple function to be run whenever we change the page layout with JS,
		 * to trigger recalculation of JS-positioned elements
		 * such as sticky_kit items
		 */
		layoutChange: function()
		{
			if (!XF._layoutChangeTriggered)
			{
				XF._layoutChangeTriggered = true;
				setTimeout(function()
				{
					XF._layoutChangeTriggered = false;

					$(document.body)
						.trigger('sticky_kit:recalc')
						.trigger('xf:layout');
				}, 0);
			}
		},

		_layoutChangeTriggered: false,

		updateAvatars: function(userId, newAvatars, includeEditor)
		{
			$('.avatar').each(function()
			{
				var $avatarContainer = $(this),
					$avatar = $avatarContainer.find('img, span').first(),
					classPrefix = 'avatar-u' + userId + '-',
					$update = $avatarContainer.hasClass('avatar--updateLink')
						? $avatarContainer.find('.avatar-update')
						: null,
					$newAvatar;

				if (!includeEditor && $avatar.hasClass('.js-croppedAvatar'))
				{
					return;
				}

				if ($avatar.is('[class^="' + classPrefix + '"]'))
				{
					if ($avatar.hasClass(classPrefix + 's'))
					{
						$newAvatar = $(newAvatars['s']);
					}
					else if ($avatar.hasClass(classPrefix + 'm'))
					{
						$newAvatar = $(newAvatars['m']);
					}
					else if ($avatar.hasClass(classPrefix + 'l'))
					{
						$newAvatar = $(newAvatars['l']);
					}
					else if ($avatar.hasClass(classPrefix + 'o'))
					{
						$newAvatar = $(newAvatars['o']);
					}
					else
					{
						return;
					}

					$avatarContainer.html($newAvatar.html());

					if ($newAvatar.hasClass('avatar--default'))
					{
						$avatarContainer.addClass('avatar--default');
						if ($newAvatar.hasClass('avatar--default--dynamic'))
						{
							$avatarContainer.addClass('avatar--default--dynamic');
						}
						else if ($newAvatar.hasClass('avatar--default--text'))
						{
							$avatarContainer.addClass('avatar--default--text');
						}
						else if ($newAvatar.hasClass('avatar--default--image'))
						{
							$avatarContainer.addClass('avatar--default--image');
						}
					}
					else
					{
						$avatarContainer.removeClass('avatar--default avatar--default--dynamic avatar--default--text avatar--default--image');
					}
					$avatarContainer.attr('style', $newAvatar.attr('style'));

					if ($update)
					{
						$avatarContainer.append($update);
					}
				}
			});
		},

		updateVisitorCounts: function(visitor, isForegroundUpdate)
		{
			if (!visitor || XF.getApp() != 'public')
			{
				return;
			}

			XF.badgeCounterUpdate($('.js-badge--conversations'), visitor.conversations_unread);
			XF.badgeCounterUpdate($('.js-badge--alerts'), visitor.alerts_unread);

			if (XF.config.visitorCounts['title_count'])
			{
				XF.pageTitleCounterUpdate(visitor.total_unread);
			}

			if (XF.config.visitorCounts['icon_indicator'])
			{
				XF.faviconUpdate(visitor.total_unread);
			}

			if (isForegroundUpdate)
			{
				XF.CrossTab.trigger('visitorCounts', visitor);
			}

			// TODO: Stack alerts?
		},

		badgeCounterUpdate: function($badge, newCount)
		{
			if (!$badge.length)
			{
				return;
			}

			$badge.attr('data-badge', newCount);

			if (String(newCount) != '0')
			{
				$badge.addClass('badgeContainer--highlighted');
			}
			else
			{
				$badge.removeClass('badgeContainer--highlighted');
			}
		},

		pageTitleCache: '',

		pageTitleCounterUpdate: function(newCount)
		{
			var pageTitle = document.title,
				newTitle;

			if (XF.pageTitleCache === '')
			{
				XF.pageTitleCache = pageTitle;
			}

			if (pageTitle !== XF.pageTitleCache && pageTitle.charAt(0) === '(')
			{
				pageTitle = XF.pageTitleCache;
			}

			newTitle = (newCount > 0 ? '(' + newCount + ') ' : '') + pageTitle;

			if (newTitle != document.title)
			{
				document.title = newTitle;
			}
		},

		favIconAlertShown: false,

		faviconUpdate: function(newCount)
		{
			var shouldBeShown = (newCount > 0);
			if (shouldBeShown == XF.favIconAlertShown)
			{
				return;
			}

			var $favicons = $('link[rel~="icon"]');

			if (!$favicons.length || !Modernizr.canvas)
			{
				// no favicons or no canvas support
				return;
			}

			XF.favIconAlertShown = shouldBeShown;

			$favicons.each(function(i, favicon)
			{
				var $favicon = $(favicon),
					href = $favicon.attr('href'),
					originalHrefKey = 'original-href',
					originalHref = $favicon.data(originalHrefKey);

				if (newCount > 0)
				{
					if (!originalHref)
					{
						$favicon.data(originalHrefKey, href);
					}

					$('<img />')
						.on('load', function() { $favicon.attr('href', XF.faviconDraw(this)); })
						.attr('src', href);
				}
				else
				{
					if (originalHref)
					{
						$favicon.attr('href', originalHref)
							.removeData(originalHrefKey);
					}
				}
			});
		},

		faviconDraw: function(image)
		{
			var w = image.naturalWidth,
				h = image.naturalHeight,
				$canvas = $('<canvas />').attr({ width: w, height: h }),
				context = $canvas[0].getContext('2d');

			var ratio = 32 / 6,
				radius = w / ratio,
				x = radius,
				y = radius,
				startAngle = 0,
				endAngle = Math.PI * 2,
				antiClockwise = false;

			context.drawImage(image, 0, 0);
			context.beginPath();
			context.arc(x, y, radius, startAngle, endAngle, antiClockwise);
			context.fillStyle = "#E03030";
			context.fill();
			context.lineWidth = w / 16;
			context.strokeStyle = "#eaeaea";
			context.stroke();
			context.closePath();

			return $canvas[0].toDataURL('image/png');
		},

		/**
		 * Attempts to convert various HTML-BB codes back into BB code
		 *
		 * @param html
		 *
		 * @returns string
		 */
		unparseBbCode: function(html)
		{
			var $div = $(document.createElement('div'));

			$div.html(html);

			// get rid of anything with this class
			$div.find('.js-noSelectToQuote').each(function()
			{
				$(this).remove();
			});

			// handle b, i, u
			$.each(['B', 'I', 'U'], function(i, tagName)
			{
				$div.find(tagName).each(function()
				{
					$(this).replaceWith('[' + tagName + ']' + $(this).html() + '[/' + tagName + ']');
				});
			});

			// handle quote tags as best we can
			$div.find('.bbCodeBlock--quote').each(function()
			{
				var $this = $(this),
					$quote = $this.find('.bbCodeBlock-expandContent');
				if ($quote.length)
				{
					$this.replaceWith('<div>[QUOTE]' + $quote.html() + '[/QUOTE]</div>');
				}
				else
				{
					$quote.find('.bbCodeBlock-expand').remove();
				}
			});

			// now for PHP, CODE and HTML
			$div.find('.bbCodeBlock--code').each(function()
			{
				var $this = $(this);

				if (!$this.find('.bbCodeCode'))
				{
					return true;
				}

				var	$code = $this.find('.bbCodeCode code'),
					language = $code.attr('class').match(/language-(\S+)/)[1];

				$code.removeAttr('class');

				$this.replaceWith($code.first().attr('data-language', language || 'none'));
			});

			// now alignment tags
			$div.find('div[style*="text-align"]').each(function()
			{
				var align = $(this).css('text-align').toUpperCase();

				$(this).replaceWith('[' + align + ']' + $(this).html() + '[/' + align + ']');
			});

			// and finally, spoilers...
			$div.find('.bbCodeSpoiler').each(function()
			{
				var $button, target, $spoilerTitle, spoilerTitle = '', spoilerText;

				// find the button and the target
				$button = $(this).find('.bbCodeSpoiler-button');
				if ($button.length)
				{
					spoilerText = $(this).find('.bbCodeSpoiler-content').html();
					$spoilerTitle = $button.find('.bbCodeSpoiler-button-title');

					if ($spoilerTitle.length)
					{
						spoilerTitle = '="' + $spoilerTitle.text() + '"';
					}

					$(this).replaceWith('[SPOILER' + spoilerTitle + ']' + spoilerText + '[/SPOILER]');
				}
			});

			// console.info('HTML to be sent: %s', $div.html());

			return $div.html();
		},

		hideOverlays: function()
		{
			$.each(XF.Overlay.cache, function(id, overlay)
			{
				overlay.hide();
			});
		},

		hideTooltips: function()
		{
			$.each(XF.TooltipTrigger.cache, function(id, trigger)
			{
				trigger.hide();
			});
		},

		loadedScripts: {},

		/**
		 * Given a URL, load it (if not already loaded)
		 * before running a callback function on success
		 *
		 * @param url
		 * @param successCallback
		 */
		loadScript: function(url, successCallback)
		{
			if (XF.loadedScripts.hasOwnProperty(url))
			{
				return false;
			}

			XF.loadedScripts[url] = true;

			return $.ajax({
				url: url,
				dataType: "script",
				cache: true,
				global: false,
				success: successCallback
			});
		},

		/**
		 * Given an array of URLs, load them all (if not already loaded)
		 * before running a callback function on complete (success or error).
		 *
		 * In the absolute majority of browsers, this will execute the loaded scripts in the order provided.
		 *
		 * @param urls
		 * @param completeCallback
		 */
		loadScripts: function(urls, completeCallback)
		{
			var firstScript = document.scripts[0],
				useAsync = 'async' in firstScript,
				useReadyState = firstScript.readyState,
				head = document.head,
				toLoad = 0,
				url,
				pendingScripts = [];

			function loaded()
			{
				toLoad--;
				if (toLoad === 0 && completeCallback)
				{
					completeCallback();
				}
			}

			function stateChange()
			{
				var pendingScript;
				while (pendingScripts[0] && pendingScripts[0].readyState == 'loaded')
				{
					pendingScript = pendingScripts.shift();
					pendingScript.onreadystatechange = null;
					pendingScript.onerror = null;
					head.appendChild(pendingScript);

					loaded();
				}
			}

			for (var i in urls)
			{
				if (!urls.hasOwnProperty(i))
				{
					continue;
				}

				url = urls[i];

				if (XF.loadedScripts[url])
				{
					continue;
				}

				XF.loadedScripts[url] = true;
				toLoad++;

				if (useAsync)
				{
					// pretty much any modern browser
					(function(url)
					{
						var $script = $('<script>').prop({
							src: url,
							async: false
						});
						$script.on('load error', function(e)
						{
							$script.off('load error');
							loaded();
						});

						head.appendChild($script[0]);
					})(url);
				}
				else if (useReadyState)
				{
					// IE 9
					(function(url)
					{
						var script = document.createElement('script');
						pendingScripts.push(script);

						script.onreadystatechange = stateChange;
						script.onerror = function()
						{
							script.onreadystatechange = null;
							script.onerror = null;
							loaded();
						};

						script.src = url;
					})(url);
				}
				else
				{
					// should very rarely be used
					$.ajax({
						url: url,
						dataType: "script",
						cache: true,
						global: false
					}).always(loaded);
				}
			}

			if (!toLoad && completeCallback)
			{
				completeCallback();
			}
		},

		ajax: function(method, url, data, successCallback, options)
		{
			if (typeof data == 'function' && successCallback === undefined)
			{
				successCallback = data;
				data = {};
			}

			data = data || {};

			var useDefaultSuccess = true,
				useDefaultSuccessError = true,
				useError = true;
			if (options)
			{
				if (options.skipDefault)
				{
					useDefaultSuccess = false;
					useDefaultSuccessError = false;
					delete options.skipDefault;
				}
				if (options.skipDefaultSuccessError)
				{
					useDefaultSuccessError = false;
					delete options.skipDefaultSuccessError;
				}
				if (options.skipDefaultSuccess)
				{
					useDefaultSuccess = false;
					delete options.skipDefaultSuccess;
				}
				if (options.skipError)
				{
					useError = false;
					delete options.skipError;
				}
			}

			var onBeforeSend = function(xhr, settings)
			{
				$(document).trigger('ajax:send', [xhr, settings]);
			};

			var onSuccess = function(data, status, xhr)
			{
				$(document).trigger('ajax:before-success', [data, status, xhr]);

				if (useDefaultSuccessError && XF.defaultAjaxSuccessError(data, status, xhr))
				{
					// this processed successfully, don't continue
					return;
				}
				if (useDefaultSuccess && XF.defaultAjaxSuccess(data, status, xhr))
				{
					// this processed successfully, don't continue
					return;
				}

				if (successCallback)
				{
					successCallback(data, status, xhr);
				}
			};

			var onError = function(xhr, status, exception)
			{
				if (!xhr.readyState)
				{
					return;
				}

				try
				{
					var json = $.parseJSON(xhr.responseText);
					onSuccess(json, '', xhr);
				}
				catch (e)
				{
					XF.defaultAjaxError(xhr, status, exception);
				}
			};

			var onComplete = function(xhr, status)
			{
				$(document).trigger('ajax:complete', [xhr, status]);
			};

			data = XF.dataPush(data, '_xfRequestUri', window.location.pathname + window.location.search);
			data = XF.dataPush(data, '_xfWithData', 1);
			if (XF.config.csrf)
			{
				data = XF.dataPush(data, '_xfToken', XF.config.csrf);
			}

			var isFormDataObject = (window.FormData && data instanceof FormData),
				ajax = $.extend(true, {
					cache: true,
					data: data || {},
					dataType: 'json',
					beforeSend: onBeforeSend,
					error: useError ? onError : null,
					success: onSuccess,
					complete: onComplete,
					timeout: 30000,
					type: method,
					url: url,
					processData: isFormDataObject ? false : true
				}, options);

			if (isFormDataObject)
			{
				ajax['contentType'] = false;
			}

			switch (ajax.dataType)
			{
				case 'html':
				case 'json':
				case 'xml':
					ajax.data = XF.dataPush(ajax.data, '_xfResponseType', ajax.dataType);
			}

			if (ajax.dataType != 'json')
			{
				useDefaultSuccess = false;
			}

			return $.ajax(ajax);
		},

		dataPush: function(data, key, value)
		{
			if (!data || typeof data == 'string')
			{
				// data is empty, or a url string - &name=value
				data = String(data);
				data += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
			}
			else if (data[0] !== undefined)
			{
				// data is a numerically-keyed array of name/value pairs
				data.push({ name: key, value: value });
			}
			else if (window.FormData && data instanceof FormData)
			{
				// data is a FormData object
				data.append(key, value);
			}
			else
			{
				// data is an object with a single set of name & value properties
				data[key] = value;
			}

			return data;
		},

		defaultAjaxSuccessError: function(data, status, xhr)
		{
			if (typeof data != 'object')
			{
				XF.alert('Response was not JSON.');
				return true;
			}

			if (data.errorHtml)
			{
				XF.setupHtmlInsert(data.errorHtml, function($html, container)
				{
					var title = container.h1 || container.title || XF.phrase('oops_we_ran_into_some_problems');
					XF.overlayMessage(title, $html);
				});
				return true;
			}

			if (data.errors)
			{
				XF.alert(data.errors);
				return true;
			}

			if (data.exception)
			{
				XF.alert(data.exception);
				return true;
			}

			return false;
		},

		defaultAjaxSuccess: function(data, status, xhr)
		{
			if (data && data.status == 'ok' && data.message)
			{
				XF.flashMessage(data.message, 3000);
				// let the real callback still run
			}

			return false;
		},

		defaultAjaxError: function(xhr, error, exception)
		{
			switch (error)
			{
				case 'abort':
					return;

				case 'timeout':
					XF.alert(
						XF.phrase('server_did_not_respond_in_time_try_again')
					);
					return;

				case 'notmodified':
				case 'error':
					if (!xhr || !xhr.responseText)
					{
						// this is likely a user cancellation, so just return
						return;
					}
					break;
			}

			console.error('PHP: ' + xhr.responseText);
			XF.alert(XF.phrase('oops_we_ran_into_some_problems_more_details_console'));
		},

		activate: function(el)
		{
			XF.addExtraPhrases(el);
			XF.IgnoreWatcher.refresh(el);
			XF.Element.initialize(el);
			XF.DynamicDate.refresh(el);
			XF.BbBlockExpand.checkSizing(el);
			XF.KeyboardShortcuts.initializeElements(el);

			var domEl = (el instanceof $ ? el.get(0) : el);

			if (window.FB)
			{
				setTimeout(function()
				{
					FB.XFBML.parse(domEl);
				}, 0);
			}

			$(document).trigger('xf:reinit', [el]);
		},

		getDefaultFormData: function($form, $submitButton, jsonName, jsonOptIn)
		{
			var formData,
				submitName;

			if ($submitButton && $submitButton.length && $submitButton.attr('name'))
			{
				submitName = $submitButton.attr('name');
			}

			if (jsonName && $form.attr('enctype') === 'multipart/form-data')
			{
				console.error('JSON serialized forms do not support the file upload-style enctype.');
			}

			// JSON serialization doesn't support uploads, so form data isn't needed
			if (window.FormData && !jsonName)
			{
				formData = new FormData($form[0]);
				if (submitName)
				{
					formData.append(submitName, $submitButton.attr('value'));
				}
			}
			else
			{
				if (jsonName)
				{
					var $els = $form.is('form') ? $($form[0].elements) : $form,
						jsonOptInRegex,
						jsonEls = [],
						regularEls = [],
						inputs;

					if (jsonOptIn)
					{
						if (typeof jsonOptIn === 'string')
						{
							jsonOptIn = jsonOptIn.split(',');
						}

						var jsonOptInRegexFields = [];
						$.each(jsonOptIn, function(i, v)
						{
							if (typeof i === 'number')
							{
								jsonOptInRegexFields.push(XF.regexQuote($.trim(v)));
							}
							else
							{
								jsonOptInRegexFields.push(XF.regexQuote($.trim(i)));
							}
						});
						if (jsonOptInRegexFields.length)
						{
							jsonOptInRegex = new RegExp('^(' + jsonOptInRegexFields.join('|') + ')(\\[|$)');
						}
					}

					$els.each(function(i, el)
					{
						var name = el.name;

						if (!name || name.substring(0, 3) === '_xf')
						{
							regularEls.push(el);
							return;
						}

						if (!jsonOptInRegex || jsonOptInRegex.test(name))
						{
							jsonEls.push(el);
						}
						else
						{
							regularEls.push(el);
						}
					});

					formData = $(regularEls).serializeArray();

					inputs = $(jsonEls).serializeJSON();
					formData.unshift({
						name: jsonName,
						value: JSON.stringify(inputs)
					});
				}
				else
				{
					formData = $form.serializeArray();
				}

				if (submitName)
				{
					formData.push({
						name: submitName,
						value: $submitButton.attr('value')
					});
				}
			}

			return formData;
		},

		scriptMatchRegex: /<script([^>]*)>([\s\S]*?)<\/script>/ig,

		setupHtmlInsert: function(container, onReady, retainScripts)
		{
			if (typeof container === 'string' || container instanceof $)
			{
				container = { content: container };
			}

			if (typeof container != 'object' || !container.content)
			{
				console.error('Was not provided an object or HTML content');
				return;
			}

			var args = arguments;

			XF.Loader.load(container.js, container.css, function()
			{
				var scriptRegexMatch,
					embeddedScripts = container.jsInline || [],
					html = container.content,
					isString = typeof html == 'string',
					retainScripts = args[2] ? true : false;

				if (container.cssInline)
				{
					for (var i = 0; i < container.cssInline.length; i++)
					{
						$('<style>' + container.cssInline[i] + '</style>').appendTo('head');
					}
				}

				if (isString)
				{
					var isJs, typeMatch;

					html = $.trim(html);

					if (!retainScripts)
					{
						while (scriptRegexMatch = XF.scriptMatchRegex.exec(html))
						{
							isJs = false;
							if (typeMatch = scriptRegexMatch[1].match(/(^|\s)type=("|'|)([^"' ;]+)/))
							{
								switch (typeMatch[3].toLowerCase())
								{
									case 'text/javascript':
									case 'text/ecmascript':
									case 'application/javascript':
									case 'application/ecmascript':
										isJs = true;
										break;
								}
							}
							else
							{
								isJs = true;
							}

							if (isJs)
							{
								embeddedScripts.push(scriptRegexMatch[2]);
								html = html.replace(scriptRegexMatch[0], '');
							}
						}
					}

					// IE11 doesn't appear to find the noscript tags in the DOM
					html = html.replace(/<noscript>([\s\S]*?)<\/noscript>/ig, '');
				}

				var $html = $(isString ? $.parseHTML(html, null, retainScripts) : html);

				// fix retina images on loaded content in iOS
				if (window.devicePixelRatio >= 2 && XF.isIOS())
				{
					$html.find('img[srcset]').each(function()
					{
						$(this).prop('src', this.getAttribute('srcset').replace(/\s+2x$/i, ''));
					});
				}

				// Remove <noscript> tags to ensure they never get parsed when not needed.
				$html.find('noscript').empty().remove();

				if (onReady instanceof $)
				{
					var $target = onReady;
					onReady = function ($h)
					{
						$target.html($h);
					};
				}
				if (typeof onReady !== 'function')
				{
					console.error('onReady was not a function');
					return;
				}

				var onCompleteRun = false,
					onComplete = function(skipActivate)
					{
						if (onCompleteRun)
						{
							return;
						}
						onCompleteRun = true;

						if (!skipActivate)
						{
							XF.activate($html);
						}

						for (var j = 0; j < embeddedScripts.length; j++)
						{
							$.globalEval(embeddedScripts[j]);
						}

						if (container.jsState)
						{
							XF.config.jsState = XF.applyJsState(XF.config.jsState, container.jsState);
						}
					};

				var result = onReady($html, container, onComplete);
				if (result !== false)
				{
					onComplete();
				}
			});
		},

		alert: function(message, messageType, title, onClose)
		{
			var messageHtml = message;
			if (typeof message == 'object')
			{
				messageHtml = '<ul>';
				$.each(message, function(k, v)
				{
					messageHtml += '<li>' + v + '</li>';
				});
				messageHtml += '</ul>';
				messageHtml = '<div class="blockMessage">' + messageHtml + '</div>';
			}

			if (!messageType)
			{
				messageType = 'error';
			}

			if (!title)
			{
				switch (messageType)
				{
					case 'error':
						title = XF.phrase('oops_we_ran_into_some_problems');
						break;

					default:
						title = '';
				}
			}

			return XF.overlayMessage(title, messageHtml);
		},

		getOverlayHtml: function(content)
		{
			var $html,
				options = {
					dismissible: true,
					title: null
				};

			if ($.isPlainObject(content))
			{
				options = $.extend({}, options, content);
				if (content.html)
				{
					content = content.html;
				}
			}

			if (typeof content == 'string')
			{
				$html = $($.parseHTML(content));
			}
			else if (content instanceof $)
			{
				$html = content;
			}
			else
			{
				throw new Error('Can only create an overlay with html provided as a string or jQuery object');
			}

			if (!$html.is('.overlay'))
			{
				var title = options.title;
				if (!title)
				{
					var $header = $html.find('.overlay-title');
					if ($header.length)
					{
						title = $header.contents();
						$header.remove();
					}
				}
				if (!title)
				{
					title = XF.htmlspecialchars($('title').text());
				}

				var $bodyInsert = $html.find('.overlay-content');

				if ($bodyInsert.length)
				{
					$html = $bodyInsert;
				}

				var $overlay = $(
					'<div class="overlay" tabindex="-1">'
					+ '<div class="overlay-title"></div>'
					+ '<div class="overlay-content"></div>'
					+ '</div>'
				);
				var $title = $overlay.find('.overlay-title');

				$title.html(title);
				if (options.dismissible)
				{
					$title.prepend('<a class="overlay-titleCloser js-overlayClose" role="button" tabindex="0" aria-label="' + XF.phrase('close') + '"></a>');
				}
				$overlay.find('.overlay-content').html($html);

				$html = $overlay;
			}

			$html.appendTo('body');

			return $html;
		},

		overlayMessage: function(title, contentHtml)
		{
			var $html,
				formattedSelector = '.block, .blockMessage';

			if (typeof contentHtml == 'string')
			{
				$html = $($.parseHTML(contentHtml));
			}
			else if (contentHtml instanceof $)
			{
				$html = contentHtml;
			}
			else
			{
				throw new Error('Can only create an overlay with html provided as a string or jQuery object');
			}

			if (!$html.is(formattedSelector) && !$html.find(formattedSelector).length)
			{
				$html = $('<div class="blockMessage" />').html($html);
			}

			$html = XF.getOverlayHtml({
				title: title,
				html: $html
			});

			return XF.showOverlay($html, {role: 'alertdialog'});
		},

		flashMessage: function(message, timeout, onClose)
		{
			var $message = $('<div class="flashMessage"><div class="flashMessage-content"></div></div>');
			$message.find('.flashMessage-content').html(message);

			$message.appendTo('body').addClassTransitioned('is-active');
			setTimeout(function()
			{
				$message.removeClassTransitioned('is-active', function()
				{
					$message.remove();
					if (onClose)
					{
						onClose();
					}
				});
			}, Math.max(500, timeout));
		},

		htmlspecialchars: function(string)
		{
			return String(string)
				.replace(/&/g, '&amp;')
				.replace(/"/g, '&quot;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;');
		},

		regexQuote: function(string)
		{
			return (string + '').replace(/([\\\.\+\*\?\[\^\]\$\(\)\{\}\=\!<>\|\:])/g, "\\$1");
		},

		stringTranslate: function(string, pairs)
		{
			string = string.toString();
			for (var key in pairs)
			{
				if (pairs.hasOwnProperty(key))
				{
					var regex = new RegExp(XF.regexQuote(key, 'g'));
					string = string.replace(regex, pairs[key]);
				}
			}
			return string;
		},

		stringHashCode: function(str)
		{
			// adapted from http://stackoverflow.com/a/7616484/1480610
			var hash = 0, i, chr, len;

			if (str.length === 0) return hash;

			for (i = 0, len = str.length; i < len; i++)
			{
				chr   = str.charCodeAt(i);
				hash  = ((hash << 5) - hash) + chr;
				hash |= 0;
			}

			return hash;
		},

		canonicalizeUrl: function(url)
		{
			if (url.match(/^[a-z]+:/i))
			{
				return url;
			}

			if (url.indexOf('/') == 0)
			{
				var fullPath = XF.config.url.fullBase,
					match;
				if (match = fullPath.match(/^([a-z]+:(\/\/)?[^\/]+)\//i))
				{
					return match[1] + url;
				}

				return url;
			}

			return XF.config.url.fullBase + url;
		},

		isRedirecting: false,

		redirect: function(url)
		{
			XF.isRedirecting = true;

			if (XF.JobRunner.isManualJobRunning())
			{
				$(document).one('job:manual-complete', function()
				{
					XF.redirect(url);
				});
				return false;
			}

			url = XF.canonicalizeUrl(url);

			var location = window.location;

			if (url == location.href)
			{
				location.reload();
			}
			else
			{
				window.location = url;

				var destParts = url.split('#'),
					srcParts = location.href.split('#');

				// on the same page except we changed the hash, because we're asking for a redirect,
				// we should explicitly reload
				if (destParts[1] && destParts[0] == srcParts[0])
				{
					location.reload();
				}
			}

			return true;
		},

		getAutoCompleteUrl: function()
		{
			if (XF.getApp() == 'admin')
			{
				return XF.canonicalizeUrl('admin.php?users/find')
			}
			else
			{
				return XF.canonicalizeUrl('index.php?members/find')
			}
		},

		applyDataOptions: function(options, data, finalTrusted)
		{
			var output = {}, v, vType, setValue;

			for (var i in options)
			{
				if (!options.hasOwnProperty(i))
				{
					continue;
				}

				output[i] = options[i];

				if (data.hasOwnProperty(i))
				{
					v = data[i];
					vType = typeof v;
					setValue = true;

					switch (typeof output[i])
					{
						case 'string':
							if (vType != 'string')
							{
								v = String(v);
							}
							break;

						case 'number':
							if (vType != 'number')
							{
								v = Number(v);
								if (isNaN(v))
								{
									setValue = false;
								}
							}
							break;

						case 'boolean':
							if (vType != 'boolean')
							{
								switch (v)
								{
									case 'true':
									case 'yes':
									case 'on':
									case '1':
										v = true;
										break;

									default:
										v = false;
								}
							}
					}

					if (setValue)
					{
						output[i] = v;
					}
				}
			}

			if ($.isPlainObject(finalTrusted))
			{
				output = $.extend(output, finalTrusted);
			}

			return output;
		},

		watchInputChangeDelayed: function(input, onChange, delay)
		{
			var $input = $(input),
				value = $input.val(),
				timeOut;

			delay = delay || 200;

			function onKeyup()
			{
				clearTimeout(timeOut);
				timeOut = setTimeout(function()
				{
					var newValue = $input.val();
					if (newValue != value)
					{
						value = newValue;
						onChange();
					}
				}, delay);
			}

			function onPaste()
			{
				setTimeout(function() { $input.trigger('keyup'); }, 0);
			}

			$input.onPassive(
			{
				keyup: onKeyup,
				paste: onPaste
			});
		},

		insertIntoEditor: function($container, html, text, notConstraints)
		{
			var htmlCallback = function(editor)
			{
				editor.insertContent(html);
			};

			var textCallback = function($textarea)
			{
				XF.insertIntoTextBox($textarea, text);
			};

			return XF.modifyEditorContent($container, htmlCallback, textCallback, notConstraints);
		},

		replaceEditorContent: function($container, html, text, notConstraints)
		{
			var htmlCallback = function(editor)
			{
				editor.replaceContent(html);
			};

			var textCallback = function($textarea)
			{
				XF.replaceIntoTextBox($textarea, text);
			};

			return XF.modifyEditorContent($container, htmlCallback, textCallback, notConstraints);
		},

		clearEditorContent: function($container, notConstraints)
		{
			var ret = XF.replaceEditorContent($container, '', '', notConstraints);

			$container.trigger('draft:sync');

			return ret;
		},

		modifyEditorContent: function($container, htmlCallback, textCallback, notConstraints)
		{
			var editor = XF.getEditorInContainer($container, notConstraints);
			if (!editor)
			{
				return false;
			}

			if (XF.Editor && editor instanceof XF.Editor)
			{
				if (editor.isBbCodeView())
				{
					var textarea = editor.ed.bbCode.getTextArea();
					textCallback(textarea);
					textarea.trigger('autosize');
				}
				else
				{
					htmlCallback(editor);
				}
				return true;
			}

			if (editor instanceof $ && editor.is('textarea'))
			{
				textCallback(editor);
				editor.trigger('autosize');
				return true;
			}

			return false;
		},

		getEditorInContainer: function($container, notConstraints)
		{
			var $editor;

			if ($container.is('.js-editor'))
			{
				if (notConstraints && $container.is(notConstraints))
				{
					return null;
				}

				$editor = $container;
			}
			else
			{
				var $editors = $container.find('.js-editor');
				if (notConstraints)
				{
					$editors = $editors.not(notConstraints);
				}

				if (!$editors.length)
				{
					return null;
				}

				$editor = $editors.first();
			}

			var editor = XF.Element.getHandler($editor, 'editor');
			if (editor)
			{
				return editor;
			}

			if ($editor.is('textarea'))
			{
				return $editor;
			}

			return null;
		},

		focusEditor: function($container, notConstraints)
		{
			var editor = XF.getEditorInContainer($container, notConstraints);
			if (!editor)
			{
				return false;
			}

			if (XF.Editor && editor instanceof XF.Editor)
			{
				editor.scrollToCursor();
				return true;
			}

			if (editor instanceof $ && editor.is('textarea'))
			{
				editor.autofocus();
				return true;
			}

			return false;
		},

		insertIntoTextBox: function($textBox, insert)
		{
			var textBox = $textBox[0],
				scrollPos = textBox.scrollTop,
				startPos = textBox.selectionStart,
				endPos = textBox.selectionEnd,
				value = $textBox.val(),
				before = value.substring(0, startPos),
				after = value.substring(endPos, value.length);

			$textBox.val(before + insert + after).trigger('autosize');
			textBox.selectionStart = textBox.selectionEnd = startPos + insert.length;
			textBox.scrollTop = scrollPos;
			$textBox.autofocus();
		},

		replaceIntoTextBox: function($textBox, insert)
		{
			$textBox.val(insert).trigger('autosize');
		},

		getFixedOffsetParent: function($el)
		{
			do
			{
				if ($el.css('position') == 'fixed')
				{
					return $el;
				}

				$el = $el.parent();
			}
			while ($el[0] && $el[0].nodeType === 1);

			return $(document.documentElement);
		},

		getFixedOffset: function($el)
		{
			var offset = $el.offset(),
				$offsetParent = XF.getFixedOffsetParent($el);

			if ($el.is('html'))
			{
				return offset;
			}

			var parentOffset = $offsetParent.offset();
			return {
				top: offset.top - parentOffset.top,
				left: offset.left - parentOffset.left
			};
		},

		autoFocusWithin: function($container, autoFocusSelector, $fallback)
		{
			var $focusEl = $container.find(autoFocusSelector || '[autofocus]');

			if (!$focusEl.length)
			{
				if (!$focusEl.length && XF.NavDeviceWatcher.isKeyboardNav())
				{
					$focusEl = $container.find('a, button, :input, [tabindex]').filter(':visible').not(':disabled');
				}
				if (!$focusEl.length)
				{
					var $form = $container.is('form') ? $container : $container.find('form').first();
					if ($form.length)
					{
						$focusEl = $form.find(':input, button').filter(':visible').not(':disabled');
					}
				}
				if (!$focusEl.length && $fallback && $fallback.length)
				{
					$focusEl = $fallback;
				}
				if (!$focusEl.length)
				{
					$container.attr('tabindex', '-1');
					$focusEl = $container;
				}
			}

			// focusing will trigger a scroll, so we want to prevent that. We need to maintain all scroll
			// values and restore them after focusing.
			var scrolls = [],
				parent = $focusEl[0].parentNode;

			do
			{
				scrolls.push({
					el: parent,
					left: parent.scrollLeft,
					top: parent.scrollTop
				});
			}
			while (parent = parent.parentNode);

			$focusEl.first().autofocus();

			var el;
			for (var i = 0; i < scrolls.length; i++)
			{
				el = scrolls[i].el;

				if (el.scrollLeft != scrolls[i].left)
				{
					el.scrollLeft = scrolls[i].left;
				}
				if (el.scrollTop != scrolls[i].top)
				{
					el.scrollTop = scrolls[i].top;
				}
			}
		},

		bottomFix: function(el)
		{
			var $fixer = $('.js-bottomFixTarget').first();
			if ($fixer)
			{
				$fixer.append(el);
			}
			else
			{
				$(el).css({
					position: 'fixed',
					bottom: 0
				}).appendTo('body');
			}
		},

		addFixedMessage: function(el, extraAttrs)
		{
			var $message;

			$message = $($.parseHTML(
				'<div class="fixedMessageBar">'
					+ '<div class="fixedMessageBar-inner">'
						+ '<div class="fixedMessageBar-message"></div>'
						+ '<a class="fixedMessageBar-close" data-close="true" role="button" tabindex="0" aria-label="' + XF.phrase('close') + '"></a>'
					+ '</div>'
				+ '</div>'
			));

			$message.find('.fixedMessageBar-message').html(el);

			if (extraAttrs)
			{
				if (extraAttrs.class)
				{
					$message.addClass(extraAttrs.class);
					delete extraAttrs.class;
				}
				$message.attr(extraAttrs);
			}

			$message.on('click', '[data-close]', function()
			{
				$message.removeClassTransitioned('is-active', function()
				{
					$message.remove();
				});
			});

			XF.bottomFix($message);
			$message.addClassTransitioned('is-active');
		},

		_measureScrollBar: null,

		measureScrollBar: function($container, type)
		{
			if (type == 'height' || type == 'h')
			{
				type = 'h';
			}
			else
			{
				type = 'w';
			}

			if ($container || XF._measureScrollBar === null)
			{
				var $measure = $('<div class="scrollMeasure" />');
				$measure.appendTo($container || 'body');
				var el = $measure[0],
					width = el.offsetWidth - el.clientWidth,
					height = el.offsetHeight - el.clientHeight,
					value = {w: width, h: height};
				$measure.remove();

				if (!$container)
				{
					XF._measureScrollBar = value;
				}

				return value[type];
			}
			else
			{
				return XF._measureScrollBar[type];
			}
		},

		windowHeight: function()
		{
			if (XF.browser.ios || XF.browser.android)
			{
				// return the effective height, without any browser UI
				return window.innerHeight;
			}
			else
			{
				return $(window).height();
			}
		},

		pageLoadScrollFix: function()
		{
			if (!window.location.hash)
			{
				return;
			}

			var isScrolled = false;
			var onLoad = function()
			{
				if (isScrolled)
				{
					return;
				}

				var hash = window.location.hash.replace(/[^a-zA-Z0-9_-]/g, ''),
					$match = hash ? $('#' + hash) : $();

				if ($match.length)
				{
					$match.get(0).scrollIntoView(true);
				}
			};

			if (document.readyState == 'complete')
			{
				// load has already fired
				setTimeout(onLoad, 0);
			}
			else
			{
				setTimeout(function()
				{
					$(window).one('scroll', function(e) {
						isScrolled = true;
					});
				}, 100);

				$(window).one('load', onLoad);
			}
		},

		applyJsState: function(currentState, additionalState)
		{
			currentState = currentState || {};

			if (!additionalState)
			{
				return currentState;
			}

			var state, applyJsStateFn;

			for (state in additionalState)
			{
				if (additionalState.hasOwnProperty(state) && !currentState[state])
				{
					if (XF.jsStates.hasOwnProperty(state))
					{
						if (XF.jsStates[state]())
						{
							currentState[state] = true;
						}
					}

				}
			}

			return currentState;
		},

		jsStates:
		{
			fbSdk: function()
			{
				$(document.body).append($('<div id="fb-root" />'));

				window.fbAsyncInit = function()
				{
					FB.init({
						version: 'v2.7',
						xfbml: true
					});
				};

				XF.loadScript('https://connect.facebook.net/' + XF.getLocale() + '/sdk.js');

				return true;
			},

			twitter: function()
			{
				// https://dev.twitter.com/web/javascript
				window.twttr = (function()
				{
					var t = window.twttr || {};

					if (XF.loadScript("https://platform.twitter.com/widgets.js"))
					{
						t._e = [];
						t.ready = function(f)
						{
							t._e.push(f);
						};
					}
					return t;
				}());

				return true;
			},

			flickr: function()
			{
				XF.loadScript('https://embedr.flickr.com/assets/client-code.js');

				return true;
			},

			instagram: function()
			{
				XF.loadScript('https://platform.instagram.com/' + XF.getLocale() + '/embeds.js', function()
				{
					$(document).on('xf:reinit', function(e, el)
					{
						if (window.instgrm)
						{
							instgrm.Embeds.process(el instanceof $ ? el.get(0) : el);
						}
					});
				});
			},

			reddit: function()
			{
				XF.loadScript('https://embed.redditmedia.com/widgets/platform.js');

				return true;
			},

			reddit_comment: function()
			{
				XF.loadScript('https://www.redditstatic.com/comment-embed.js', function()
				{
					$(document).on('xf:reinit', function(e, el)
					{
						if (window.rembeddit)
						{
							rembeddit.init();
						}
					});
				});

				return true;
			},

			imgur: function()
			{
				var selector = 'blockquote.imgur-embed-pub';

				if (!window.imgurEmbed)
				{
					window.imgurEmbed = { tasks: $(selector).length };
				}

				XF.loadScript('//s.imgur.com/min/embed-controller.js', function()
				{
					$(document).on('xf:reinit', function(e, el)
					{
						imgurEmbed.tasks += $(selector).length;

						for (var i = 0; i < imgurEmbed.tasks; i++)
						{
							imgurEmbed.createIframe();
							imgurEmbed.tasks --;

						}
					});
				});

				return true;
			},

			pinterest: function()
			{
				XF.loadScript('//assets.pinterest.com/js/pinit.js', function()
				{
					$(document).on('xf:reinit', function(e, el)
					{
						PinUtils.build(el instanceof $ ? el.get(0) : el);
					});
				});

				return true;
			}
		},

		getLocale: function()
		{
			var locale = $('html').attr('lang').replace('-', '_');
			if (!locale)
			{
				locale = 'en_US';
			}

			return locale;
		},

		isEventTouchTriggered: function(e)
		{
			if (!e)
			{
				return Modernizr.touchevents;
			}

			var oe = e.originalEvent;

			if (oe && oe.sourceCapabilities)
			{
				return oe.sourceCapabilities.firesTouchEvents;
			}
			else
			{
				return Modernizr.touchevents;
			}
		},

		getElEffectiveZIndex: function($reference)
		{
			var maxZIndex = parseInt($reference.css('z-index'), 10) || 0;

			$reference.parents().each(function(i, el)
			{
				var zIndex = parseInt($(el).css('z-index'), 10);
				if (zIndex > maxZIndex)
				{
					maxZIndex = zIndex;
				}
			});

			return maxZIndex;
		},

		setRelativeZIndex: function($targets, $reference, offsetAmount, minZIndex)
		{
			if (!minZIndex)
			{
				minZIndex = 6; // make sure we go over the default editor stuff
			}

			var maxZIndex = XF.getElEffectiveZIndex($reference);
			if (minZIndex && minZIndex > maxZIndex)
			{
				maxZIndex = minZIndex;
			}

			if (offsetAmount === null || typeof offsetAmount === 'undefined')
			{
				offsetAmount = 0;
			}

			if (maxZIndex || offsetAmount)
			{
				$targets.each(function()
				{
					var $this = $(this),
						dataKey = 'base-z-index';
					if (typeof $this.data(dataKey) == 'undefined')
					{
						$this.data(dataKey, parseInt($this.css('z-index'), 10) || 0);
					}
					$this.css('z-index', $this.data(dataKey) + offsetAmount + maxZIndex);
				});
			}
			else
			{
				$targets.css('z-index', '');
			}
		},

		adjustHtmlForRte: function(content)
		{
			content = content.replace(/<img[^>]+>/ig, function(match)
			{
				if (match.match(/class="([^"]* )?smilie( |")/))
				{
					var altMatch;
					if (altMatch = match.match(/alt="([^"]+)"/))
					{
						return altMatch[1];
					}
				}

				return match;
			});

			content = content.replace(/([\w\W]|^)<a\s[^>]*data-user-id="\d+"\s+data-username="([^"]+)"[^>]*>([\w\W]+?)<\/a>/gi,
				function(match, prefix, user, username) {
					return prefix + (prefix == '@' ? '' : '@') + username.replace(/^@/, '');
				}
			);

			content = content.replace(/(<img\s[^>]*)src="[^"]*"(\s[^>]*)data-url="([^"]+)"/gi,
				function(match, prefix, suffix, source) {
					return prefix + 'src="' + source + '"' + suffix;
				}
			);

			return content;
		},

		requestAnimationTimeout: function(fn, delay)
		{
			if (!delay)
			{
				delay = 0;
			}

			var raf = window.requestAnimationFrame || function(cb) { return window.setTimeout(cb, 1000 / 60); },
				start = Date.now(),
				data = {};

			function loop()
			{
				if (Date.now() - start >= delay)
				{
					fn();
				}
				else
				{
					data.id = raf(loop);
				}
			}

			data.id = raf(loop);
			data.cancel = function()
			{
				var caf = window.cancelAnimationFrame || window.clearTimeout;
				caf(this.id);
			};

			return data;
		}
	});

	if (typeof Object.create != 'function')
	{
		Object.create = (function()
		{
			var o = function() {};
			return function (prototype)
			{
				o.prototype = prototype;
				var result = new o();
				o.prototype = null;
				return result;
			};
		})();
	}

	XF.create = function(props)
	{
		var fn = function()
		{
			this.__construct.apply(this, arguments);
		};

		fn.prototype = Object.create(props);

		if (!fn.prototype.__construct)
		{
			fn.prototype.__construct = function() {};
		}
		fn.prototype.constructor = fn;

		return fn;
	};

	XF.extend = function(parent, extension)
	{
		var fn = function()
		{
			this.__construct.apply(this, arguments);
		};
		var i;

		fn.prototype = Object.create(parent.prototype);

		if (!fn.prototype.__construct)
		{
			fn.prototype.__construct = function() {};
		}
		fn.prototype.constructor = fn;

		if (typeof extension == 'object')
		{
			if (typeof extension.__backup == 'object')
			{
				var backup = extension.__backup;
				for (i in backup)
				{
					if (backup.hasOwnProperty(i))
					{
						if (fn.prototype[backup[i]])
						{
							throw new Error('Method ' + backup[i] + ' already exists on object. Aliases must be unique.');
						}
						fn.prototype[backup[i]] = fn.prototype[i];
					}
				}

				delete extension.__backup;
			}

			for (i in extension)
			{
				if (extension.hasOwnProperty(i))
				{
					fn.prototype[i] = extension[i];
				}
			}
		}

		return fn;
	};

	XF.classToConstructor = function(className)
	{
		var obj = window,
			parts = className.split('.'),
			i = 0;

		for (i = 0; i < parts.length; i++)
		{
			obj = obj[parts[i]];
		}

		if (typeof obj != 'function')
		{
			console.error('%s is not a function.', className);
			return false;
		}

		return obj;
	};

	XF.Cookie = {
		get: function(name)
		{
			var expr, cookie;

			expr = new RegExp('(^| )' + XF.config.cookie.prefix + name + '=([^;]+)(;|$)');
			cookie = expr.exec(document.cookie);

			if (cookie)
			{
				return decodeURIComponent(cookie[2]);
			}
			else
			{
				return null;
			}
		},

		set: function(name, value, expires)
		{
			var c = XF.config.cookie;

			document.cookie = c.prefix + name + '=' + encodeURIComponent(value)
				+ (expires === undefined ? '' : ';expires=' + expires.toUTCString())
				+ (c.path  ? ';path=' + c.path : '')
				+ (c.domain ? ';domain=' + c.domain : '');
		},

		getJson: function(name)
		{
			var data = this.get(name);
			if (!data)
			{
				return {};
			}

			try
			{
				return $.parseJSON(data) || {};
			}
			catch (e)
			{
				return {};
			}
		},

		setJson: function(name, value, expires)
		{
			this.set(name, JSON.stringify(value), expires);
		},

		remove: function(name)
		{
			var c = XF.config.cookie;

			document.cookie = c.prefix + name + '='
				+ (c.path  ? '; path=' + c.path : '')
				+ (c.domain ? '; domain=' + c.domain : '')
				+ '; expires=Thu, 01-Jan-70 00:00:01 GMT';
		}
	};

	XF.LocalStorage = {
		getKeyName: function(name)
		{
			return XF.config.cookie.prefix + name;
		},

		get: function(name)
		{
			var value  = null;

			try
			{
				value = window.localStorage.getItem(this.getKeyName(name));
			}
			catch (e) {}

			if (value === null)
			{
				var localStorage = this.getFallbackValue();
				if (localStorage && localStorage.hasOwnProperty(name))
				{
					value = localStorage[name];
				}
			}

			return value;
		},

		getJson: function(name)
		{
			var data = this.get(name);
			if (!data)
			{
				return {};
			}

			try
			{
				return $.parseJSON(data) || {};
			}
			catch (e)
			{
				return {};
			}
		},

		set: function(name, value, allowFallback)
		{
			try
			{
				window.localStorage.setItem(this.getKeyName(name), value);
			}
			catch (e)
			{
				if (allowFallback)
				{
					var localStorage = this.getFallbackValue();
					localStorage[name] = value;
					this.updateFallbackValue(localStorage);
				}
			}
		},

		setJson: function(name, value, allowFallback)
		{
			this.set(name, JSON.stringify(value), allowFallback);
		},

		remove: function(name)
		{
			try
			{
				window.localStorage.removeItem(this.getKeyName(name));
			}
			catch (e) {}

			var localStorage = this.getFallbackValue();
			if (localStorage && localStorage.hasOwnProperty(name))
			{
				delete localStorage[name];
				this.updateFallbackValue(localStorage);
			}
		},

		getFallbackValue: function()
		{
			var value = XF.Cookie.get('ls');
			if (value)
			{
				try
				{
					value = $.parseJSON(value);
				}
				catch (e)
				{
					value = {};
				}
			}

			return value || {};
		},

		updateFallbackValue: function(newValue)
		{
			if ($.isEmptyObject(newValue))
			{
				XF.Cookie.remove('ls');
			}
			else
			{
				XF.Cookie.set('ls', JSON.stringify(newValue));
			}
		}
	};

	XF.CrossTab = (function()
	{
		var listeners = {},
			listening = false,
			communicationKey = '__crossTab',
			activeEvent;

		function handleEvent(e)
		{
			var expectedKey = XF.LocalStorage.getKeyName(communicationKey);
			if (e.key !== expectedKey)
			{
				return;
			}

			var json;

			try
			{
				json = $.parseJSON(e.newValue);
			}
			catch (e)
			{
				return;
			}

			if (!json || !json.event)
			{
				return;
			}

			var event = json.event,
				data = json.data || null,
				activeListeners = listeners[event];
			if (!activeListeners)
			{
				return;
			}

			activeEvent = event;

			for (var i = 0; i < activeListeners.length; i++)
			{
				activeListeners[i](data);
			}

			activeEvent = null;
		}

		function on(event, callback)
		{
			if (!listeners[event])
			{
				listeners[event] = [];
			}

			listeners[event].push(callback);

			if (!listening)
			{
				listening = true;
				window.addEventListener('storage', handleEvent);
			}
		}

		function trigger(event, data, forceCall)
		{
			if (!forceCall && activeEvent && activeEvent == event)
			{
				// this is to help prevent infinite loops where the code that reacts to an event
				// is the same code that gets called by the event
				return;
			}

			XF.LocalStorage.setJson(communicationKey, {
				event: event,
				data: data,
				'_': new Date() + Math.random() // forces the event to fire
			});
		}

		return {
			on: on,
			trigger: trigger
		}
	})();

	XF.Breakpoint = (function()
	{
		var val = null,
			sizes = ['narrow', 'medium', 'wide', 'full'];

		function current()
		{
			return val;
		}

		function isNarrowerThan(test)
		{
			for (var i = 0; i < sizes.length; i++)
			{
				if (test == sizes[i])
				{
					return false;
				}

				if (val == sizes[i])
				{
					return true;
				}
			}

			return false;
		}

		function isAtOrNarrowerThan(test)
		{
			return (val == test || isNarrowerThan(test));
		}

		function isWiderThan(test)
		{
			var afterTest = false;

			for (var i = 0; i < sizes.length; i++)
			{
				if (test == sizes[i])
				{
					afterTest = true;
					continue;
				}

				if (val == sizes[i])
				{
					return afterTest;
				}
			}

			return false;
		}

		function isAtOrWiderThan(test)
		{
			return (val == test || isWiderThan(test));
		}

		function refresh()
		{
			var newVal = window.getComputedStyle($('html')[0], ':after').getPropertyValue('content').replace(/\"/g, '');

			if (val)
			{
				if (newVal != val)
				{
					var oldVal = val;
					val = newVal;

					$(document).trigger('breakpoint:change', [oldVal, newVal]);
				}
			}
			else
			{
				// initial load, don't trigger anything
				val = newVal;
			}

			return val;
		}

		refresh();
		$(window).onPassive('resize', refresh);

		return {
			current: current,
			refresh: refresh,
			isNarrowerThan: isNarrowerThan,
			isAtOrNarrowerThan: isAtOrNarrowerThan,
			isWiderThan: isWiderThan,
			isAtOrWiderThan: isAtOrWiderThan
		};
	})();

	XF.JobRunner = (function()
	{
		var manualRunning = false,
			manualOnlyIds = [],
			manualXhr,
			overlay = null;

		var runAuto = function()
		{
			$.ajax({
				url: XF.canonicalizeUrl('job.php'),
				type: 'post',
				cache: false,
				dataType: 'json',
				global: false
			}).always(function(data)
			{
				if (data && data.more)
				{
					setTimeout(runAuto, 100);
				}
			});
		};

		var stopManual = function()
		{
			if (overlay)
			{
				overlay.hide();
			}

			manualOnlyIds = [];
			manualRunning = false;
			$(document).trigger('job:manual-complete');

			if (manualXhr)
			{
				manualXhr.abort();
			}
			manualXhr = null;
		};

		var runManual = function(onlyId)
		{
			var url = XF.config.job.manualUrl;
			if (!url)
			{
				return;
			}

			if (onlyId === null)
			{
				manualOnlyIds = null;
			}
			else
			{
				var manualOnlyIds = manualOnlyIds || [];
				if (typeof onlyId === 'integer')
				{
					manualOnlyIds.push(onlyId);
				}
				else if (Array.isArray(onlyId))
				{
					manualOnlyIds.push.apply(manualOnlyIds, onlyId);
				}
			}

			if (manualRunning)
			{
				return;
			}
			manualRunning = true;

			getManualOverlay().show();

			var runJob = function(runOnlyId) {
				manualXhr = XF.ajax('post', url, runOnlyId ? {only_id: runOnlyId} : null, function(data)
				{
					if (data.jobRunner)
					{
						$('#xfManualJobStatus').text(data.jobRunner.status || XF.phrase('processing...'));

						setTimeout(function ()
						{
							runJob(runOnlyId);
						}, 0);
					}
					else
					{
						runNext();
					}
				}, {skipDefault: true}).fail(stopManual);
			};
			var runNext = function()
			{
				var ids = manualOnlyIds;
				if (Array.isArray(manualOnlyIds) && manualOnlyIds.length == 0)
				{
					stopManual();
				}
				else
				{
					runJob(manualOnlyIds ? manualOnlyIds.shift() : null);
				}
			};
			runNext();
		};

		var getManualOverlay = function()
		{
			if (overlay)
			{
				return overlay;
			}

			var $overlay = XF.getOverlayHtml({
				title: XF.phrase('processing...'),
				dismissible: false,
				html: '<div class="blockMessage"><span id="xfManualJobStatus">' + XF.phrase('processing...') + '</span></div>'
			});
			overlay = new XF.Overlay($overlay, {
				backdropClose: false,
				keyboard: false
			});

			return overlay;
		};

		return {
			isManualJobRunning: function() { return manualRunning; },
			runAuto: runAuto,
			runManual: runManual,
			stopManual: stopManual,
			getManualOverlay: getManualOverlay
		}
	})();

	XF.Loader = (function()
	{
		var loadedCss = XF.config.css,
			loadedJs = XF.config.js;

		var load = function(js, css, onComplete)
		{
			js = js || [];
			css = css || [];

			var loadJs = [], loadCss = [], i;

			for (i = 0; i < js.length; i++)
			{
				if (!loadedJs.hasOwnProperty(js[i]))
				{
					loadJs.push(js[i]);
				}
			}
			for (i = 0; i < css.length; i++)
			{
				if (!loadedCss.hasOwnProperty(css[i]))
				{
					loadCss.push(css[i]);
				}
			}

			var totalRemaining = (loadJs.length ? 1 : 0) + (loadCss.length ? 1 : 0),
				markFinished = function()
				{
					totalRemaining--;
					if (totalRemaining == 0 && onComplete)
					{
						onComplete();
					}
				};

			if (!totalRemaining)
			{
				if (onComplete)
				{
					onComplete();
				}
				return;
			}

			if (loadJs.length)
			{
				XF.loadScripts(loadJs, function()
				{
					$.each(loadJs, function (i, jsFile)
					{
						loadedJs[jsFile] = true;
					});
					markFinished();
				});
			}

			if (loadCss.length)
			{
				var cssUrl = XF.config.url.css;
				if (cssUrl)
				{
					cssUrl = cssUrl.replace('__SENTINEL__', loadCss.join(','));

					$.ajax({
						type: 'GET',
						url: cssUrl,
						cache: true,
						global: false,
						dataType: 'text',
						success: function (cssText)
						{
							var baseHref = XF.config.url.basePath;
							if (baseHref)
							{
								cssText = cssText.replace(
									/(url\(("|')?)([^"')]+)(("|')?\))/gi,
									function (all, front, null1, url, back, null2)
									{
										if (!url.match(/^([a-z]+:|\/)/i))
										{
											url = baseHref + url;
										}
										return front + url + back;
									}
								);
							}

							$('<style>' + cssText + '</style>').appendTo('head');
						}
					}).always(function ()
					{
						$.each(loadCss, function (i, stylesheet)
						{
							loadedCss[stylesheet] = true;
						});
						markFinished();
					});
				}
				else
				{
					console.error('No CSS URL so cannot dynamically load CSS');
					markFinished();
				}
			}
		};

		return {
			load: load,
			loadCss: function(css, onComplete) { load([], css, onComplete); },
			loadJs: function(js, onComplete) { load(js, [], onComplete); }
		};
	})();

	XF.ClassMapper = XF.create({
		_map: {},
		_toExtend: {},

		add: function(identifier, className)
		{
			this._map[identifier] = className;
		},

		extend: function(identifier, extension)
		{
			var obj = this.getObjectFromIdentifier(identifier);
			if (obj)
			{
				obj = XF.extend(obj, extension);
				this._map[identifier] = obj;
			}
			else
			{
				if (!this._toExtend[identifier])
				{
					this._toExtend[identifier] = [];
				}
				this._toExtend[identifier].push(extension);
			}
		},

		getObjectFromIdentifier: function(identifier)
		{
			var record = this._map[identifier],
				extensions = this._toExtend[identifier];

			if (!record)
			{
				return null;
			}

			if (typeof record == 'string')
			{
				record = XF.classToConstructor(record);
				if (extensions)
				{
					for (var i = 0; i < extensions.length; i++)
					{
						record = XF.extend(record, extensions[i]);
					}

					delete this._toExtend[identifier];
				}

				this._map[identifier] = record;
			}

			return record;
		}
	});

	XF.ActionIndicator = (function()
	{
		var activeCounter = 0, $indicator;

		var initialize = function()
		{
			$(document).on({
				ajaxStart: show,
				'xf:action-start': show,
				ajaxStop: hide,
				'xf:action-stop': hide
			});
		};

		var show = function()
		{
			activeCounter++;
			if (activeCounter != 1)
			{
				return;
			}

			if (!$indicator)
			{
				$indicator = $(
					'<span class="globalAction">'
					+ '<span class="globalAction-bar" />'
					+ '<span class="globalAction-block"><i></i><i></i><i></i></span>'
					+ '</span>'
				).appendTo('body');
			}

			$indicator.addClassTransitioned('is-active');
		};

		var hide = function()
		{
			activeCounter--;
			if (activeCounter > 0)
			{
				return;
			}

			activeCounter = 0;
			$indicator.removeClassTransitioned('is-active');
		};

		return {
			initialize: initialize,
			show: show,
			hide: hide
		}
	})();

	XF.DynamicDate = (function()
	{
		var localLoadTime,
			serverLoadTime,
			todayStart,
			todayDow,
			yesterdayStart,
			tomorrowStart,
			weekStart,
			initialized = false,
			interval,
			futureInterval;

		var startInterval = function()
		{
			interval = setInterval(function()
			{
				refresh(document);
			}, 20 * 1000);
		};

		var initialize = function()
		{
			if (initialized)
			{
				return;
			}
			initialized = true;

			var time = XF.config.time,
				$loadCache = $('#_xfClientLoadTime'),
				loadVal = $loadCache.val();

			if (loadVal && loadVal.length)
			{
				var parts = loadVal.split(',');
				if (parts.length == 2 && parseInt(parts[1], 10) == time.now)
				{
					localLoadTime = parseInt(parts[0], 10);
					$loadCache.val(loadVal); // IE needs this to maintain across multiple views
				}
			}

			if (!localLoadTime)
			{
				if (window.performance && window.performance.timing)
				{
					var timing = window.performance.timing;

					// average between request and response start is likely to be somewhere around when the server started
					localLoadTime = Math.floor(
						(timing.requestStart + timing.responseStart) / (2 * 1000)
					);
				}
				else
				{
					localLoadTime = Math.floor(new Date().getTime() / 1000) - 1;
				}
				$loadCache.val(localLoadTime + ',' + time.now);
			}

			serverLoadTime = time.now;
			todayStart = time.today;
			todayDow = time.todayDow;
			yesterdayStart = todayStart - 86400;
			tomorrowStart = todayStart + 86400;
			weekStart = todayStart - 6 * 86400;

			if (document.hidden !== undefined)
			{
				if (!document.hidden)
				{
					startInterval();
				}

				$(document).on('visibilitychange', function()
				{
					if (document.hidden)
					{
						clearInterval(interval);
					}
					else
					{
						startInterval();
						refresh(document);
					}
				});
			}
			else
			{
				startInterval();
			}
		};

		var refresh = function(root)
		{
			if (!initialized)
			{
				this.initialize();
			}

			var $els = $(root).find('time[data-time]'),
				length = $els.length,
				now = Math.floor(new Date().getTime() / 1000),
				openLength = now - localLoadTime;

			var el, $el, interval, futureInterval, dynType, thisTime;

			if (serverLoadTime + openLength > todayStart + 86400)
			{
				// day has changed, need to adjust
				var dayOffset = Math.floor((serverLoadTime + openLength - todayStart) / 86400);

				todayStart += dayOffset * 86400;
				todayDow = (todayDow + dayOffset) % 7;
				yesterdayStart = todayStart - 86400;
				tomorrowStart = todayStart + 86400;
				weekStart = todayStart - 6 * 86400;
			}

			for (var i = 0; i < length; i++)
			{
				el = $els[i];
				$el = $(el);
				thisTime = parseInt(el.getAttribute('data-time'), 10);
				interval = (serverLoadTime - thisTime) + openLength;
				dynType = el.xfDynType;

				if (interval < -2)
				{
					// date in the future, note that -2 is a bit of fudging as times might be very close and our local
					// load time may not jive 100% with the server

					futureInterval = thisTime -  (serverLoadTime + openLength);

					if (futureInterval < 60)
					{
						if (dynType != 'futureMoment')
						{
							$el.text(XF.phrase('in_a_moment'));
							el.xfDynType = 'futureMoment';
						}
					}
					else if (futureInterval < 120)
					{
						if (dynType != 'futureMinute')
						{
							$el.text(XF.phrase('in_a_minute'));
							el.xfDynType = 'futureMinute';
						}
					}
					else if (futureInterval < 3600)
					{
						var minutes = Math.floor(futureInterval / 60);
						if (dynType !== 'futureMinutes' + minutes)
						{
							$el.text(XF.phrase('in_x_minutes', {
								'{minutes}': minutes
							}));
							el.xfDynType = 'futureMinutes' + minutes;
						}
					}
					else if (thisTime < tomorrowStart)
					{
						if (dynType != 'latertoday')
						{
							$el.text(XF.phrase('later_today_at_x', {
								'{time}': $el.attr('data-time-string')
							}));
							el.xfDynType = 'latertoday';
						}
					}
					else if (thisTime < tomorrowStart + 86400)
					{
						if (dynType != 'tomorrow')
						{
							$el.text(XF.phrase('tomorrow_at_x', {
								'{time}': $el.attr('data-time-string')
							}));
							el.xfDynType = 'tomorrow';
						}
					}
					else if (futureInterval < (7 * 86400))
					{
						// no need to change anything
						el.xfDynType = 'future';
					}
					else
					{
						// after the next week
						$el.text($el.attr('data-date-string'));
						el.xfDynType = 'future';
					}
				}
				else if (interval <= 60)
				{
					if (dynType !== 'moment')
					{
						$el.text(XF.phrase('a_moment_ago'));
						el.xfDynType = 'moment';
					}
				}
				else if (interval <= 120)
				{
					if (dynType !== 'minute')
					{
						$el.text(XF.phrase('one_minute_ago'));
						el.xfDynType = 'minute';
					}
				}
				else if (interval < 3600)
				{
					var minutes = Math.floor(interval / 60);
					if (dynType !== 'minutes' + minutes)
					{
						$el.text(XF.phrase('x_minutes_ago', {
							'{minutes}': minutes
						}));
						el.xfDynType = 'minutes' + minutes;
					}
				}
				else if (thisTime >= todayStart)
				{
					if (dynType !== 'today')
					{
						$el.text(XF.phrase('today_at_x', {
							'{time}': $el.attr('data-time-string') // must use attr for string value
						}));
						el.xfDynType = 'today';
					}
				}
				else if (thisTime >= yesterdayStart)
				{
					if (dynType !== 'yesterday')
					{
						$el.text(XF.phrase('yesterday_at_x', {
							'{time}': $el.attr('data-time-string') // must use attr for string value
						}));
						el.xfDynType = 'yesterday';
					}
				}
				else if (thisTime >= weekStart)
				{
					if (dynType !== 'week')
					{
						var calcDow = todayDow - Math.ceil((todayStart - thisTime) / 86400);
						if (calcDow < 0)
						{
							calcDow += 7;
						}

						$el.text(XF.phrase('day_x_at_time_y', {
							'{day}': XF.phrase('day' + calcDow),
							'{time}': $el.attr('data-time-string') // must use attr for string value
						}));
						el.xfDynType = 'week';
					}
				}
				else
				{
					if (dynType !== 'old')
					{
						if ($el.attr('data-full-old-date'))
						{
							$el.text(XF.phrase('date_x_at_time_y', {
								'{date}': $el.attr('data-date-string'), // must use attr for string value
								'{time}': $el.attr('data-time-string') // must use attr for string value
							}));
						}
						else
						{
							$el.text($el.attr('data-date-string')); // must use attr for string value
						}

						el.xfDynType = 'old';
					}
				}
			}
		};

		return {
			initialize: initialize,
			refresh: refresh
		};
	})();

	XF.KeepAlive = (function()
	{
		var url,
			crossTabEvent,
			initialized = false,
			baseTimerDelay = 50 * 60, // in seconds, 50 minutes
			jitterRange = 120,
			interval;

		var initialize = function()
		{
			if (initialized)
			{
				return;
			}

			if (!XF.config.url.keepAlive || !XF.config.url.keepAlive.length)
			{
				return;
			}
			initialized = true;

			url = XF.config.url.keepAlive;
			crossTabEvent = 'keepAlive' + XF.stringHashCode(url);

			resetTimer();

			XF.CrossTab.on(crossTabEvent, applyChanges);

			if (window.performance && window.performance.navigation)
			{
				var navType = window.performance.navigation.type;
				if (navType == 0 || navType == 1)
				{
					// navigate or reload, we have the most recent data from the server so pass that on
					XF.CrossTab.trigger(crossTabEvent, {
						csrf: XF.config.csrf,
						time: XF.config.time.now,
						user_id: XF.config.userId
					});
				}
			}

			if (!XF.Cookie.get('csrf'))
			{
				refresh();
			}
		};

		var resetTimer = function()
		{
			var rand = function(min, max)
			{
				return Math.floor(Math.random() * (max - min + 1)) + min;
			};

			var delay = baseTimerDelay + rand(-jitterRange, jitterRange); // +/- jitter to prevent opened tabs sticking together
			if (delay < jitterRange)
			{
				delay = jitterRange;
			}

			if (interval)
			{
				clearInterval(interval);
			}
			interval = setInterval(refresh, delay * 1000);

			// note that while this should be reset each time it's triggered, using an interval ensures
			// that it runs again even if there's an error
		};

		var offlineCount = 0,
			offlineDelayTimer;

		var refresh = function()
		{
			if (!initialized)
			{
				return;
			}

			// if we're offline, delay testing by 30 seconds a few times. This tries to maintain the keep alive
			// when there are temporary network drops or if waking up from sleep and the network isn't ready yet.
			if (window.navigator.onLine === false)
			{
				offlineCount++;

				if (offlineCount <= 5)
				{
					offlineDelayTimer = setTimeout(refresh, 30);
				}
			}

			offlineCount = 0;
			clearTimeout(offlineDelayTimer);

			$.ajax({
				url: XF.canonicalizeUrl(url),
				data: {
					_xfResponseType: 'json',
					_xfToken: XF.config.csrf
				},
				type: 'post',
				cache: false,
				dataType: 'json',
				global: false
			}).done(function(data)
			{
				if (data.status != 'ok')
				{
					return;
				}

				applyChanges(data);
				XF.CrossTab.trigger(crossTabEvent, data);
			});
		};

		var applyChanges = function(data)
		{
			if (data.csrf)
			{
				XF.config.csrf = data.csrf;
				$('input[name=_xfToken]').val(data.csrf);
			}

			if (typeof data.user_id !== 'undefined')
			{
				var $activeChangeMessage = $('.js-activeUserChangeMessage');

				if (data.user_id != XF.config.userId && !$activeChangeMessage.length)
				{
					XF.addFixedMessage(XF.phrase('active_user_changed_reload_page'), {
						'class': 'js-activeUserChangeMessage'
					});
				}
				if (data.user_id == XF.config.userId && $activeChangeMessage.length)
				{
					$activeChangeMessage.remove();
				}
			}

			resetTimer();
		};

		return {
			initialize: initialize,
			refresh: refresh
		};
	})();

	// ################################## LINK PROXY WATCHER ###########################################

	XF.LinkWatcher = (function()
	{
		var proxyInternals = false;

		var proxyLinkClick = function(e)
		{
			var $this = $(this),
				proxyHref = $this.data('proxy-href'),
				lastEvent = $this.data('proxy-handler-last');

			if (!proxyHref)
			{
				return;
			}

			// we may have a direct click event and a bubbled event. Ensure they don't both fire.
			if (lastEvent && lastEvent == e.timeStamp)
			{
				return;
			}
			$this.data('proxy-handler-last', e.timeStamp);

			$.ajax({
				url: XF.canonicalizeUrl(proxyHref),
				data: { _xfResponseType: 'json', referrer: window.location.href.replace(/#.*$/, '') },
				type: 'post',
				cache: false,
				dataType: 'json',
				global: false
			});
		};

		var initLinkProxy = function()
		{
			var selector = 'a[data-proxy-href]',
				dataAttrAttacher = 'proxy-handler';

			if (!proxyInternals)
			{
				selector += ':not(.link--internal)';
			}

			$(document)
				.on('click', selector, proxyLinkClick)
				.on('focusin', selector, function(e)
				{
					// This approach is taken because middle click events do not bubble. This is a way of
					// getting the equivalent of event bubbling on middle clicks in Chrome.
					var $this = $(this);
					if ($this.data(dataAttrAttacher))
					{
						return;
					}

					$this.data(dataAttrAttacher, true)
						.click(proxyLinkClick);
				});
		};

		var externalLinkClick = function(e)
		{
			if (!XF.config.enableRtnProtect)
			{
				return;
			}

			if (e.isDefaultPrevented())
			{
				return;
			}

			var $this = $(this),
				href = $this.attr('href'),
				lastEvent = $this.data('blank-handler-last');
			if (!href)
			{
				return;
			}

			if (href.match(/^[a-z]:/i) && !href.match(/^https?:/i))
			{
				// ignore canonical but non http(s) links
				return;
			}

			// if noopener is supported and in use, then use that instead
			if ($this.is('[rel~=noopener]'))
			{
				var browser = XF.browser;
				if (
					(browser.chrome && browser.version >= 49)
					|| (browser.mozilla && browser.version >= 52)
					|| (browser.safari && browser.version >= 11) // may be supported in some 10.x releases
					// Edge and IE don't support it yet
				)
				{
					return;
				}
			}

			if ($this.closest('[contenteditable=true]').length)
			{
				return;
			}

			href = XF.canonicalizeUrl(href);

			var regex = new RegExp('^[a-z]+://' + location.host + '(/|$|:)', 'i');
			if (regex.test(href))
			{
				// if the link is local, then don't do the special processing
				return;
			}

			// we may have a direct click event and a bubbled event. Ensure they don't both fire.
			if (lastEvent && lastEvent == e.timeStamp)
			{
				return;
			}

			$this.data('blank-handler-last', e.timeStamp);

			var ua = navigator.userAgent,
				isOldIE = ua.indexOf('MSIE') !== -1,
				isSafari = ua.indexOf('Safari') !== -1 && ua.indexOf('Chrome') == -1,
				isGecko = ua.indexOf('Gecko/') !== -1;

			if (e.shiftKey && isGecko)
			{
				// Firefox doesn't trigger when holding shift. If the code below runs, it will force
				// opening in a new tab instead of a new window, so stop. Note that Chrome still triggers here,
				// but it does open in a new window anyway so we run the normal code.
				return;
			}
			if (isSafari && (e.shiftKey || e.altKey))
			{
				// this adds to reading list or downloads instead of opening a new tab
				return;
			}
			if (isOldIE)
			{
				// IE has mitigations for this and this blocks referrers
				return;
			}

			// now run the opener clearing

			if (isSafari)
			{
				// Safari doesn't work with the other approach
				// Concept from: https://github.com/danielstjules/blankshield
				var $iframe, iframeDoc, $script;

				$iframe = $('<iframe style="display: none" />').appendTo(document.body);
				iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;

				iframeDoc.__href = href; // set this so we don't need to do an eval-type thing

				$script = $('<script />', iframeDoc);
				$script[0].text = 'window.opener=null;' +
					'window.parent=null;window.top=null;window.frameElement=null;' +
					'window.open(document.__href).opener = null;';

				iframeDoc.body.appendChild($script[0]);
				$iframe.remove();
			}
			else
			{
				// use this approach for the rest to maintain referrers when possible
				var w = window.open(href);

				try
				{
					// this can potentially fail, don't want to break
					w.opener = null;
				}
				catch (e) {}
			}

			e.preventDefault();
		};

		var initExternalWatcher = function()
		{
			var selector = 'a[target=_blank]',
				dataAttrAttacher = 'blank-handler';

			$(document)
				.on('click', selector, externalLinkClick)
				.on('focusin', selector, function(e)
				{
					// This approach is taken because middle click events do not bubble. This is a way of
					// getting the equivalent of event bubbling on middle clicks in Chrome.
					var $this = $(this);
					if ($this.data(dataAttrAttacher))
					{
						return;
					}

					$this.data(dataAttrAttacher, true)
						.click(externalLinkClick);
				});
		};

		return {
			initLinkProxy: initLinkProxy,
			initExternalWatcher: initExternalWatcher
		};
	})();

	// ################################## IGNORED CONTENT WATCHER ###########################################

	XF._IgnoredWatcher = XF.create({
		options: {
			container: 'body',
			ignored: '.is-ignored',
			link: '.js-showIgnored'
		},

		$container: null,
		authors: [],
		shown: false,

		__construct: function(options)
		{
			this.options = $.extend(true, {}, this.options, options || {});

			var $container = $(this.options.container);
			this.$container = $container;

			this.updateState();

			$container.on('click', this.options.link, $.proxy(this, 'show'));
		},

		refresh: function($el)
		{
			if (!this.$container.find($el).length)
			{
				// el is not in our search area
				return;
			}

			if (this.shown)
			{
				// already showing, so apply that here as well
				this.show();
			}
			else
			{
				this.updateState();
			}
		},

		updateState: function()
		{
			if (this.shown)
			{
				// already showing
				return;
			}

			var $ignored = this.getIgnored(),
				authors = [];

			if (!$ignored.length)
			{
				// nothing to do - assume hidden by default
				return;
			}

			$ignored.each(function()
			{
				var author = $(this).data('author');
				if (author && $.inArray(author, authors) === -1)
				{
					authors.push(author);
				}
			});

			if (authors.length)
			{
				var textReplace = { names: authors.join(', ') };

				this.getLinks().each(function()
				{
					var $link = $(this),
						title = $link.attr('title');
					if (title)
					{
						$link.attr('title', Mustache.render(title, textReplace))
							.removeClass('is-hidden');
					}
				});
			}
			else
			{
				this.getLinks().each(function()
				{
					$(this).removeAttr('title').removeClass('is-hidden');
				});
			}
		},

		getIgnored: function()
		{
			return this.$container.find(this.options.ignored);
		},

		getLinks: function()
		{
			return this.$container.find(this.options.link);
		},

		show: function()
		{
			this.shown = true;
			this.getIgnored().removeClass('is-ignored');
			this.getLinks().addClass('is-hidden');
		},

		initializeHash: function()
		{
			if (window.location.hash)
			{
				var $jump = $(window.location.hash.replace(/[^\w_#-]/g, '')),
					ignoredSel = this.options.ignored,
					$ignored;

				if ($jump.is(ignoredSel))
				{
					$ignored = $jump;
				}
				else
				{
					$ignored = $jump.closest(ignoredSel);
				}

				if ($ignored && $ignored.length)
				{
					$ignored.removeClass('is-ignored');
					$jump.get(0).scrollIntoView(true);
				}
			}
		}
	});
	XF.IgnoreWatcher = new XF._IgnoredWatcher();

	// ################################## OVERLAY HANDLER ###########################################

	XF.Overlay = XF.create({
		options: {
			backdropClose: true,
			escapeClose: true,
			focusShow: true
		},

		$container: null,
		$overlay: null,
		shown: false,

		__construct: function(content, options)
		{
			this.options = $.extend(true, {}, this.options, options || {});

			this.$overlay = content instanceof $ ? content : $($.parseHTML(content));
			this.$overlay.attr('role', this.options.role || 'dialog')
				.attr('aria-hidden', 'true');

			this.$container = $('<div class="overlay-container" />').html(this.$overlay);
			this.$container
				.data('overlay', this)
				.xfUniqueId();

			var self = this;

			if (this.options.backdropClose)
			{
				this.$container.on('mousedown', function(e)
				{
					self.$container.data('block-close', false);

					if (!$(e.target).is(self.$container))
					{
						// click didn't target container so block closing.
						self.$container.data('block-close', true);
					}
				});

				this.$container.on('click', function(e)
				{
					if ($(e.target).is(self.$container))
					{
						if (!self.$container.data('block-close'))
						{
							self.hide();
						}
					}

					self.$container.data('block-close', false);
				});
			}

			this.$container.on('click', '.js-overlayClose', $.proxy(this, 'hide'));

			this.$container.appendTo('body');
			XF.activate(this.$container);

			XF.Overlay.cache[this.$container.attr('id')] = this;

			this.$overlay.on('overlay:hide', $.proxy(this, 'hide'));
			this.$overlay.on('overlay:show', $.proxy(this, 'show'));
		},

		show: function()
		{
			if (this.shown)
			{
				return;
			}

			var self = this,
				$overlay = this.$overlay;

			this.shown = true;

			$overlay.attr('aria-hidden', 'false');

			// reappending to the body ensures this is the last one, which should allow stacking
			this.$container.appendTo('body').addClassTransitioned('is-active', function()
			{
				if (self.options.focusShow)
				{
					var $autoFocusFallback = $overlay.find('.js-overlayClose');
					XF.autoFocusWithin($overlay.find('.overlay-content'), null, $autoFocusFallback);
				}

				self.$container.trigger('overlay:shown');
				XF.layoutChange();
			});

			self.$container.trigger('overlay:showing');

			XF.Modal.open();
			XF.layoutChange();
		},

		hide: function()
		{
			if (!this.shown)
			{
				return;
			}

			this.shown = false;

			this.$overlay.attr('aria-hidden', 'true');

			var self = this;
			this.$container.removeClassTransitioned('is-active', function()
			{
				self.$container.trigger('overlay:hidden');
				XF.Modal.close();
				XF.layoutChange();
			});

			self.$container.trigger('overlay:hiding');

			XF.layoutChange();
		},

		recalculate: function()
		{
			if (this.shown)
			{
				XF.Modal.updateScrollbarPadding();
			}
		},

		toggle: function()
		{
			this.shown ? this.hide() : this.show();
		},

		destroy: function()
		{
			var id = this.$container.attr('id'),
				cache = XF.Overlay.cache;

			this.$container.remove();
			if (cache.hasOwnProperty(id))
			{
				delete cache[id];
			}
		},

		on: function()
		{
			this.$container.on.apply(this.$container, arguments);
		},

		getContainer: function() { return this.$container; },
		getOverlay: function() { return this.$overlay; }
	});
	XF.Overlay.cache = {};

	XF.Modal = (function()
	{
		var count = 0,
			$applyEl = $('body').first(),
			$html = $('html');

		var open = function()
		{
			count++;
			if (count == 1)
			{
				$applyEl.addClass('is-modalOpen');
				updateScrollbarPadding();
			}
		};
		var close = function()
		{
			if (count > 0)
			{
				count--;
				if (count == 0)
				{
					$applyEl.removeClass('is-modalOpen');
					updateScrollbarPadding();
				}
			}
		};

		var updateScrollbarPadding = function()
		{
			var side = 'right',
				value = $applyEl.hasClass('is-modalOpen') ? XF.measureScrollBar() + 'px' : '';

			if (XF.isRtl())
			{
				// Chrome and Firefox keep the body scrollbar on the right but IE/Edge flips it
				if (!XF.browser.chrome && !XF.browser.mozilla)
				{
					side = 'left';
				}
			}

			$html.css('margin-' + side, value);
		};

		return {
			getOpenCount: function() { return count; },
			open: open,
			close: close,
			updateScrollbarPadding: updateScrollbarPadding
		};
	})();

	XF.showOverlay = function($html, options)
	{
		var overlay = new XF.Overlay($html, options);
		overlay.show();
		return overlay;
	};
	XF.loadOverlay = function(url, options, overlayOptions)
	{
		if ($.isFunction(options))
		{
			options = {init: options};
		}

		options = $.extend({
			cache: false,
			beforeShow: null,
			afterShow: null,
			onRedirect: null,
			init: null,
			show: true
		}, options || {});

		var show = function(overlay)
		{
			if (options.beforeShow)
			{
				var e = $.Event();
				options.beforeShow(overlay, e);
				if (e.isDefaultPrevented())
				{
					return;
				}
			}

			if (options.show)
			{
				overlay.show();
			}

			if (options.afterShow)
			{
				var e = $.Event();
				options.afterShow(overlay, e);
				if (e.isDefaultPrevented())
				{
					return;
				}
			}
		};

		if (options.cache && XF.loadOverlay.cache[url])
		{
			show(XF.loadOverlay.cache[url]);
			return;
		}

		var overlayAjaxHandler = function(data)
		{
			if (data.redirect)
			{
				if (options.onRedirect)
				{
					options.onRedirect(data, overlayAjaxHandler);
				}
				else
				{
					XF.ajax('get', data.redirect, function(data)
					{
						overlayAjaxHandler(data);
					});
				}
			}

			if (!data.html)
			{
				return;
			}

			XF.setupHtmlInsert(data.html, function($html, container, onComplete)
			{
				var overlay = new XF.Overlay(XF.getOverlayHtml({
					html: $html,
					title: container.title || container.h1
				}), overlayOptions);
				if (options.init)
				{
					options.init(overlay);
				}
				if (!options.cache)
				{
					overlay.on('overlay:hidden', function()
					{
						overlay.destroy();
					});
				}

				onComplete();

				if (options.cache)
				{
					XF.loadOverlay.cache[url] = overlay;
				}

				show(overlay);
			});
		};

		return XF.ajax('get', url, function(data)
		{
			overlayAjaxHandler(data);
		});
	};
	XF.loadOverlay.cache = {};

	// ################################## NAVIGATION DEVICE WATCHER ###########################################

	/**
	 * Allows querying of the current input device (mouse or keyboard) -- .isKeyboardNav()
	 * And sets a CSS class (has-pointer-nav) on <html> to allow styling based on current input
	 *
	 * @type {{initialize, toggle, isKeyboardNav}}
	 */
	XF.NavDeviceWatcher = (function()
	{
		var isKeyboard = true;

		function initialize()
		{
			$(document).onPassive(
			{
				mousedown: function()
				{
					toggle(false);
				},
				keydown: function(e)
				{
					switch (e.which)
					{
						case 9: // tab
						case 13: // enter
							toggle(true);
					}
				}
			});
		}

		function toggle(toKeyboard)
		{
			if (toKeyboard != isKeyboard)
			{
				$('html').toggleClass('has-pointer-nav', !toKeyboard);

				isKeyboard = toKeyboard;
			}
		}

		function isKeyboardNav()
		{
			return isKeyboard;
		}

		return {
			initialize: initialize,
			toggle: toggle,
			isKeyboardNav: isKeyboardNav
		};
	})();

	XF.ScrollButtons = (function()
	{
		var hideTimer = null,
			pauseScrollWatch = false,
			upOnly = false,
			isShown = false,
			scrollTop = window.pageYOffset || document.documentElement.scrollTop,
			scrollDir = null,
			scrollTopDirChange = null,
			scrollTrigger,
			$buttons = null;

		function initialize()
		{
			if ($buttons && $buttons.length)
			{
				// already initialized
				return false;
			}

			$buttons = $('.js-scrollButtons');
			if (!$buttons.length)
			{
				return false;
			}

			if ($buttons.data('trigger-type') === 'up')
			{
				upOnly = true;
			}

			$buttons.on({
				'mouseenter focus': enter,
				'mouseleave blur': leave,
				'click': click
			});

			$(window).onPassive('scroll', onScroll);

			return true;
		}

		function onScroll(e)
		{
			if (pauseScrollWatch)
			{
				return;
			}

			var newScrollTop = window.pageYOffset || document.documentElement.scrollTop,
				oldScrollTop = scrollTop;

			scrollTop = newScrollTop;

			if (newScrollTop > oldScrollTop)
			{
				if (scrollDir != 'down')
				{
					scrollDir = 'down';
					scrollTopDirChange = oldScrollTop;
				}
			}
			else if (newScrollTop < oldScrollTop)
			{
				if (scrollDir != 'up')
				{
					scrollDir = 'up';
					scrollTopDirChange = oldScrollTop;
				}
			}
			else
			{
				// didn't scroll?
				return;
			}

			if (upOnly)
			{
				// downward scroll or we're near the top anyway
				if (scrollDir !== 'up' || scrollTop < 100)
				{
					if (scrollTrigger)
					{
						scrollTrigger.cancel();
						scrollTrigger = null;
					}
					return;
				}

				// only trigger after scrolling up 30px to reduce false positives
				if (scrollTopDirChange - newScrollTop < 30)
				{
					return;
				}
			}

			if (scrollTrigger)
			{
				// already about to be triggered
				return;
			}

			// note that Chrome on Android can heavily throttle setTimeout, so use a requestAnimationFrame
			// alternative if possible to ensure this triggers when expected
			scrollTrigger = XF.requestAnimationTimeout(function()
			{
				scrollTrigger = null;

				show();
				startHideTimer();
			}, 200);
		}

		function show()
		{
			if (!isShown)
			{
				$buttons.addClassTransitioned('is-active');
				isShown = true;
			}
		}

		function hide()
		{
			if (isShown)
			{
				$buttons.removeClassTransitioned('is-active');
				isShown = false;
			}
		}

		function startHideTimer()
		{
			clearHideTimer();

			hideTimer = setTimeout(function()
			{
				hide();
			}, 3000);
		}

		function clearHideTimer()
		{
			clearTimeout(hideTimer);
		}

		function enter()
		{
			clearHideTimer();

			show();
		}

		function leave()
		{
			clearHideTimer();
		}

		function click()
		{
			pauseScrollWatch = true;

			setTimeout(function()
			{
				pauseScrollWatch = false;
			}, 500);

			hide();
		}

		return {
			initialize: initialize,
			show: show,
			hide: hide,
			startHideTimer: startHideTimer,
			clearHideTimer: clearHideTimer
		}
	})();

	// ################################## KEYBOARD SHORTCUT HANDLER ###########################################

	/**
	 * Activates keyboard shortcuts for elements based on data-xf-key attributes
	 *
	 * @type {{initialize, initializeElements}}
	 */
	XF.KeyboardShortcuts = (function()
	{
		var shortcuts = {},

			Ctrl = 1,
			Alt  = 2,
			Meta = 4,

			debug = false;

		function initialize()
		{
			$(document.body).onPassive('keyup', keyEvent);
		}

		function initializeElements(root)
		{
			var $root = $(root);

			if ($root.length > 1)
			{
				$root.each(function() { initializeElements(this); });
				return;
			}

			if ($root.is('[data-xf-key]'))
			{
				initializeElement($root[0]);
			}
			$root.find('[data-xf-key]').each(function()
			{
				initializeElement(this);
			});

			if (debug) console.info('Registered keyboard shortcuts: %o', shortcuts);
		}

		function initializeElement(el)
		{
			// accepts a shortcut key either as 'a', 'B', etc., or a charcode with a # prefix - '#97', '#56'
			var shortcut = String($(el).data('xf-key')),
				key = shortcut.substr(shortcut.lastIndexOf('+') + 1),
				charCode = key[0] === '#' ? key.substr(1): key.toUpperCase().charCodeAt(0),
				codeInfo = shortcut.toUpperCase().split('+'),
				modifierCode = getModifierCode(
					codeInfo.indexOf('CTRL') !== -1,
					codeInfo.indexOf('ALT') !== -1,
					codeInfo.indexOf('META') !== -1
				);

			if (modifierCode)
			{
				if (isStandardKey(charCode))
				{
					shortcuts[charCode] = shortcuts[charCode] || {};
					shortcuts[charCode][modifierCode] = el;

					if (debug) console.info('Shortcut %c%s%c registered as %s + %s for %s', 'color:red;font-weight:bold;font-size:larger', shortcut, 'color:inherit;font-weight:inherit;font-size:inherit', charCode, modifierCode, el);
				}
				else
				{
					console.warn('It is not possible to specify a keyboard shortcut using this key combination (%s)', shortcut);
				}
			}
			else
			{
				shortcuts[key] = el;

				if (debug) console.info('Shortcut %c%s%c registered as %s for %s', 'color:red;font-weight:bold;font-size:larger', shortcut, 'color:inherit;font-weight:inherit;font-size:inherit', key, el);
			}
		}

		function keyEvent(e)
		{
			switch (e.which)
			{
				case 27: // escape
					XF.MenuWatcher.closeAll(); // close all menus
					XF.hideOverlays(); // close all overlays
					XF.hideTooltips();
					return;

				case 16: // shift
				case 17: // ctrl
				case 18: // alt
				case 91: // meta
					return;
			}

			if (!isShortcutAllowed(document.activeElement))
			{
				return;
			}

			if (debug) console.log('KEYUP: key:%s, which:%s (charCode from key: %s), Decoded from e.which: %s%s%s%s',
				e.key, e.which, e.key.charCodeAt(0),
				(e.ctrlKey?"CTRL+":""),
				(e.altKey?"ALT+":""),
				(e.metaKey?"META+":""),
				String.fromCharCode(e.which)
			);

			if (shortcuts.hasOwnProperty(e.key)) // try simple mapping first
			{
				if (fireShortcut(shortcuts[e.key]))
				{
					return;
				}
			}

			if (shortcuts.hasOwnProperty(e.which)) // try complex mapping next
			{
				var modifierCode = getModifierCodeFromEvent(e);

				if (shortcuts[e.which].hasOwnProperty(modifierCode))
				{
					if (fireShortcut(shortcuts[e.which][modifierCode]))
					{
						return;
					}
				}
			}
		}

		function fireShortcut(target)
		{
			// only act on elements that are :visible
			var $target = $(target).filter(':visible');

			if ($target.length)
			{
				XF.NavDeviceWatcher.toggle(true);

				if ($target.is(XF.getKeyboardInputs()))
				{
					$target.autofocus();
				}
				else if ($target.is('a[href]'))
				{
					$target.get(0).click();
				}
				else
				{
					$target.click();
				}

				return true;
			}

			return false;
		}

		function isShortcutAllowed(activeElement)
		{
			switch (activeElement.tagName)
			{
				case 'TEXTAREA':
					return false;

				case 'INPUT':
					switch (activeElement.type)
					{
						case 'checkbox':
						case 'radio':
						case 'submit':
						case 'reset':
							return true;
						default:
							return false;
					}

				case 'BODY':
					return true;

				default:
					return activeElement.contentEditable === 'true' ? false : true;
			}
		}

		function isStandardKey(which)
		{
			return (which >= 48 && which <= 90);
		}

		function getModifierCode(CtrlKey, AltKey, MetaKey)
		{
			return 0
			+ CtrlKey  ? Ctrl  : 0
			+ AltKey   ? Alt   : 0
			+ MetaKey  ? Meta  : 0;
		}

		function getModifierCodeFromEvent(event)
		{
			return getModifierCode(event.ctrlKey, event.altKey, event.metaKey);
		}

		return {
			initialize: initialize,
			initializeElements: initializeElements
		}
	})();

	// ################################## BB CODE EXPAND WATCHER ###########################################

	XF.BbBlockExpand = (function()
	{
		var containerSel = '.bbCodeBlock--expandable';

		function watch()
		{
			$(document).on('click', '.bbCodeBlock-expandLink', function(e)
			{
				var $target = $(e.target);
				$target.closest(containerSel).addClassTransitioned('is-expanded', XF.layoutChange);
			});

			$(window).onPassive('resize', function()
			{
				checkSizing(document);
			});

			$(document).on('embed:loaded', function()
			{
				checkSizing(document);
			});
		}

		function checkSizing(el)
		{
			$(el).find(containerSel + ':not(.is-expanded)').each(function()
			{
				var $this = $(this),
					content = $this.find('.bbCodeBlock-expandContent')[0];

				if (!content)
				{
					return;
				}

				var timer,
					delay = 0,
					check = function()
					{
						var scroll = content.scrollHeight,
							offset = content.offsetHeight;

						if (scroll == 0 || offset == 0)
						{
							if (delay > 1000)
							{
								return;
							}
							if (timer)
							{
								clearTimeout(timer);
								delay += 200;
							}
							timer = setTimeout(check, delay);
							return;
						}

						if (scroll > offset + 1) // +1 resolves a Chrome rounding issue
						{
							$this.addClass('is-expandable');
						}
						else
						{
							$this.removeClass('is-expandable');
						}
					};

				check();

				if (!$this.data('expand-check-triggered'))
				{
					$this.data('expand-check-triggered', true);

					$this.find('img').one('load', check);

					if (window.MutationObserver)
					{
						var observer,
							mutationTimeout,
							allowMutationTrigger = true,
							mutationTrigger = function()
							{
								allowMutationTrigger = false;
								check();

								// prevent triggers for a little bit after this so we limit thrashing
								setTimeout(function()
								{
									allowMutationTrigger = true;
								}, 100);
							};

						observer = new MutationObserver(function(mutations)
						{
							if ($this.hasClass('is-expanded'))
							{
								observer.disconnect();
								return;
							}

							if (!allowMutationTrigger)
							{
								return;
							}

							if (mutationTimeout)
							{
								clearTimeout(mutationTimeout);
							}
							mutationTimeout = setTimeout(mutationTrigger, 200);
						});
						observer.observe(this, {
							attributes: true,
							childList: true,
							subtree: true
						});
					}
				}
			});
		}

		return {
			watch: watch,
			checkSizing: checkSizing
		}
	})();

	// ################################## CLICK HANDLER SYSTEM ###########################################

	XF.Click = (function()
	{
		var mapper = new XF.ClassMapper();

		var watch = function()
		{
			$(document).on('click', '[data-xf-click]', function(e)
			{
				var target = e.currentTarget;
				if (!target || !target.getAttribute)
				{
					// not an element so can't have a handler
					return;
				}

				if ($(target).closest('[contenteditable=true]').length)
				{
					return;
				}

				initElement(target, e);
			});
		};

		var initElement = function(target, clickE)
		{
			var $target = $(target),
				handlerList = $target.data('xf-click').split(' ') || [],
				handlerObjs = $target.data('xf-click-handlers') || {},
				ident, obj, i, options;

			for (i = 0; i < handlerList.length; i++)
			{
				ident = handlerList[i];
				if (!ident.length)
				{
					continue;
				}
				if (!handlerObjs[ident])
				{
					obj = mapper.getObjectFromIdentifier(ident);
					if (!obj)
					{
						console.error('Could not find handler for ' + ident);
						continue;
					}

					options = $target.data('xf-' + ident) || {};
					handlerObjs[ident] = new obj($target, options);
				}

				if (clickE && handlerObjs[ident]._click(clickE) === false)
				{
					break;
				}
			}

			$target.data('xf-click-handlers', handlerObjs);

			return handlerObjs;
		};

		var getElementHandler = function($el, handlerName)
		{
			var handlers = $el.data('xf-click-handlers');

			if (!handlers)
			{
				handlers = XF.Click.initElement($el[0]);
			}

			if (handlers && handlers[handlerName])
			{
				return handlers[handlerName];
			}
			else
			{
				return null;
			}
		};

		var AbstractHandler = XF.create(
		{
			initialized: false,
			eventNameSpace: null,
			$target: null,
			options: {},

			__construct: function($target, options)
			{
				this.$target = $target;
				this.options = XF.applyDataOptions(this.options, $target.data(), options);

				if (!this.eventNameSpace)
				{
					throw new Error('Please provide an eventNameSpace for your extended click handler class');
				}

				this._init();
			},

			/**
			 * 'protected' wrapper function for init(),
			 *  containing Before/AfterInit events
			 */
			_init: function()
			{
				var beforeInitEvent = new $.Event('xf-click:before-init.' + this.eventNameSpace),
					returnValue = false;

				this.$target.trigger(beforeInitEvent, [this]);

				if (!beforeInitEvent.isDefaultPrevented())
				{
					returnValue = this.init();

					this.$target.trigger('xf-click:after-init.' + this.eventNameSpace, [this, returnValue]);
				}

				this.initialized = true;

				return returnValue;
			},

			/**
			 * 'protected' wrapper function for click(),
			 *  containing Before/AfterClick events
			 */
			_click: function(e)
			{
				var beforeClickEvent = new $.Event('xf-click:before-click.' + this.eventNameSpace),
					returnValue = null;

				this.$target.trigger(beforeClickEvent, [this]);

				if (!beforeClickEvent.isDefaultPrevented())
				{
					returnValue = this.click(e);

					this.$target.trigger('xf-click:after-click.' + this.eventNameSpace, [this, returnValue, e]);
				}

				return null;
			},

			// methods to be overridden by inheriting classes
			init: function()
			{
				console.error('This is the abstract init method for XF.Click, '
					+ 'which should be overridden.');
			},
			click: function(e)
			{
				console.error('This is the abstract click method for XF.Click, '
					+ 'which should be overridden.');
			}
		});

		return {
			watch: watch,
			initElement: initElement,
			getElementHandler: getElementHandler,
			register: function(identifier, className) { mapper.add(identifier, className); },
			extend: function(identifier, extension) { mapper.extend(identifier, extension); },
			newHandler: function(extend)
			{
				return XF.extend(AbstractHandler, extend);
			},

			AbstractHandler: AbstractHandler
		};
	})();

	// ################################## ELEMENT HANDLER SYSTEM ##########################################

	XF.Element = (function()
	{
		var elements = [];

		var mapper = new XF.ClassMapper();

		var applyHandler = function($el, handlerId, options)
		{
			var handlers = $el.data('xf-element-handlers') || {};
			if (handlers[handlerId])
			{
				return handlers[handlerId];
			}

			var ctor = mapper.getObjectFromIdentifier(handlerId);
			if (!ctor)
			{
				return null;
			}

			var obj = new ctor($el, options || {});

			handlers[handlerId] = obj;
			$el.data('xf-element-handlers', handlers);

			var hp = _getHandlerPrefixed(handlerId);
			elements[hp] = elements[hp] || [];
			elements[hp].push(obj);

			obj.init();

			return obj;
		};

		var getHandlers = function(handlerId)
		{
			var hp = _getHandlerPrefixed(handlerId);

			if (typeof elements[hp] != 'undefined')
			{
				return elements[hp];
			}

			return false;
		};

		var _getHandlerPrefixed = function(handlerId)
		{
			return 'XF.Element.Handler.' + handlerId;
		};

		var getHandler = function($el, handlerId)
		{
			var handlers = $el.data('xf-element-handlers');
			if (handlers === undefined)
			{
				initializeElement($el);
				handlers = $el.data('xf-element-handlers');
			}

			if (handlers && handlers[handlerId])
			{
				return handlers[handlerId];
			}
			else
			{
				return null;
			}
		};

		var initializeElement = function(el)
		{
			if (el instanceof $)
			{
				el = el[0];
			}

			if (!el || !el.getAttribute)
			{
				// not an element -- probably a text node
				return;
			}

			var init = el.getAttribute('data-xf-init');
			if (!init)
			{
				return;
			}

			var parts = init.split(' '),
				len = parts.length,
				$el = $(el),
				handlerId;
			for (var i = 0; i < len; i++)
			{
				handlerId = parts[i];
				if (!handlerId)
				{
					continue;
				}

				applyHandler($el, handlerId, $el.data('xf-' + handlerId));
			}
		};

		var initialize = function(root)
		{
			var $root = $(root);

			if ($root.length > 1)
			{
				$root.each(function() { initialize(this); });
				return;
			}

			var initCallback = function()
			{
				if (!this || !this.getAttribute)
				{
					// not an element -- probably a text node
					return;
				}

				var $this = $(this),
					init = this.getAttribute('data-xf-init');
				if (!init)
				{
					return;
				}

				var parts = init.split(' '),
					len = parts.length,
					handlerId;
				for (var i = 0; i < len; i++)
				{
					handlerId = parts[i];
					if (!handlerId)
					{
						continue;
					}

					applyHandler($this, handlerId, $this.data('xf-' + handlerId));
				}
			};

			if ($root.is('[data-xf-init]'))
			{
				initializeElement($root[0]);
			}
			$root.find('[data-xf-init]').each(function() { initializeElement(this); });
		};

		var AbstractHandler = XF.create(
		{
			$target: null,
			options: {},

			__construct: function ($target, options)
			{
				this.$target = $target;
				this.options = XF.applyDataOptions(this.options, $target.data(), options);
			},

			init: function()
			{
				console.error('This is the abstract init method for XF.Element, '
					+ 'which should be overridden.');
			},

			getOption: function(option)
			{
				return this.options[option];
			}
		});

		return {
			register: function(identifier, className) { mapper.add(identifier, className); },
			extend: function(identifier, extension) { mapper.extend(identifier, extension); },
			initialize: initialize,
			initializeElement: initializeElement,
			applyHandler: applyHandler,
			getHandler: getHandler,
			getHandlers: getHandlers,
			newHandler: function(extend)
			{
				return XF.extend(AbstractHandler, extend);
			},

			AbstractHandler: AbstractHandler
		};
	})();

	XF.AutoCompleteResults = XF.create({
		selectedResult: 0,
		$results: false,
		$scrollWatchers: null,
		resultsVisible: false,
		resizeBound: false,
		options: {},

		__construct: function(options)
		{
			this.options = $.extend({
				onInsert: null,
				clickAttacher: null
			}, options);
		},

		isVisible: function()
		{
			return this.resultsVisible;
		},

		hideResults: function()
		{
			this.resultsVisible = false;

			if (this.$results)
			{
				this.$results.hide();
			}
			this.stopScrollWatching();
		},

		stopScrollWatching: function()
		{
			if (this.$scrollWatchers)
			{
				this.$scrollWatchers.off('scroll.autocomplete');
				this.$scrollWatchers = null;
			}
		},

		showResults: function(val, results, $targetOver, cssPosition)
		{
			var maxZIndex = 0,
				i,
				filterRegex,
				result,
				$li;

			if (!results)
			{
				this.hideResults();
				return;
			}

			this.resultsVisible = false;

			if (!this.$results)
			{
				this.$results = $('<ul />')
					.css({position: 'absolute', display: 'none'})
					.addClass('autoCompleteList')
					.attr('role', 'listbox')
					.appendTo(document.body);

				XF.setRelativeZIndex(this.$results, $targetOver, 1);
			}
			else
			{
				this.$results.hide().empty();
			}

			filterRegex = new RegExp('(' + XF.regexQuote(val) + ')', 'i');

			for (i in results)
			{
				if (!results.hasOwnProperty(i))
				{
					continue;
				}

				result = results[i];

				$li = $('<li />')
					.css('cursor', 'pointer')
					.attr('unselectable', 'on')
					.attr('role', 'option')
					.mouseenter($.proxy(this, 'resultMouseEnter'));

				if (this.options.clickAttacher)
				{
					this.options.clickAttacher($li, $.proxy(this, 'resultClick'));
				}
				else
				{
					$li.click($.proxy(this, 'resultClick'));
				}

				if (typeof result == 'string')
				{
					$li.html(XF.htmlspecialchars(result).replace(filterRegex, '<strong>$1</strong>'))
						.data('autocomplete', result);
				}
				else
				{
					$li.html(XF.htmlspecialchars(result.text).replace(filterRegex, '<strong>$1</strong>'))
						.data('autocomplete', result.text);

					if (typeof result.icon !== 'undefined')
					{
						$li.prepend($('<img class="autoCompleteList-icon" />').attr('src', result.icon));
					}
					else if (typeof result.iconHtml !== 'undefined')
					{
						$li.prepend($('<span class="autoCompleteList-icon" />').html(result.iconHtml));
					}
				}

				$li.appendTo(this.$results);
			}

			if (!this.$results.children().length)
			{
				return;
			}

			this.selectResult(0, true);

			if (!this.resizeBound)
			{
				$(window).onPassive('resize', $.proxy(this, 'hideResults'));
			}

			this.$results.css({
				top: '',
				left: '',
				right: '',
				bottom: ''
			});

			var $results = this.$results,
				getPositioning = function(cssPosition)
				{
					if ($.isFunction(cssPosition))
					{
						cssPosition = cssPosition($results, $targetOver);
					}

					if (!cssPosition)
					{
						var offset = $targetOver.offset();

						cssPosition = {
							top: offset.top + $targetOver.outerHeight(),
							left: offset.left
						};

						if (XF.isRtl())
						{
							cssPosition.right = $('html').width() - offset.left - $targetOver.outerWidth();
							cssPosition.left = 'auto';
						}
					}

					return cssPosition;
				};

			// if this is in a scrollable area, watch anything scrollable
			this.stopScrollWatching();
			var $scrollWatchers = $targetOver.parents().filter(function()
			{
				switch ($(this).css('overflow-x'))
				{
					case 'scroll':
					case 'auto':
						return true;

					default:
						return false;
				}
			});
			if ($scrollWatchers && $scrollWatchers.length)
			{
				$scrollWatchers.on('scroll.autocomplete', function()
				{
					$results.css(getPositioning(cssPosition));
				});

				this.$scrollWatchers = $scrollWatchers;
			}

			this.$results.css(getPositioning(cssPosition)).show();
			this.resultsVisible = true;
		},

		resultClick: function(e)
		{
			e.stopPropagation();

			this.insertResult($(e.currentTarget).data('autocomplete'));
			this.hideResults();
		},

		resultMouseEnter: function (e)
		{
			this.selectResult($(e.currentTarget).index(), true);
		},

		selectResult: function(shift, absolute)
		{
			var sel, children;

			if (!this.$results)
			{
				return;
			}

			if (absolute)
			{
				this.selectedResult = shift;
			}
			else
			{
				this.selectedResult += shift;
			}

			sel = this.selectedResult;
			children = this.$results.children();
			children.each(function(i)
			{
				if (i == sel)
				{
					$(this).addClass('is-selected');
				}
				else
				{
					$(this).removeClass('is-selected');
				}
			});

			if (sel < 0 || sel >= children.length)
			{
				this.selectedResult = -1;
			}
		},

		insertSelectedResult: function()
		{
			var res, ret = false;

			if (!this.resultsVisible)
			{
				return false;
			}

			if (this.selectedResult >= 0)
			{
				res = this.$results.children().get(this.selectedResult);
				if (res)
				{
					this.insertResult($(res).data('autocomplete'));
					ret = true;
				}
			}

			this.hideResults();

			return ret;
		},

		insertResult: function(value)
		{
			if (this.options.onInsert)
			{
				this.options.onInsert(value);
			}
		}
	});

	$(XF.onPageLoad);
}
(window.jQuery, window, document);