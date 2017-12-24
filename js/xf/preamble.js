var XF = window.XF || {};

/**
 * Deal with the console not being present
 */
!function(w) {
	var fn, i = 0;
	if (!w.console) w.console = {};
	if (w.console.log && !w.console.debug) w.console.debug = w.console.log;
	fn = ['assert', 'clear', 'count', 'debug', 'dir', 'dirxml', 'error', 'getFirebugElement', 'group', 'groupCollapsed', 'groupEnd', 'info', 'log', 'notifyFirebug', 'profile', 'profileEnd', 'time', 'timeEnd', 'trace', 'warn'];
	for (i = 0; i < fn.length; ++i) if (!w.console[fn[i]]) w.console[fn[i]] = function() {};
}(window);

!function(window, document)
{
	"use strict";

	// IMPORTANT - jQuery is not available here!
	// Keep this code minimal and only for things that need to happen in the <head> tag.

	var docEl = document.documentElement,
		cookiePrefix = docEl.getAttribute('data-cookie-prefix') || '',
		app = docEl.getAttribute('data-app'),
		loggedIn = docEl.getAttribute('data-logged-in') === 'true';

	function readCookie(name)
	{
		var expr = new RegExp('(^| )' + cookiePrefix + name + '=([^;]+)(;|$)'),
			cookie = expr.exec(document.cookie);

		return cookie ? decodeURIComponent(cookie[2]) : null;
	}

	function insertCss(css)
	{
		var el = document.createElement('style');
		el.type = 'text/css';
		el.innerHTML = css;
		document.head.appendChild(el);
	}

	if (app === 'public' && !loggedIn)
	{
		// prevent page jumping from dismissed notices for guests
		(function()
		{
			var dismissedNoticeCookie = readCookie('notice_dismiss'),
				dismissedNotices = dismissedNoticeCookie ? dismissedNoticeCookie.split(',') : [],
				noticeId,
				selectors = [];

			for (var i = 0; i < dismissedNotices.length; i++)
			{
				noticeId = parseInt(dismissedNotices[i], 10);
				if (noticeId > 0)
				{
					selectors.push('.notice[data-notice-id="' + noticeId + '"]');
				}
			}

			if (selectors.length)
			{
				insertCss(selectors.join(', ') + ' { display: none !important } ');
			}
		})();
	}

	(function()
	{
		var ua = navigator.userAgent.toLowerCase(),
			match,
			browser;

		match = /trident\/.*rv:([0-9.]+)/.exec(ua);
		if (match)
		{
			browser = {
				browser: 'msie',
				version: parseFloat(match[1])
			}
		}
		else
		{
			// this is different regexes as we need the particular order
			match = /(msie)[ \/]([0-9\.]+)/.exec(ua)
				|| /(edge)[ \/]([0-9\.]+)/.exec(ua)
            	|| /(chrome)[ \/]([0-9\.]+)/.exec(ua)
				|| /(webkit)[ \/]([0-9\.]+)/.exec(ua)
				|| /(opera)(?:.*version|)[ \/]([0-9\.]+)/.exec(ua)
				|| ua.indexOf('compatible') < 0 && /(mozilla)(?:.*? rv:([0-9\.]+)|)/.exec(ua)
				|| [];

			if (match[1] == 'webkit' && ua.indexOf('safari'))
			{
				var safariMatch = /version[ \/]([0-9\.]+)/.exec(ua);
				if (safariMatch)
				{
					match = [match[0], 'safari', safariMatch[1]];
				}
			}

			browser = {
				browser: match[1] || '',
				version: parseFloat(match[2]) || 0
			};
		}

		if (browser.browser)
		{
			browser[browser.browser] = true;
		}

		var os = '',
			osVersion = null,
			osMatch;

		if (/(ipad|iphone|ipod)/.test(ua))
		{
			os = 'ios';
			if (osMatch = /os ([0-9_]+)/.exec(ua))
			{
				osVersion = parseFloat(osMatch[1].replace('_', '.'));
			}
		}
		else if (osMatch = /android[ \/]([0-9\.]+)/.exec(ua))
		{
			os = 'android';
			osVersion = parseFloat(osMatch[1]);
		}
		else if (/windows /.test(ua))
		{
			os = 'windows';
		}
		else if (/linux/.test(ua))
		{
			os = 'linux';
		}
		else if (/mac os/.test(ua))
		{
			os = 'mac';
		}

		browser.os = os;
		browser.osVersion = osVersion;
		if (os)
		{
			browser[os] = true;
		}

		XF.browser = browser;
	})();
}
(window, document);