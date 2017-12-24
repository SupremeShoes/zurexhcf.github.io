/** @param {jQuery} $ jQuery Object */
!function($, window, document)
{
	"use strict";

	// ################################## SUBMIT CHANGE HANDLER ###########################################

	XF.SubmitClick = XF.Click.newHandler({
		eventNameSpace: 'XFSubmitClick',
		options: {
			target: null,
			container: null,
			timeout: 500,
			uncheckedValue: '0',
			disable: null
		},

		$input: null,
		$form: null,

		init: function()
		{
			var $input = this.$target;

			if ($input.is('label'))
			{
				$input = $input.find('input:radio, input:checkbox');
				if (!$input.length)
				{
					return;
				}
			}

			this.$input = $input;

			var $form = $input.closest('form');
			this.$form = $form.length ? $form : null;
		},

		click: function(e)
		{
			var $input = this.$input,
				$form = this.$form,
				target = this.options.target,
				container = this.options.container;
			if (!$input)
			{
				return;
			}

			if (target)
			{
				var unchecked = this.options.uncheckedValue;

				setTimeout(function()
				{
					var data = {};

					if (container)
					{
						data = $input.closest(container).find('input, select, textarea').serializeArray();
					}
					else
					{
						data[$input.attr('name')] = $input.prop('checked') ? $input.attr('value') : unchecked;
					}

					XF.ajax('POST', target, data);
				}, 0);
			}
			else if ($form)
			{
				var timer = $form.data('submit-click-timer');
				if (timer)
				{
					clearTimeout(timer);
				}

				$form.one('ajax-submit:complete', function(e, data, submitter)
				{
					if (data.errors)
					{
						// undo the checked status change
						$input.prop('checked', ($input.prop('checked') ? '' : 'checked'));
					}
					else
					{
						// toggle 'dataList-row--disabled' for the parent dataList-row, if there is one
						if ($input.attr('type') == 'checkbox' && $input.closest('tr.dataList-row') !== null)
						{
							$input.closest('tr.dataList-row')[($input.prop('checked') ? 'removeClass' : 'addClass')]('dataList-row--disabled');
						}
					}
				});

				timer = setTimeout(function()
				{
					$form.submit();
				}, this.options.timeout);

				$form.data('submit-click-timer', timer);
			}
			else
			{
				console.error('No target or form to submit on click');
			}
		}
	});

	// ################################## AJAX FORM SUBMISSION ###########################################

	XF.AjaxSubmit = XF.Element.newHandler({
		options: {
			redirect: true,
			skipOverlayRedirect: false,
			forceFlashMessage: false,
			resetComplete: false,
			hideOverlay: true,
			disableSubmit: '.button, :submit, :reset, [data-disable-submit]',
			jsonName: null,
			jsonOptIn: null,
			replace: null
		},

		submitPending: false,
		$submitButton: null,

		init: function()
		{
			var $form = this.$target;

			if (!$form.is('form'))
			{
				console.error('%o is not a form', $form[0]);
				return;
			}

			$form.on({
				submit: $.proxy(this, 'submit'),
				keyup: $.proxy(this, 'cmdEnterKey'),
				'draft:beforesave': $.proxy(this, 'draftCheck')
			});
			$form.on('click', 'input[type=submit], button:not([type]), button[type=submit]', $.proxy(this, 'submitButtonClicked'));
		},

		submit: function(e)
		{
			var $submitButton = this.$submitButton,
				$form = this.$target;

			if ($form.attr('enctype') == 'multipart/form-data')
			{
				if (this.options.jsonName)
				{
					// JSON encoding would try to encode the upload which will break it, so prevent submission and error.
					e.preventDefault();
					console.error('JSON serialized forms do not support the file upload-style enctype.');
					XF.alert(XF.phrase('oops_we_ran_into_some_problems_more_details_console'));
					return;
				}

				if (!window.FormData)
				{
					// This is an upload type form and the browser cannot support AJAX submission for this.
					return;
				}
			}

			if (this.$submitButton && this.$submitButton.data('prevent-ajax'))
			{
				return;
			}

			if (this.submitPending)
			{
				if (e)
				{
					e.preventDefault();
				}
				return;
			}

			var event = $.Event('ajax-submit:before'),
				config = {
					form: $form,
					handler: this,
					method: $form.attr('method') || 'get',
					action: $form.attr('action'),
					submitButton: $submitButton,
					preventSubmit: false,
					successCallback: $.proxy(this, 'submitResponse'),
					ajaxOptions: { skipDefault: true }
				};

			$form.trigger(event, config);

			if (config.preventSubmit)
			{
				// preventing any submit
				return false;
			}
			if (event.isDefaultPrevented())
			{
				// preventing ajax submission
				return true;
			}

			if (e)
			{
				e.preventDefault();
			}

			var t = this;

			// do this in a timeout to ensure that all other submit handlers run
			setTimeout(function()
			{
				t.submitPending = true;

				var formData = XF.getDefaultFormData($form, $submitButton, t.options.jsonName, t.options.jsonOptIn);

				t.disableButtons();

				XF.ajax(
					config.method,
					config.action,
					formData,
					config.successCallback,
					config.ajaxOptions
				).always(function()
				{
					t.$submitButton = null;

					// delay re-enable slightly to allow animation to potentially happen
					setTimeout(function()
					{
						t.submitPending = false;
						t.enableButtons();
					}, 300);

					event = $.Event('ajax-submit:always');
					$form.trigger(event, $form, t);
				});
			}, 0);
		},

		disableButtons: function()
		{
			var disable = this.options.disableSubmit;
			if (!disable)
			{
				return;
			}

			this.$target.find(disable).prop('disabled', true);
		},

		enableButtons: function()
		{
			var disable = this.options.disableSubmit;
			if (!disable)
			{
				return;
			}

			this.$target.find(disable).prop('disabled', false);
		},

		submitResponse: function(data, status, xhr)
		{
			if (typeof data != 'object')
			{
				XF.alert('Response was not JSON.');
				return;
			}

			var $form = this.$target,
				$submitButton = this.$submitButton;

			var event = $.Event('ajax-submit:response');
			$form.trigger(event, data, this);
			if (event.isDefaultPrevented())
			{
				return;
			}

			var errorEvent = $.Event('ajax-submit:error'),
				hasError = false,
				doRedirect = data.redirect && this.options.redirect,
				$overlay = $form.closest('.overlay');

			if (!$overlay.length || !this.options.hideOverlay)
			{
				$overlay = null;
			}

			if (doRedirect && this.options.skipOverlayRedirect && $overlay)
			{
				doRedirect = false;
			}

			if ($submitButton && $submitButton.attr('data-ajax-redirect'))
			{
				doRedirect = $submitButton.data('ajax-redirect');
			}

			if (data.errorHtml)
			{
				$form.trigger(errorEvent, data, this);
				if (!errorEvent.isDefaultPrevented())
				{
					XF.setupHtmlInsert(data.errorHtml, function($html, container)
					{
						var title = container.h1 || container.title || XF.phrase('oops_we_ran_into_some_problems');
						XF.overlayMessage(title, $html);
					});
				}

				hasError = true;
			}
			else if (data.errors)
			{
				$form.trigger(errorEvent, data, this);
				if (!errorEvent.isDefaultPrevented())
				{
					XF.alert(data.errors);
				}

				hasError = true;
			}
			else if (data.exception)
			{
				XF.alert(data.exception);
			}
			else if (data.status == 'ok' && data.message)
			{
				if (doRedirect)
				{
					if (this.options.forceFlashMessage)
					{
						XF.flashMessage(data.message, 1000, function()
						{
							XF.redirect(data.redirect);
						});
					}
					else
					{
						XF.redirect(data.redirect);
					}
				}
				else
				{
					XF.flashMessage(data.message, 3000);
				}

				if ($overlay)
				{
					$overlay.trigger('overlay:hide');
				}
			}
			else if (data.html)
			{
				var self = this;

				XF.setupHtmlInsert(data.html, function($html, container, onComplete)
				{
					if (self.options.replace && self.doSubmitReplace($html, onComplete))
					{
						return false; // handle on complete when finished
					}

					if ($overlay)
					{
						$overlay.trigger('overlay:hide');
					}

					var $childOverlay = XF.getOverlayHtml({
						html: $html,
						title: container.h1 || container.title
					});
					XF.showOverlay($childOverlay);
				});
			}
			else if (data.status == 'ok')
			{
				if (doRedirect)
				{
					XF.redirect(data.redirect);
				}

				if ($overlay)
				{
					$overlay.trigger('overlay:hide');
				}
			}

			if (data.errors && !errorEvent.isDefaultPrevented())
			{
				// TODO: tie to individual fields?
			}

			event = $.Event('ajax-submit:complete');
			$form.trigger(event, data, this);
			if (event.isDefaultPrevented())
			{
				return;
			}

			if (this.options.resetComplete && !hasError)
			{
				$form[0].reset();
			}
		},

		doSubmitReplace: function($html, onComplete)
		{
			var replace = this.options.replace;

			if (!replace)
			{
				return false;
			}

			var parts = replace.split(' with '),
				selectorOld = $.trim(parts[0]),
				selectorNew = parts[1] ? $.trim(parts[1]) : selectorOld,
				$old, $new;

			if (selectorOld == 'self' || this.$target.is(selectorOld))
			{
				$old = this.$target;
			}
			else
			{
				$old = this.$target.find(selectorOld).first();
				if (!$old.length)
				{
					$old = $(selectorOld).first();
				}
			}

			if (!$old.length)
			{
				console.error("Could not find old selector '" + selectorOld + "'");
				return false;
			}

			if ($html.is(selectorNew))
			{
				$new = $html;
			}
			else
			{
				$new = $html.find(selectorNew).first();
			}

			if (!$new.length)
			{
				console.error("Could not find new selector '" + selectorNew + "'");
				return false;
			}

			$new.hide().insertAfter($old);
			$old.xfFadeUp(null, function()
			{
				$old.remove();

				if ($new.length)
				{
					XF.activate($new);
					onComplete(false);
				}

				$new.xfFadeDown(null, XF.layoutChange);
			});

			return true;
		},

		submitButtonClicked: function(e)
		{
			this.$submitButton = $(e.currentTarget);
		},

		draftCheck: function(e)
		{
			if (this.submitPending)
			{
				e.preventDefault();
			}
		}
	});

	// ################################## AUTO COMPLETE ###########################################

	XF.AutoComplete = XF.Element.newHandler({
		loadTimer: null,
		loadVal: '',
		results: null,

		options: {
			single: false,
			multiple: ',', // multiple value joiner (used if single == true)
			acurl: '',
			minLength: 2, // min word length before lookup
			queryKey: 'q',
			extraFields: '',
			extraParams: {},
			jsonContainer: 'results',
			autosubmit: false
		},

		init: function()
		{
			var $input = this.$target;

			if (this.options.autosubmit)
			{
				this.options.single = true;
			}

			if (!this.options.acurl)
			{
				this.options.acurl = XF.getAutoCompleteUrl();
			}

			this.results = new XF.AutoCompleteResults({
				onInsert: $.proxy(this, 'addValue')
			});

			$input.attr('autocomplete', 'off')
				.on({
					keydown: $.proxy(this, 'keydown'),
					keyup: $.proxy(this, 'keyup'),
					'blur click': $.proxy(this, 'blur'),
					paste: function()
					{
						setTimeout(function() { $input.trigger('keydown'); }, 0);
					}
				});

			$input.closest('form').submit($.proxy(this, 'hideResults'))
		},

		keydown: function(e)
		{
			if (!this.results.isVisible())
			{
				return;
			}

			var results = this.results,
				prevent = function() { e.preventDefault(); return false; };

			switch (e.which)
			{
				case 40: // down
					results.selectResult(1);
					return prevent();

				case 38: // up
					results.selectResult(-1);
					return prevent();

				case 27: // esc
					this.hide();
					return prevent();

				case 13: // enter
					results.insertSelectedResult();
					return prevent();
			}
		},

		keyup: function(e)
		{
			if (this.results.isVisible())
			{
				switch (e.which)
				{
					case 40: // down
					case 38: // up
					case 13: // enter
						return;
				}
			}

			this.hideResults();

			if (this.loadTimer)
			{
				clearTimeout(this.loadTimer);
			}
			this.loadTimer = setTimeout($.proxy(this, 'load'), 200);
		},

		blur: function(e)
		{
			clearTimeout(this.loadTimer);

			// timeout ensures that clicks still register
			setTimeout($.proxy(this, 'hideResults'), 250);

			if (this.xhr)
			{
				this.xhr.abort();
				this.xhr = false;
			}
		},

		load: function()
		{
			var lastLoad = this.loadVal,
				params = this.options.extraParams;

			if (this.loadTimer)
			{
				clearTimeout(this.loadTimer);
			}

			this.loadVal = this.getPartialValue();

			if (this.loadVal == '')
			{
				this.hideResults();
				return;
			}

			if (this.loadVal == lastLoad)
			{
				return;
			}

			if (this.loadVal.length < this.options.minLength)
			{
				return;
			}

			params[this.options.queryKey] = this.loadVal;

			if (this.options.extraFields != '')
			{
				$(this.options.extraFields).each(function()
				{
					params[this.name] = $(this).val();
				});
			}

			if (this.xhr)
			{
				this.xhr.abort();
			}

			this.xhr = XF.ajax(
				'get',
				this.options.acurl,
				params,
				$.proxy(this, 'showResults'),
				{ global: false, error: false }
			);
		},

		hideResults: function()
		{
			this.results.hideResults();
		},

		showResults: function(results)
		{
			if (this.xhr)
			{
				this.xhr = false;
			}

			if (this.options.jsonContainer && results)
			{
				results = results[this.options.jsonContainer];
			}

			this.results.showResults(this.getPartialValue(), results, this.$target);
		},

		addValue: function(value)
		{
			if (this.options.single)
			{
				this.$target.val(value);
			}
			else
			{
				var values = this.getFullValues();
				if (value != '')
				{
					if (values.length)
					{
						value = ' ' + value;
					}
					values.push(value + this.options.multiple + ' ');
				}
				this.$target.val(values.join(this.options.multiple));
			}
			this.$target.trigger("auto-complete:insert", {inserted: value, current: this.$target.val()});

			if (this.options.autosubmit)
			{
				this.$target.closest('form').submit();
			}
			else
			{
				this.$target.autofocus();
			}
		},

		getFullValues: function()
		{
			var val = this.$target.val(),
				splitPos = '';

			if (val == '')
			{
				return [];
			}

			if (this.options.single)
			{
				return [val];
			}
			else
			{
				splitPos = val.lastIndexOf(this.options.multiple);
				if (splitPos == -1)
				{
					return [];
				}
				else
				{
					val = val.substr(0, splitPos);
					return val.split(this.options.multiple);
				}
			}
		},

		getPartialValue: function()
		{
			var val = this.$target.val(),
				splitPos;

			if (this.options.single)
			{
				return $.trim(val);
			}
			else
			{
				splitPos = val.lastIndexOf(this.options.multiple);
				if (splitPos == -1)
				{
					return $.trim(val);
				}
				else
				{
					return $.trim(val.substr(splitPos + this.options.multiple.length));
				}
			}
		}
	});

	// ################################## USER MENTIONER ###########################################

	XF.UserMentioner = XF.Element.newHandler({

		options: {},

		$input: null,
		visible: false,
		idleWait: 200,

		idleTimer: null,
		pendingMention: '',
		results: null,

		init: function()
		{
			this.$input = this.$target;

			var t = this;
			this.results = new XF.AutoCompleteResults({
				onInsert: function(name)
				{
					t.insertMention(name);
				}
			});

			this.$input.on('keydown', $.proxy(this, 'keydown'));
			this.$input.on('keyup', $.proxy(this, 'keyup'));
			this.$input.on('blur click', $.proxy(this, 'blur'));
			$(document).on('scroll', $.proxy(this, 'blur'))
		},

		keydown: function(e)
		{
			if (!this.visible)
			{
				return;
			}

			switch (e.which)
			{
				case 40: // down
					this.results.selectResult(1);
					e.preventDefault();
					return false;

				case 38: // up
					this.results.selectResult(-1);
					e.preventDefault();
					return false;

				case 27: // esc
					this.hide();
					e.preventDefault();
					return false;

				case 13: // enter
					if (this.visible)
					{
						e.preventDefault();
						this.results.insertSelectedResult();
						return false;
					}
					break;
			}
		},

		keyup: function(e)
		{
			if (this.visible)
			{
				switch (e.which)
				{
					case 40: // down
					case 38: // up
					case 13: // enter
						return;
				}
			}

			this.hide();

			if (this.idleTimer)
			{
				clearTimeout(this.idleTimer);
			}
			this.idleTimer = setTimeout($.proxy(this, 'lookForMention'), this.idleWait);
		},

		blur: function()
		{
			if (!this.visible)
			{
				return;
			}

			// timeout ensures that clicks still register
			setTimeout($.proxy(this, 'hide'), 250);
		},

		lookForMention: function()
		{
			var mention = this.getCurrentMentionInfo();
			if (mention)
			{
				this.foundMention(mention.name);
			}
			else
			{
				this.hide();
			}
		},

		getCurrentMentionInfo: function()
		{
			var $input = this.$input;
			$input.autofocus();
			var sel = $input.getSelection(),
				testText;

			if (!sel || sel.end <= 1)
			{
				return false;
			}

			var text = $input.val().substring(0, sel.end),
				lastAt = text.lastIndexOf('@');

			if (lastAt == -1) // no @
			{
				return null;
			}

			if (lastAt == 0 || text.substr(lastAt - 1, 1).match(/(\s|[\](,]|--)/))
			{
				var afterAt = text.substr(lastAt + 1);
				if (!afterAt.match(/\s/) || afterAt.length <= 10)
				{
					return {
						text: text,
						start: lastAt,
						name: afterAt.replace(new RegExp(String.fromCharCode(160), 'g'), ' '),
						range: sel
					};
				}
			}

			return null;
		},

		foundMention: function(mention)
		{
			if (this.pendingMention == mention)
			{
				return;
			}

			this.pendingMention = mention;

			if (mention.length >= 2 && mention.substr(0, 1) != '[')
			{
				this.getPendingMentionOptions();
			}
		},

		getPendingMentionOptions: function()
		{
			XF.ajax(
				'GET', XF.getAutoCompleteUrl(), { q: this.pendingMention },
				$.proxy(this, 'handlePendingMentionOptions'),
				{ global: false, error: false }
			);
		},

		handlePendingMentionOptions: function(data)
		{
			var current = this.getCurrentMentionInfo();

			if (!data.q || !current || data.q != current.name)
			{
				return;
			}

			if (data.results)
			{
				this.show(data.q, data.results);
			}
			else
			{
				this.hide();
			}
		},

		insertMention: function(mention)
		{
			this.hide();

			var $input = this.$input;

			$input.autofocus();

			var mentionInfo = this.getCurrentMentionInfo();
			if (!mentionInfo)
			{
				return;
			}

			var afterAtPos = mentionInfo.start + 1,
				sel = mentionInfo.range;

			if (afterAtPos != -1)
			{
				$input.setSelection(mentionInfo.start, sel.end);
				$input.replaceSelectedText('@' + mention + ' ', 'collapseToEnd');
			}
		},

		show: function(val, results)
		{
			var mentionInfo = this.getCurrentMentionInfo();
			if (!mentionInfo)
			{
				return;
			}

			this.visible = true;
			this.results.showResults(val, results, this.$input);
		},

		hide: function()
		{
			if (this.visible)
			{
				this.visible = false;
				this.results.hideResults();
			}
		}
	});

	// ################################## AUTO SUBMIT ###########################################

	XF.AutoSubmit = XF.Element.newHandler({

		options: {
			hide: true,
			progress: true
		},

		init: function()
		{
			this.$target.submit();

			if (this.options.hide)
			{
				this.$target.find(':submit').hide();
			}
			if (this.options.progress)
			{
				$(document).trigger('xf:action-start');
			}
		}
	});

	// ################################## CHANGED FIELD ###########################################

	XF.ChangedFieldNotifier = XF.Element.newHandler({

		options: {
			hide: true,
			progress: true
		},

		init: function ()
		{
			this.$target.find('input, select, textarea').each(function()
			{
				var $el = $(this);
				$el.data('orig-val', $el.val());

				$el.change(function()
				{
					$el.toggleClass('is-changed', ($el.val() != $el.data('orig-val')));
				})
			});
		}
	});

	// ################################## CHECK ALL HANDLER ###########################################

	XF.CheckAll = XF.Element.newHandler({
		options: {
			container: '< form',
			match: 'input:checkbox'
		},

		$container: null,
		updating: false,

		init: function()
		{
			this.$container = XF.findRelativeIf(this.options.container, this.$target);

			var t = this;
			this.$container.on('click', this.options.match, function(e)
			{
				if (t.updating)
				{
					return;
				}

				var $target = $(e.target);
				if ($target.is(t.$target))
				{
					return;
				}

				t.updateState();
			});

			this.updateState();

			this.$target.click($.proxy(this, 'click'));
		},

		click: function(e)
		{
			this.updating = true;
			this.getCheckBoxes().prop('checked', e.target.checked).triggerHandler('click');
			this.updating = false;
		},

		updateState: function()
		{
			var $checkboxes = this.getCheckBoxes(),
				allSelected = $checkboxes.length > 0;

			$checkboxes.each(function() {
				if (!$(this).prop('checked'))
				{
					allSelected = false;
					return false;
				}
			});

			this.$target.prop('checked', allSelected);
		},

		getCheckBoxes: function()
		{
			return this.$container.find(this.options.match).not(this.$target);
		}
	});

	// ################################## DATE INPUT HANDLER ###########################################

	XF.DateInput = XF.Element.newHandler({
		options: {
			weekStart: 0,
			minDate: null,
			maxDate: null,
			disableWeekends: false,
			yearRange: null,
			showWeekNumber: false,
			showDaysInNextAndPreviousMonths: true
		},

		init: function()
		{
			var minDate = this.options.minDate,
				maxDate = this.options.maxDate;
			if (minDate)
			{
				var minTime = Date.parse(minDate.replace(/-/g, '/'));
				minDate = new Date(minTime);
			}
			if (maxDate)
			{
				var maxTime = Date.parse(maxDate.replace(/-/g, '/'));
				maxDate = new Date(maxTime);
			}

			var self = this,
				$target = this.$target,
				initialValue = $target.val(),
				config = {
					onSelect: function()
					{
						var pad = function(number)
						{
							if (number < 10) { return '0' + number; }
							return number;
						};
						var date = this._d,
							day = String(date.getDate()),
							month = String(date.getMonth() + 1),
							year = String(date.getFullYear());

						self.$target.val(year + '-' + pad(month) + '-' + pad(day));
					},
					onOpen: function()
					{
						if ($target.prop('readonly'))
						{
							this.hide();
						}
					},
					showTime: false,
					firstDay: this.options.weekStart,
					minDate: minDate,
					maxDate: maxDate,
					disableWeekends: this.options.disableWeekends,
					yearRange: this.options.yearRange,
					showWeekNumber: this.options.showWeekNumber,
					showDaysInNextAndPreviousMonths: this.options.showDaysInNextAndPreviousMonths,
					i18n: {
						previousMonth : '',
						nextMonth     : '',
						weekdays      : [0, 1, 2, 3, 4, 5, 6].map(function(day){ return XF.phrase('day' + day) }),
						weekdaysShort : [0, 1, 2, 3, 4, 5, 6].map(function(day){ return XF.phrase('dayShort' + day) }),
						months        : [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11].map(function(month){ return XF.phrase('month' + month) })
					},
					isRTL: XF.isRtl()
				};

			if (initialValue)
			{
				// Pikaday uses Date.parse() internally which parses yyyy-mm-dd unexpectedly when in UTC-X timezones.
				// This works around that issue.
				var match = initialValue.match(/^(\d{4})-(\d\d?)-(\d\d?)$/);
				if (match)
				{
					config.defaultDate = new Date(parseInt(match[1], 10), parseInt(match[2], 10) - 1, parseInt(match[3]));
					config.setDefaultDate = true;
				}
			}

			this.$target.pikaday(config);
			this.$target.val(initialValue);
		}
	});

	// ################################## DESC LOADER HANDLER ###########################################

	XF.DescLoader = XF.Element.newHandler({
		options: {
			descUrl: null
		},

		$container: null,
		loading: false,

		init: function()
		{
			if (!this.options.descUrl)
			{
				console.error('Element must have a data-desc-url value');
				return;
			}

			var $container = this.$target.parent().find('.js-descTarget');
			if (!$container.length)
			{
				console.error('Target element must have a .js-descTarget sibling');
				return;
			}
			this.$container = $container;

			this.$target.on('change', $.proxy(this, 'change'));
		},

		change: function()
		{
			var value = this.$target.val();

			if (!value)
			{
				this.$container.xfFadeUp(XF.config.speed.fast);
				return;
			}

			if (this.loading)
			{
				return;
			}

			var self = this;

			XF.ajax('post', this.options.descUrl, { id: value }, $.proxy(this, 'onLoad'))
			.always(function() { self.loading = false; });
		},

		onLoad: function(data)
		{
			var $container = this.$container;

			if (data.description)
			{
				this.$container.xfFadeUp(XF.config.speed.fast, function()
				{
					$container.html(data.description);
					$container.xfFadeDown(XF.config.speed.normal);
				});
			}
			else
			{
				$container.xfFadeUp(XF.config.speed.fast);
			}
		}
	});

	// ################################## CONTROL DISABLER HANDLER ###########################################

	XF.Disabler = XF.Element.newHandler({
		options: {
			container: '< li | ul, ol, dl',
			controls: 'input, select, textarea, button, .js-attachmentUpload',
			hide: false,
			optional: false,
			invert: false // if true, system will disable on checked
		},

		$container: null,

		init: function()
		{
			this.$container = XF.findRelativeIf(this.options.container, this.$target);

			if (!this.$container.length)
			{
				if (!this.options.optional)
				{
					console.error('Could not find the disabler control container');
				}
			}

			var $input = this.$target,
				$form = $input.closest('form');
			if ($form.length)
			{
				$form.on('reset', $.proxy(this, 'formReset'));
			}

			if ($input.is(':radio'))
			{
				var $context = $form,
					name = $input.attr('name');
				if (!$form.length)
				{
					$context = $(document.body);
				}

				// radios only fire events for the element we click normally, so we need to know
				// when we move away from the value by firing every radio's handler for every click
				$context.on('click', 'input:radio[name="' + name + '"]', $.proxy(this, 'click'));
			}
			else
			{
				$input.click($.proxy(this, 'click'));
			}

			// this ensures that nested disablers are disabled properly
			$input.on('control:enabled control:disabled', $.proxy(this, 'recalculateAfter'));

			this.recalculate(true);
		},

		click: function(e, options)
		{
			var noSelect = (options && options.triggered);
			this.recalculateAfter(false, noSelect);
		},

		formReset: function(e)
		{
			this.recalculateAfter(false, true);
		},

		recalculateAfter: function(init, noSelect)
		{
			var t = this;
			setTimeout(function()
			{
				t.recalculate(init, noSelect);
			}, 0);
		},

		recalculate: function(init, noSelect)
		{
			var $container = this.$container,
				$input = this.$target,
				$controls = $container.find(this.options.controls).not($input),
				speed = init ? 0 : XF.config.speed.fast,
				enable = $input.is(':enabled') && (($input.is(':checked') && !this.options.invert) || (this.options.invert && !$input.is(':checked'))),
				select = function()
				{
					if (noSelect)
					{
						return;
					}

					$container.find('input:not([type=hidden], [type=file]), textarea, select, button').not($input)
						.first().autofocus();
				};

			if (enable)
			{
				$container
					.prop('disabled', false)
					.removeClass('is-disabled');

				$controls
					.prop('disabled', false)
					.removeClass('is-disabled')
					.each(function(i, ctrl)
					{
						var $ctrl = $(ctrl);

						if ($ctrl.is('select.is-readonly'))
						{
							// readonly has to be implemented through disabling so we can't undisable this
							$ctrl.prop('disabled', true);
						}
					})
					.trigger('control:enabled');

				if (this.options.hide)
				{
					if (init)
					{
						$container.show();
					}
					else
					{
						var cb = function()
						{
							XF.layoutChange();
							select();
						};

						$container.slideDown(speed, cb);
					}
					XF.layoutChange();
				}
				else if (!init)
				{
					select();
				}
			}
			else
			{
				if (this.options.hide)
				{
					if (init)
					{
						$container.hide();
					}
					else
					{
						$container.slideUp(speed, XF.layoutChange);
					}
					XF.layoutChange();
				}

				$container
					.prop('disabled', true)
					.addClass('is-disabled');

				$controls
					.prop('disabled', true)
					.addClass('is-disabled')
					.trigger('control:disabled')
					.each(function(i, ctrl)
					{
						var $ctrl = $(ctrl),
							disabledVal = $ctrl.data('disabled');

						if (disabledVal !== null && typeof(disabledVal) != 'undefined')
						{
							$ctrl.val(disabledVal);
						}
					});
			}
		}
	});

	// ################################## FIELD ADDER ###########################################

	XF.FieldAdder = XF.Element.newHandler({

		options: {
			incrementFormat: null,
			remaining: -1
		},

		$clone: null,
		created: false,

		init: function()
		{
			// Clear the cached values of any child elements (except checkboxes)
			this.$target.find('input:not(:checkbox), select, textarea').val('');

			this.$clone = this.$target.clone();

			var self = this;
			this.$target.on('keypress change', function(e)
			{
				if ($(e.target).prop('readonly'))
				{
					return;
				}

				self.$target.off(e);
				self.create();
			});
		},

		create: function()
		{
			if (this.created)
			{
				return;
			}

			this.created = true;

			if (this.options.remaining == 0)
			{
				return;
			}

			var incrementFormat = this.options.incrementFormat;
			if (this.options.incrementFormat)
			{
				var incrementRegex = new RegExp('^' + XF.regexQuote(incrementFormat).replace('\\{counter\\}', '(\\d+)'));

				this.$clone.find('input, select, textarea').each(function()
				{
					var $this = $(this),
						name = $this.attr('name');

					name = name.replace(incrementRegex, function(prefix, counter)
					{
						return incrementFormat.replace('{counter}', parseInt(counter, 10) + 1);
					});

					$this.attr('name', name);
				});
			}

			if (this.options.remaining > 0)
			{
				this.$clone.attr('data-remaining', this.options.remaining - 1);
			}

			this.$clone.insertAfter(this.$target);

			XF.activate(this.$clone);
			XF.layoutChange();
		}
	});

	// ################################## FORM SUBMIT ROWS ###########################################

	XF.FormSubmitRow = XF.Element.newHandler({
		options: {
			container: '.block-container',
			fixedChild: '.formSubmitRow-main',
			stickyClass: 'is-sticky',
			topOffset: 100,
			minWindowHeight: 281
		},

		$container: null,
		$fixedParent: null,
		$fixEl: null,
		fixElHeight: 0,
		winHeight: 0,
		containerTop: 0,
		containerBorderLeftWidth: 0,
		topOffset: 0,
		elBottom: 0,
		state: 'normal',
		windowTooSmall: false,

		init: function()
		{
			if (!XF.config.enableFormSubmitSticky)
			{
				return;
			}

			var $target = this.$target,
				$container = $target.closest(this.options.container);
			if (!$container.length)
			{
				console.error('Cannot float submit row, no container');
				return;
			}

			this.$container = $container;

			this.topOffset = this.options.topOffset;
			this.$fixEl = $target.find(this.options.fixedChild);

			$(window).on('scroll', $.proxy(this, 'onScroll'))
				.on('resize', $.proxy(this, 'recalcAndUpdate'));

			var $fixedParent = XF.getFixedOffsetParent($target);
			if (!$fixedParent.is('html'))
			{
				this.$fixedParent = $fixedParent;
				$fixedParent.on('scroll', $.proxy(this, 'onScroll'));
			}

			$(document.body).on('xf:layout', $.proxy(this, 'recalcAndUpdate'));

			if (!$target.height())
			{
				setTimeout($.proxy(this, 'recalcAndUpdate'), 250);
			}

			this.recalcAndUpdate();
		},

		recalc: function()
		{
			var $target = this.$target;

			this.winHeight = $(window).height();
			this.elBottom = this.getTargetTop() + $target.height();
			this.fixElHeight = this.$fixEl.height();
			this.containerTop = XF.getFixedOffset(this.$container).top;
			this.containerBorderLeftWidth = parseInt(this.$container.css('border-left-width'), 10);
		},

		recalcAndUpdate: function()
		{
			this.state = 'normal'; // need to force CSS updates
			this.resetTarget();
			this.recalc();
			this.update();
		},

		getTargetTop: function()
		{
			var top = this.$target.offset().top;

			if (this.$fixedParent)
			{
				return top - this.$fixedParent.offset().top;
			}
			else
			{
				return top;
			}
		},

		getScrollTop: function()
		{
			if (this.$fixedParent)
			{
				return this.$fixedParent.scrollTop();
			}
			else
			{
				return $(window).scrollTop();
			}
		},

		update: function()
		{
			// in iOS and Android, scrolling may conditionally show/hide UI elements without triggering a
			// resize event. window.innerHeight represents the size of the viewport that shows the page.
			var winHeight = (XF.browser.ios || XF.browser.android) ? window.innerHeight : this.winHeight;

			if (winHeight < this.options.minWindowHeight)
			{
				if (this.state != 'normal')
				{
					this.resetTarget();
					this.state = 'normal';
				}
				return;
			}

			var containerOffset;

			var screenBottom = this.getScrollTop() + winHeight;
			if (screenBottom >= this.elBottom)
			{
				// screen is past the end of the element, natural position
				if (this.state != 'normal')
				{
					this.resetTarget();
					this.state = 'normal';
				}
				return;
			}

			var absoluteCutOff = this.containerTop + this.topOffset + this.fixElHeight;

			if (screenBottom <= absoluteCutOff)
			{
				if (absoluteCutOff >= this.elBottom)
				{
					return;
				}

				// screen is above container
				if (this.state != 'absolute')
				{
					containerOffset = this.$container.offset();

					var $offsetParent;
					if (this.state == 'stuck')
					{
						// when fixed, the offset parent is the HTML element
						$offsetParent = this.$fixEl.parent();
						if ($offsetParent.css('position') == 'static')
						{
							$offsetParent = $offsetParent.offsetParent();
						}
					}
					else
					{
						$offsetParent = this.$fixEl.offsetParent();
					}
					var offsetParentOffset = $offsetParent.offset();

					this.$fixEl.css({
						position: 'absolute',
						top: containerOffset.top - offsetParentOffset.top + this.topOffset,
						right: 'auto',
						bottom: 'auto',
						left: containerOffset.left - offsetParentOffset.left + this.containerBorderLeftWidth,
						width: this.$container.width()
					});
					this.setTargetSticky(true);
					this.state = 'absolute';
				}

				return;
			}

			// screen ends within the container
			if (this.state != 'stuck')
			{
				containerOffset = this.$container.offset();

				this.$fixEl.css({
					position: '',
					top: '',
					right: '',
					bottom: '',
					left: containerOffset.left + this.containerBorderLeftWidth,
					width: this.$container.width()
				});
				this.setTargetSticky(true);
				this.state = 'stuck';
			}
		},

		resetTarget: function()
		{
			this.$fixEl.css({
				position: '',
				top: '',
				right: '',
				bottom: '',
				left: '',
				width: ''
			});
			this.setTargetSticky(false);
		},

		setTargetSticky: function(sticky)
		{
			var $target = this.$target;

			$target.toggleClass('is-sticky', sticky).css('height', this.$fixEl.height());
		},

		onScroll: function()
		{
			this.update();
		}
	});

	// ################################## GUEST USERNAME HANDLER ###########################################

	XF.GuestUsername = XF.Element.newHandler({

		init: function()
		{
			var $input = this.$target;
			$input.val(XF.LocalStorage.get('guestUsername'));
			$input.on('keyup', $.proxy(this, 'change'));
		},

		change: function()
		{
			var $input = this.$target;
			if ($input.val().length)
			{
				XF.LocalStorage.set('guestUsername', $input.val(), true);
			}
			else
			{
				XF.LocalStorage.remove('guestUsername');
			}
		}
	});

	// ################################## MIN LENGTH ###########################################

	XF.MinLength = XF.Element.newHandler({
		options: {
			minLength: 0,
			allowEmpty: false,
			disableSubmit: true,
			toggleTarget: null
		},

		met: null,
		$form: null,
		$toggleTarget: null,

		init: function()
		{
			var self = this;

			this.$form = this.$target.closest('form');

			if (this.options.toggleTarget)
			{
				this.$toggleTarget = XF.findRelativeIf(this.options.toggleTarget, this.$target);
			}
			else
			{
				this.$toggleTarget = $([]);
			}

			this.$target.on('change keypress keydown paste', function()
			{
				setTimeout($.proxy(self, 'checkLimits'), 0);
			});

			this.checkLimits();
		},

		checkLimits: function()
		{
			var length = $.trim(this.$target.val()).length,
				options = this.options,
				met = (length >= options.minLength || (length == 0 && options.allowEmpty));

			if (met === this.met)
			{
				return;
			}
			this.met = met;

			if (met)
			{
				if (options.disableSubmit)
				{
					this.$form.find(':submit').prop('disabled', false).removeClass('is-disabled');
				}
				this.$toggleTarget.hide();
			}
			else
			{
				if (options.disableSubmit)
				{
					this.$form.find(':submit').prop('disabled', true).addClass('is-disabled');
				}
				this.$toggleTarget.show();
			}
		}
	});

	// ################################## TEXTAREA HANDLER ###########################################

	XF.TextAreaHandler = XF.Element.newHandler({
		options: {
			autoSize: true,
			keySubmit: true,
			singleLine: false
		},

		initialized: false,

		init: function()
		{
			if (this.options.autoSize)
			{
				if (this.$target[0].scrollHeight)
				{
					this.setupAutoSize();
				}
				else
				{
					this.$target.one('focus', $.proxy(this, 'setupDelayed'));
					this.$target.onWithin('toggle:shown overlay:shown tab:shown quick-edit:shown', $.proxy(this, 'setupDelayed'));
				}

				this.$target.on('autosize', $.proxy(this, 'update'));
			}

			if (this.options.keySubmit || this.options.singleLine)
			{
				this.$target.on('keydown', $.proxy(this, 'keySubmit'));
			}
		},

		setupAutoSize: function()
		{
			if (this.initialized)
			{
				return;
			}
			this.initialized = true;

			autosize(this.$target);

			this.$target.on('autosize:resized', function()
			{
				XF.layoutChange();
			});
		},

		setupDelayed: function()
		{
			if (this.initialized)
			{
				this.update();
			}
			else
			{
				var t = this,
					init = function()
					{
						t.setupAutoSize();
						XF.layoutChange();
					};

				if (this.$target[0].scrollHeight)
				{
					init();
				}
				else
				{
					setTimeout(init, 100);
				}
			}
		},

		update: function()
		{
			if (this.initialized)
			{
				autosize.update(this.$target[0]);
			}
			else
			{
				this.setupDelayed();
			}
		},

		keySubmit: function(e)
		{
			if (e.which == 13)
			{
				if (this.options.singleLine || (this.options.keySubmit && (XF.isMac() ? e.metaKey : e.ctrlKey)))
				{
					e.preventDefault();
					this.$target.closest('form').submit();
					return false;
				}
			}
		}
	});

	// ################################## PERMISSION MATRIX UI HANDLER ###########################################

	XF.PermissionMatrix = XF.Element.newHandler({
		options: {
			inputSelector: 'input[type="radio"]',
			parentSelector: 'dl.formRow',
			classPrefix: 'formRow--permissionType-',
			permissionType: 'user'
		},

		values: ['allow', 'unset', 'deny', 'content_allow', 'reset'],
		currentClass: null,

		init: function()
		{
			// identify parent row, to which classes will be attached
			this.$parentRow = this.$target.closest(this.options.parentSelector);

			this.$target.find(this.options.inputSelector).on('click', $.proxy(this, 'update'));
			this.update();
		},

		update: function()
		{
			// remove any existing permission classes
			if (this.currentClass)
			{
				this.$parentRow.removeClass(this.currentClass);
			}

			// get the currently selected value
			var value = this.$target.find(this.options.inputSelector + ':checked').val();

			// if it's a valid value, assign the class to the target
			if ($.inArray(value, this.values) > -1)
			{
				this.currentClass = this.options.classPrefix + this.options.permissionType + '-' + value;
				this.$parentRow.addClass(this.currentClass);
			}
		}
	});

	// ################################## MULTI-INPUT CHECKER ###########################################
	// TODO: this can be more generic in due course, see it in use in the permission editors

	XF.MultiCheck = XF.Click.newHandler({
		eventNameSpace: 'XFMultiCheck',
		options: {
			target: null, // selector for all inputs
			values: 'allow,unset,deny' // possible input values to cycle
		},

		$target: null,
		values: null,
		currentValue: null,

		init: function ()
		{
			this.$target = $(this.options.target);

			this.values = this.options.values.split(',');

			// if the first radio is checked, start the cycle with the second, otherwise start with the first
			var firstChecked = this.$target.filter(':checked').first().val(),
				firstIndex = $.inArray(firstChecked, this.values);
			this.currentValue = (firstIndex > 0 ? this.values[-1] : this.values[0]);
		},

		click: function(e)
		{
			var currentValue = this.values[$.inArray(this.currentValue, this.values) + 1];

			if (currentValue === undefined)
			{
				currentValue = this.values[0];
			}

			this.$target.each(function()
			{
				if ($(this).val() == currentValue)
				{
					$(this).prop('checked', true).trigger('click');
				}
			});

			this.currentValue = currentValue;
		}
	});

	// ################################## NUMBER BOX HANDLER ###########################################

	XF.NumberBox = XF.Element.newHandler({
		options: {
			textInput: '.js-numberBoxTextInput'
		},

		$textInput: null,

		holdTimeout: null,
		holdInterval: null,

		init: function()
		{
			var $target = this.$target,
				$textInput = $target.find(this.options.textInput);

			if (!$textInput.length)
			{
				console.error('Cannot initialize, no text input.');
				return;
			}

			$target.addClass('inputGroup--joined');

			var $button = $('<button />')
				.attr('type', 'button')
				.attr('tabindex', '-1')
				.addClass('inputGroup-text')
				.addClass('inputNumber-button')
				.on('focus', $.proxy(this, 'buttonFocus'))
				.on('click', $.proxy(this, 'buttonClick'))
				.on('mousedown touchstart', $.proxy(this, 'buttonMouseDown'))
				.on('mouseleave mouseup touchend', $.proxy(this, 'buttonMouseUp'))
				.on('touchend', function(e)
				{
					e.preventDefault();

					// this prevents double tap zoom on touch devices
					$(this).click();
				}),

				$up = $button
					.clone(true)
					.data('dir', 'up')
					.addClass('inputNumber-button--up')
					.insertAfter($textInput),

				$down = $button
					.clone(true)
					.data('dir', 'down')
					.addClass('inputNumber-button--down')
					.insertAfter($up);

			if ($textInput.prop('disabled'))
			{
				$up.addClass('is-disabled').prop('disabled', true);
				$down.addClass('is-disabled').prop('disabled', true);
			}

			if ($textInput.hasClass('input--numberNarrow'))
			{
				$up.addClass('inputNumber-button--smaller');
				$down.addClass('inputNumber-button--smaller');
			}

			this.$textInput = $textInput;

			if (!this.supportsStepFunctions())
			{
				$textInput.on('keydown', $.proxy(this, 'stepFallback'));
			}
		},

		buttonFocus: function(e)
		{
			return false;
		},

		buttonClick: function(e)
		{
			this.step($(e.target).data('dir'));
		},

		step: function(dir)
		{
			var $textInput = this.$textInput,
				fnName = 'step' + dir.charAt(0).toUpperCase() + dir.slice(1);

			if ($textInput.prop('readonly'))
			{
				return;
			}

			if (this.supportsStepFunctions())
			{
				$textInput[0][fnName]();
			}
			else
			{
				this.stepFallback(dir);
			}
		},

		stepFallback: function(dir)
		{
			if (this.$textInput.prop('readonly'))
			{
				return;
			}

			if (typeof dir === 'object' && dir.keyCode)
			{
				var e = dir;

				switch (e.keyCode)
				{
					case 38:

						dir = 'up';
						e.preventDefault();
						break;

					case 40:

						dir = 'down';
						e.preventDefault();
						break;

					default:
						return;
				}
			}

			var $textInput = this.$textInput,
				originalVal = $textInput.val(),
				multiplier = (dir === 'down' ? -1 : 1),
				min = $textInput.attr('min') || null,
				max = $textInput.attr('max') || null,
				step = $textInput.attr('step') || 1,
				stepAny = (step == 'any'),
				value;

			if (stepAny)
			{
				step = 1;
			}
			step = parseFloat(step);

			value = parseFloat(originalVal) + (step * multiplier);

			// force some rounding if appropriate to the same number of places as the step
			if (Math.round(value) !== value)
			{
				var decs = 0;
				if (stepAny)
				{
					decs = originalVal.split('.')[1].length || 0;
				}
				else if (Math.floor(step) !== step)
				{
					decs = step.toString().split('.')[1].length || 0;
				}
				value = value.toFixed(decs);
			}

			if (min !== null && value < min)
			{
				value = min;
			}
			if (max !== null && value > max)
			{
				value = max;
			}

			$textInput.val(value);
		},

		buttonMouseDown: function(e)
		{
			this.buttonMouseUp(e);

			this.holdTimeout = setTimeout(
				$.proxy(function()
				{
					this.holdInterval = setInterval(
						$.proxy(function()
						{
							this.step($(e.target).data('dir'));
						}, this
					), 75);
				}, this
			), 500);
		},

		buttonMouseUp: function(e)
		{
			clearTimeout(this.holdTimeout);
			clearInterval(this.holdInterval);
		},

		supportsStepFunctions: function()
		{
			var $textInputClone = this.$textInput.clone();

			if ($textInputClone.prop('disabled') || $textInputClone.prop('readonly'))
			{
				return;
			}

			// Check we have stepUp or stepDown support else fallback
			if (typeof $textInputClone[0]['stepUp'] === 'function')
			{
				try
				{
					$textInputClone[0]['stepUp']();
				}
				catch (e)
				{
					// browser should support it but doesn't
					return false;
				}
			}
			else
			{
				return false;
			}

			return true;
		}
	});

	// ################################## CHECKBOXES DISABLE SELECT OPTIONS ###########################################
	// Using this, checkbox values correspond to <option> values in the <select> selected by this.options.select,
	// and if the checkbox is not checked, the corresponding <option> will be disabled

	XF.CheckboxSelectDisabler = XF.Element.newHandler({
		options: {
			select: null
		},

		$select: null,
		$checkboxes: null,

		init: function()
		{
			this.$select = XF.findRelativeIf(this.options.select, this.$target);
			if (!this.$select || !this.$select.length)
			{
				console.warn('No select element found using %s', this.options.select);
				return;
			}

			this.$checkboxes = this.$target.find(':checkbox').on('click', $.proxy(this, 'update'));

			this.update();
		},

		update: function()
		{
			var $select = this.$select;

			this.$checkboxes.each(function()
			{
				var $option = $select.find('option[value=' + this.value + ']').prop('disabled', !this.checked);
				if (!this.checked && $option.is(':selected'))
				{
					$select.find('option:enabled').first().prop('selected', true);
				}
			});
		}
	});

	/**
	 * Focus the next focusable element in the document after el
	 *
	 * @param el
	 */
	XF.focusNext = function(el)
	{
		if (!el instanceof $)
		{
			el = $(el);
		}

		var $focusable = $('a, button, :input, [tabindex]'),
			focusIndex = $focusable.index(el) + 1;

		$focusable.eq(focusIndex >= $focusable.length ? 0 : focusIndex).focus();
	}

	// ################################## --- ###########################################

	XF.Click.register('submit', 'XF.SubmitClick');
	XF.Click.register('multi-check', 'XF.MultiCheck');

	XF.Element.register('ajax-submit', 'XF.AjaxSubmit');
	XF.Element.register('auto-complete', 'XF.AutoComplete');
	XF.Element.register('user-mentioner', 'XF.UserMentioner');
	XF.Element.register('auto-submit', 'XF.AutoSubmit');
	XF.Element.register('changed-field-notifier', 'XF.ChangedFieldNotifier');
	XF.Element.register('check-all', 'XF.CheckAll');
	XF.Element.register('date-input', 'XF.DateInput');
	XF.Element.register('desc-loader', 'XF.DescLoader');
	XF.Element.register('disabler', 'XF.Disabler');
	XF.Element.register('field-adder', 'XF.FieldAdder');
	XF.Element.register('form-submit-row', 'XF.FormSubmitRow');
	XF.Element.register('guest-username', 'XF.GuestUsername');
	XF.Element.register('min-length', 'XF.MinLength');
	XF.Element.register('textarea-handler', 'XF.TextAreaHandler');
	XF.Element.register('permission-matrix', 'XF.PermissionMatrix');
	XF.Element.register('number-box', 'XF.NumberBox');
	XF.Element.register('checkbox-select-disabler', 'XF.CheckboxSelectDisabler');

}
(jQuery, window, document);