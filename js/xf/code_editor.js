!function($, window, document, _undefined)
{
	"use strict";

	XF.CodeEditor = XF.Element.newHandler({
		options: {
			indentUnit: 4,
			indentWithTabs: true,
			lineNumbers: true,
			lineWrapping: false,
			autoCloseBrackets: true,
			mode: null,
			config: null,
			submitSelector: null,
			scrollbarStyle: 'simple'
		},

		editor: null,
		$wrapper: null,

		init: function()
		{
			// this is checking the parent node because we have css that will force hide this textarea
			if (this.$target[0].parentNode.scrollHeight)
			{
				this.initEditor();
			}
			else
			{
				this.$target.oneWithin('toggle:shown overlay:showing tab:shown', $.proxy(this, 'initEditor'));
			}
		},

		initEditor: function()
		{
			var $textarea = this.$target,
				config = {};

			if ($textarea.data('cmInitialized'))
			{
				return;
			}

			try
			{
				config = $.parseJSON(this.options.config);
			}
			catch (e)
			{
				config = this.options.config;
			}

			this.editor = CodeMirror.fromTextArea($textarea.get(0), $.extend({
				indentUnit: this.options.indentUnit,
				indentWithTabs: this.options.indentWithTabs,
				lineNumbers: this.options.lineNumbers,
				lineWrapping: this.options.lineWrapping,
				autoCloseBrackets: this.options.autoCloseBrackets,
				readOnly: $textarea.prop('readonly'),
				autofocus: $textarea.prop('autofocus'),
				scrollbarStyle: this.options.scrollbarStyle
			}, config));

			this.$wrapper = $(this.editor.getWrapperElement());

			// Sync the textarea classes to CodeMirror
			this.$wrapper.addClass($textarea.attr('class')).attr('dir', 'ltr');
			$textarea.attr('class', '');
			this.editor.refresh();

			XF.layoutChange();

			this.editor.on('keydown', $.proxy(this, 'keydown'));

			$textarea.trigger('code-editor:init', this.editor);

			$textarea.data('cmInitialized', true);
		},

		keydown: function(editor, e)
		{
			// macOS: Cmd + Ctrl + F | other: F11
			if ((XF.isMac() && e.metaKey && e.ctrlKey && e.which == 70)
				|| (!XF.isMac() && e.which == 122)
			)
			{
				e.preventDefault();

				editor.setOption("fullScreen", !editor.getOption("fullScreen"));
			}

			// Escape (exit full screen)
			if (e.which == 27)
			{
				e.stopPropagation();

				if (editor.getOption("fullScreen"))
				{
					editor.setOption("fullScreen", false);
				}
			}

			// (ctrl|meta)+(s|enter) submits the associated form
			if ((e.which == 83 || e.which == 13) && (XF.isMac() ? e.metaKey : e.ctrlKey))
			{
				e.preventDefault();

				var $textarea = $(editor.getTextArea()),
					$form = $textarea.closest('form'),
					self = this;
				this.editor.save();

				setTimeout(function()
				{
					var selector = self.options.submitSelector,
						$submit = $form.find(selector);

					if (selector && $submit.length)
					{
						$form.find(selector).click();
					}
					else
					{
						$form.submit();
					}
				}, 200);
			}
		}
	});

	XF.CodeEditorSwitcherContainer = XF.Element.newHandler({
		options: {
			switcher: '.js-codeEditorSwitcher',
			templateSuffixMode: 0
		},

		$switcher: null,

		editor: null,
		loading: false,

		init: function()
		{
			this.$target.on('code-editor:init', $.proxy(this, 'initEditor'));
		},

		initEditor: function(e, editor)
		{
			var $switcher = this.$target.find(this.options.switcher);
			if (!$switcher.length)
			{
				console.warn('Switcher container has no switcher: %o', this.$target);
				return;
			}
			this.$switcher = $switcher;

			if ($switcher.is('select, :radio'))
			{
				$switcher.on('change', $.proxy(this, 'change'));
			}
			else if ($switcher.is('input:not(:checkbox :radio)'))
			{
				$switcher.on('blur', $.proxy(this, 'blurInput'));

				// Trigger after a short delay to get the existing template's mode and apply
				setTimeout(function()
				{
					$switcher.trigger('blur');
				}, 100);
			}
			else
			{
				console.warn('Switcher only works for text inputs, radios and selects.');
				return;
			}

			this.editor = editor;
		},

		change: function(e)
		{
			var language = this.$switcher.find(":selected").val();

			this.switchLanguage(language);
		},

		blurInput: function(e)
		{
			var language = this.$switcher.val();

			if (this.options.templateSuffixMode)
			{
				language = language.toLowerCase();

				if (language.indexOf('.less') > 0)
				{
					language = 'less';
				}
				else if (language.indexOf('.css') > 0)
				{
					language = 'css';
				}
				else
				{
					language = 'html';
				}
			}

			this.switchLanguage(language);
		},

		switchLanguage: function(language)
		{
			if (this.loading)
			{
				return;
			}

			var self = this,
				editor = this.editor,
				$textarea = $(editor.getTextArea());

			editor.save();

			if ($textarea.data('lang') == language)
			{
				return;
			}

			setTimeout(function()
			{
				var url;
				if ($('html').data('app') == 'public')
				{
					url = 'index.php?misc/code-editor-mode-loader';
				}
				else
				{
					url = 'admin.php?templates/code-editor-mode-loader';
				}

				XF.ajax('post', XF.canonicalizeUrl(url), {
					language: language
				}, $.proxy(self, 'handleAjax')).always(function() { self.loading = false; });
			}, 200);
		},

		handleAjax: function(data)
		{
			if (data.errors || data.exception)
			{
				return;
			}

			if (data.redirect)
			{
				XF.redirect(data.redirect);
			}

			var editor = this.editor,
				$textarea = $(editor.getTextArea());

			XF.setupHtmlInsert(data.html, function($html, container)
			{
				var mode = '';

				if (data.mime)
				{
					mode = data.mime;
				}
				else if (data.mode)
				{
					mode = data.mode;
				}

				editor.setOption('mode', mode);
				$textarea.data('lang', data.language);
				$textarea.data('config', JSON.stringify(data.config));
				if (data.config)
				{
					$.each(data.config, function(key, value)
					{
						editor.setOption(key, value);
					});
				}
			});
		}
	});

	XF.Element.register('code-editor', 'XF.CodeEditor');
	XF.Element.register('code-editor-switcher-container', 'XF.CodeEditorSwitcherContainer');
}
(jQuery, window, document);