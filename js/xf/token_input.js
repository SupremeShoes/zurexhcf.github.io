!function($, window, document, _undefined)
{
	"use strict";

	// ################################## TOKEN INPUT HANDLER ###########################################

	XF.TokenInput = XF.Element.newHandler({

		options: {
			tokens: [','],
			minLength: 2,
			maxLength: 0,
			maxTokens: 0,
			acUrl: ''
		},

		$container: null,
		$selection: null,
		$hiddenInput: null,

		init: function()
		{
			var $input = this.$target,
				options = this.options,
				$hiddenInput = $input.clone()
					.insertAfter($input)
					.prop('type', 'hidden')
					.removeAttr('data-xf-init')
					.removeAttr('id');

			var config = {
				tags: true,
				width: '100%',
				containerCssClass: 'input',
				language: XF.TokenInput.Phrases,
				minimumInputLength: options.minLength,
				maximumInputLength: options.maxLength,
				maximumSelectionLength: options.maxTokens,
				selectOnClose: true,
				disabled: $input.prop('disabled')
			};

			try
			{
				config.tokenSeparators = $.parseJSON(options.tokens);
			}
			catch(e)
			{
				config.tokenSeparators = options.tokens;
			}

			if (options.acUrl)
			{
				config.ajax = {
					delay: 250,
					data: $.proxy(this, 'prepareAjax'),
					transport: $.proxy(this, 'getAjax'),
					processResults: $.proxy(this, 'processAjax')
				};
				config.escapeMarkup = function(markup) { return markup; };
				config.sorter = $.proxy(this, 'sortResults');
				config.templateResult = $.proxy(this, 'prepareResults');
				config.templateSelection = $.proxy(this, 'prepareSelection');
			}

			$input.remove();

			var $element = $('<select name="tokens_select" multiple="multiple"></select>')
				.insertBefore($hiddenInput)
				.select2(config);

			if ($hiddenInput.val())
			{
				var existingValues = $hiddenInput.val().split(',').map($.trim);
				$.each(existingValues, function(index, value)
				{
					var $option = $('<option selected>').val(value).text(value);
					$option.appendTo($element);
				});
				$element.trigger('change');
			}
			this.$hiddenInput = $hiddenInput;

			var api = $element.data('select2');
			this.$container = api.$container;
			this.$selection = api.$selection;

			api.on('results:message', function(params)
			{
				this.dropdown._resizeDropdown();
				this.dropdown._positionDropdown();
			});

			var autoFocus = $input.attr('autofocus');
			if (autoFocus)
			{
				$element.select2('open');
				api.$selection.addClass('is-focused');
			}

			var $overlay = $element.closest('.overlay-container');
			if ($overlay.length)
			{
				$overlay.on('overlay:hiding', function()
				{
					$element.select2('close');
				});
			}

			api.$container.on('focusin focusout', $.proxy(this, 'inputFocusBlur'));
			$element.on('change', $.proxy(this, 'updateInput'));
		},

		prepareAjax: function(params)
		{
			return {
				q: params.term
			};
		},

		getAjax: function(params, success, failure)
		{
			return XF.ajax('get', this.options.acUrl, params.data, success);
		},

		processAjax: function(data, params)
		{
			if (typeof data != 'object')
			{
				return {};
			}
			return data;
		},

		sortResults: function(results)
		{
			if (results.length <= 1)
			{
				return results;
			}
			// Check if first result is just the current query, and if it is, push it to the end.
			if (results.hasOwnProperty(0))
			{
				// Only results from AJAX will have the 'q' property.
				if (!results[0].hasOwnProperty('q'))
				{
					results.push(results.shift());
				}
			}
			return results;
		},

		prepareResults: function(result)
		{
			result.styledText = result.text;
			if (result.q)
			{
				var filterRegex = new RegExp('(' + XF.regexQuote(result.q) + ')', 'i');
				result.styledText = XF.htmlspecialchars(result.styledText).replace(filterRegex, '<strong>$1</strong>');
			}
			else if (!result.disabled)
			{
				result.styledText = '<strong>' + XF.htmlspecialchars(result.styledText) + '</strong>';
			}

			var html = result.styledText;
			if (result.iconHtml)
			{
				html = result.iconHtml + '&nbsp;' + html;
			}
			return html;
		},

		prepareSelection: function(selection)
		{
			return XF.htmlspecialchars(selection.text);
		},

		inputFocusBlur: function(e)
		{
			switch (e.type)
			{
				case 'focusout':
					this.$selection.removeClass('is-focused');
					break;

				case 'focusin':
				default:
					this.$selection.addClass('is-focused');
					break;
			}
		},

		updateInput: function(e)
		{
			var $select = $(e.target),
				$hiddenInput = this.$hiddenInput;

			if ($select.val())
			{
				$hiddenInput.val($select.val().join(', '));
			}
			else
			{
				$hiddenInput.val('');
			}
		}
	});

	XF.TokenInput.Phrases = {

		errorLoading: function()
		{
			return XF.phrase('s2_error_loading');
		},
		inputTooLong: function(args)
		{
			return XF.phrase('s2_input_too_long', {
				'{count}': (args.input.length - args.maximum)
			});
		},
		inputTooShort: function(args)
		{
			return XF.phrase('s2_input_too_short', {
				'{count}': (args.minimum - args.input.length)
			});
		},
		loadingMore: function()
		{
			return XF.phrase('s2_loading_more');
		},
		maximumSelected: function(args)
		{
			return XF.phrase('s2_maximum_selected', {
				'{count}': args.maximum
			});
		},
		noResults: function()
		{
			return XF.phrase('s2_no_results');
		},
		searching: function()
		{
			return XF.phrase('s2_searching');
		}
	};

	XF.Element.register('token-input', 'XF.TokenInput');
}
(jQuery, window, document);