!function($, window, document, _undefined)
{
	"use strict";

	XF.isEditorEnabled = function()
	{
		return XF.LocalStorage.get('editorDisabled') ? false : true;
	};
	XF.setIsEditorEnabled = function(enabled)
	{
		if (enabled)
		{
			XF.LocalStorage.remove('editorDisabled');
		}
		else
		{
			XF.LocalStorage.set('editorDisabled', '1', true);
		}
	};

	XF.Editor = XF.Element.newHandler({
		options: {
			maxHeight: .70,
			minHeight: 250, // default set in Templater->formEditor() $controlOptions['data-min-height']
			buttonsRemove: '',
			attachmentTarget: true,
			attachmentUploader: '.js-attachmentUpload',
			attachmentContextInput: 'attachment_hash_combined'
		},

		$form: null,
		ed: null,
		mentioner: null,
		uploadUrl: null,

		init: function()
		{
			if (!this.$target.is('textarea'))
			{
				console.error('Editor can only be initialized on a textarea');
				return;
			}

			this.$target.trigger('editor:start', [this]);

			this.$form = this.$target.closest('form');
			if (!this.$form.length)
			{
				this.$form = null;
			}

			if (this.options.attachmentTarget)
			{
				var $attachManager = this.$target.closest('[data-xf-init~=attachment-manager]'),
					$uploader = $attachManager.find(this.options.attachmentUploader);
				this.uploadUrl = $uploader.attr('href');
			}

			var t = this;

			this.$target
				.css('visibility', '')
				.on('froalaEditor.initialized', function (m, ed)
				{
					t.ed = ed;
					t.editorInit();
				})
				.froalaEditor(this.getEditorConfig());
		},

		getEditorConfig: function()
		{
			var fontSize = ['9', '10', '12', '15', '18', '22', '26'];
			var fontFamily = {
				"arial": 'Arial',
				"'book antiqua'": 'Book Antiqua',
				"'courier new'": 'Courier New',
				"georgia": 'Georgia',
				'tahoma': 'Tahoma',
				"'times new roman'": 'Times New Roman',
				"'trebuchet ms'": 'Trebuchet MS',
				"verdana": 'Verdana'
			};

			var heightLimits = this.getHeightLimits();

			// make the sticky toolbar underlap the sticky header a teeny bit
			var toolbarStickyOffset = Math.max(parseInt(window.getComputedStyle($('.p-header')[0], ':after').getPropertyValue('content').replace(/\"/g, ''), 10) -2, 0);

			var config = {
				direction: $.FE.LANGUAGE.xf.direction,
				editorClass: 'bbWrapper', // since this is a BB code editor, we want our output to normalize like BB code
				fileAllowedTypes: [],
				fileMaxSize: 4 * 1024 * 1024 * 1024, // 4G
				fileUploadParam: 'upload',
				fileUploadURL: false,
				fontFamily: fontFamily,
				fontSize: fontSize,
				heightMin: heightLimits[0],
				heightMax: heightLimits[1],
				htmlAllowedTags: ['a', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'cite', 'code', 'dfn', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'mark', 'ol', 'p', 'pre', 's', 'script', 'style', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul', 'var', 'wbr'],
				key: 'AODOd2HLEBFZOTGHW==',
				htmlAllowComments: false,
				imageAllowedTypes: [],
				imageCORSProxy: null,
				imageDefaultDisplay: 'inline',
				imageDefaultWidth: 'auto',
				imageEditButtons: ['imageReplace', 'imageRemove', '|', 'imageLink', 'linkOpen', 'linkEdit', 'linkRemove'],
				imageManagerLoadURL: false,
				imageMaxSize: 4 * 1024 * 1024 * 1024, // 4G
				imagePaste: false,
				imageResize: false,
				imageUploadParam: 'upload',
				imageUploadRemoteUrls: false,
				imageUploadURL: false,
				language: 'xf',
				linkAlwaysBlank: true,
				linkEditButtons: ['linkOpen', 'linkEdit', 'linkRemove'],
				linkInsertButtons: ['linkBack'],
				placeholderText: '',
				toolbarSticky: false,
				toolbarStickyOffset: toolbarStickyOffset,
				videoUploadURL: false,
				xfBbCodeAttachmentContextInput: this.options.attachmentContextInput
			};
			$.FE.DT = true;

			if (this.uploadUrl)
			{
				var uploadParams = {
					_xfToken: XF.config.csrf,
					_xfResponseType: 'json',
					_xfWithData: 1
				};

				config.fileAllowedTypes = ['*'];
				config.fileUploadParams = uploadParams;
				config.fileUploadURL = this.uploadUrl;
				config.imageAllowedTypes = ['jpeg', 'jpg', 'png', 'gif'];
				config.imageUploadParams = uploadParams;
				config.imageUploadURL = this.uploadUrl;
				config.imagePaste = true;
			}
			else
			{
				config.imageInsertButtons = ['imageByURL'];
			}

			var buttons = this.getButtonConfig();

			config = $.extend({}, config, buttons);

			this.$target.trigger('editor:config', [config, this]);

			return config;
		},

		getButtonConfig: function()
		{
			var buttons =
				'clearFormatting,|,bold,italic,underline,strikeThrough,'
				+ '|,color,fontFamily,fontSize,'
				+ '|,insertLink,insertImage,xfSmilie,xfInsert,'
				+ '|,xfCustomPlaceholder,'
				+ '|,align,xfList,'
				+ '|,undo,redo,'
				+ '|,xfDraft,xfBbCode';

			var buttonClass = {
				_basic: ['bold', 'italic', 'underline', 'strikeThrough'],
				_extended: ['color', 'fontFamily', 'fontSize'],
				_link: ['insertLink'],
				_align: ['align'],
				_list: ['formatOL', 'formatUL', 'outdent', 'indent'],
				_indent: ['outdent', 'indent'],
				_smilies: ['xfSmilie'],
				_image: ['insertImage'],
				_media: ['xfMedia'],
				_block: ['xfQuote', 'xfCode', 'xfSpoiler']
			};

			var insertButtons = 'xfMedia,xfQuote,xfSpoiler,xfCode,xfInlineCode',
				listButtons = 'formatOL,formatUL,outdent,indent',
				i;

			var customReplace = XF.editorStart.custom.length ? XF.editorStart.custom.join(',') : '';
			buttons = buttons.replace('xfCustomPlaceholder', customReplace);
			insertButtons = insertButtons.replace('xfCustomPlaceholder', customReplace);

			var cleanUp = function(buttons)
			{
				return buttons
					.replace(/\|,\|(,\|)*/g, '|')
					.replace(/^\|,/, '')
					.replace(/,\|$/, '')
					.replace(/^,+/, '')
					.replace(/,+$/, '');
			};

			var removeButton = function(buttons, removeName)
			{
				if (typeof removeName == 'string' && buttonClass[removeName])
				{
					removeName = buttonClass[removeName];
				}

				if (typeof removeName != 'string')
				{
					removeName = removeName.join('|');
				}

				var r = new RegExp('(^|,)(' + removeName + ')(?=,|$)', 'g');
				return buttons.replace(r, '$1');
			};

			var splitButtons = function(str)
			{
				return str.length ? str.split(',') : [];
			};

			var cleanUpAndSplit = function(str)
			{
				return splitButtons(cleanUp(str));
			};

			if (!this.$form || !this.$form.is('[data-xf-init~=draft]'))
			{
				buttons = removeButton(buttons, 'xfDraft');
			}

			if (this.options.buttonsRemove)
			{
				var remove = this.options.buttonsRemove.split(',');
				for (i = 0; i < remove.length; i++)
				{
					buttons = removeButton(buttons, remove[i]);
					insertButtons = removeButton(insertButtons, remove[i]);
					listButtons = removeButton(listButtons, remove[i]);
				}
			}

			insertButtons = cleanUp(insertButtons);
			listButtons = cleanUp(listButtons);

			var eventData = {
				buttons: buttons,
				xsRemove: ['fontFamily', 'outdent', 'indent', 'strikeThrough', 'xfDraft'],
				insertButtons: insertButtons,
				listButtons: listButtons,
				helpers: {
					removeButton: removeButton,
					cleanUp: cleanUp,
					splitButtons: splitButtons
				}
			};

			this.$target.trigger('editor:buttons', [eventData, this]);
			buttons = eventData.buttons;

			if (!insertButtons.length)
			{
				buttons = removeButton(buttons, 'xfInsert');
			}
			if (!listButtons.length)
			{
				buttons = removeButton(buttons, 'xfList');
			}

			var xsButtons = buttons,
				xsRemove = eventData.xsRemove;

			for (i = 0; i < xsRemove.length; i++)
			{
				xsButtons = removeButton(xsButtons, xsRemove[i]);
			}

			var buttonsList = cleanUpAndSplit(buttons),
				xsButtonsList = cleanUpAndSplit(xsButtons);

			return {
				toolbarButtons: buttonsList,
				toolbarButtonsMD: buttonsList,
				toolbarButtonsSM: buttonsList,
				toolbarButtonsXS: xsButtonsList,
				xfInsertOptions: cleanUpAndSplit(eventData.insertButtons),
				xfListOptions: cleanUpAndSplit(eventData.listButtons)
			};
		},

		editorInit: function()
		{
			var t = this,
				ed = this.ed;

			this.watchEditorHeight();

			if (this.$form)
			{
				this.$form.on('ajax-submit:before draft:beforesync', function()
				{
					ed.$oel.val(t.ed.html.get());
				});
				this.$form.on('draft:complete', function()
				{
					var $draftButton = ed.$tb.find('.fr-command.fr-btn[data-cmd=xfDraft]'),
						$indicator = $draftButton.find('.editorDraftIndicator');

					if (!$indicator.length)
					{
						$indicator = $('<b class="editorDraftIndicator" />').appendTo($draftButton);
					}
					setTimeout(function() { $indicator.addClass('is-active'); }, 50);
					setTimeout(function() { $indicator.removeClass('is-active'); }, 2500);
				});

				ed.events.on('keydown', function(e)
				{
					if (e.which == 13 && (XF.isMac() ? e.metaKey : e.ctrlKey))
					{
						e.preventDefault();
						t.$form.submit();
						return false;
					}
				}, true);
			}

			// hide justify as we don't support it
			ed.$tb.find('[data-cmd=align][data-param1=justify]').closest('li').css('display', 'none');

			// make images be inline automatically
			ed.events.on('image.inserted', function($img)
			{
				$img.removeClass('fr-dib').addClass('fr-dii');
			});

			ed.events.on('image.beforePasteUpload', function(img)
			{
				var placeholderSrc = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
				if (img.src == placeholderSrc)
				{
					return false;
				}
			});

			var isPlainPaste = false;

			ed.events.on('cut copy', function(e)
			{
				var range = ed.selection.ranges(0);
				if (range && range.commonAncestorContainer)
				{
					var container = range.commonAncestorContainer;
					if (container.nodeType == Node.TEXT_NODE)
					{
						container = container.parentNode;
					}

					var $ps = $(container).find('p');

					$(container).find('p').attr('data-xf-p', '1');

					setTimeout(function()
					{
						$ps.removeAttr('data-xf-p');
					}, 0);
				}
			});

			ed.events.on('paste.before', function(e)
			{
				isPlainPaste = false;

				if (e && e.clipboardData && e.clipboardData.getData)
				{
					var types = '',
						clipboard_types = e.clipboardData.types;

					if (ed.helpers.isArray(clipboard_types))
					{
						for (var i = 0 ; i < clipboard_types.length; i++)
						{
							types += clipboard_types[i] + ';';
						}
					}
					else
					{
						types = clipboard_types;
					}

					if (
						/text\/plain/.test(types) && !ed.browser.mozilla
						&& !/text\/html/.test(types)
						&& (!/text\/rtf/.test(types) || !ed.browser.safari)
					)
					{
						isPlainPaste = true;
					}
				}
			});

			ed.events.on('paste.beforeCleanup', function(content)
			{
				if (isPlainPaste)
				{
					content = content
						.replace(/\t/g, '    ')
						.replace(/  /g, '&nbsp; ')
						.replace(/  /g, '&nbsp; ')
						.replace(/> /g, '>&nbsp;');
				}

				// by the time the clean up happens, these line breaks have been stripped
				content = content.replace(/(<pre[^>]*>)([\s\S]+)(<\/pre>)/g, function(match, open, inner, close)
				{
					inner = inner.replace(/\r?\n/g, '<br>');

					return open + inner + close;
				});

				return XF.adjustHtmlForRte(content);
			});

			ed.events.on('paste.afterCleanup', function(content)
			{
				return t.normalizePaste(content);
			});

			ed.events.on('paste.after', function()
			{
				// keep the cursor visible if possible
				var range = ed.selection.ranges(0);
				if (!range || !range.getBoundingClientRect)
				{
					return;
				}

				var rect = range.getBoundingClientRect(),
					elRect = ed.$wp[0].getBoundingClientRect();

				if (
					rect.top < 0
					|| rect.left < 0
					|| rect.bottom > $(window).height()
					|| rect.right > $(window).width()
					|| rect.bottom > elRect.bottom
				)
				{
					setTimeout(function()
					{
						t.scrollToCursor();
					}, 100);
				}
			});

			// hide the background color chooser
			ed.events.on('popups.show.colors.picker', function()
			{
				$(this.popups.get('colors.picker')).find('.fr-colors-buttons').css('display', 'none');
			});

			this.mentioner = new XF.EditorMentioner(ed);
			this.setupUploads();

			if (!XF.isEditorEnabled())
			{
				var $bbCodeInput = this.$target.next('input[data-bb-code]');
				if ($bbCodeInput.length)
				{
					ed.bbCode.toBbCode($bbCodeInput.val(), true);
				}
				else
				{
					ed.bbCode.toBbCode(null, true);
				}
			}

			XF.EditorHelpers.setupBlurSelectionWatcher(ed);

			this.$target.trigger('editor:init', [ed, this]);

			XF.layoutChange();
		},

		focus: function()
		{
			XF.EditorHelpers.focus(this.ed);
		},

		blur: function()
		{
			this.ed.$el[0].blur();
			this.ed.selection.clear();
		},

		normalizePaste: function(content)
		{
			// FF has a tendency of maintaining whitespace from the content which gives odd pasting results
			content = content.replace(/(<(ul|li|p|div)>)\s+/ig, '$1');
			content = content.replace(/\s+(<\/(ul|li|p|div)>)/ig, '$1');

			// some pastes from Chrome insert this span unexpectedly which causes extra bullet points
			content = content
				.replace(/<span>&nbsp;<\/span>/ig, ' ')
				.replace(/(<\/li>)\s+(<li)/ig, '$1$2');

			var ed = this.ed,
				frag = $.parseHTML(content),
				$fragWrapper = $('<div />').html(frag);

			$fragWrapper.find('tr').replaceWith(function() { return this.innerHTML + '<br>'; });
			$fragWrapper.find('td, tbody, thead, tfoot, table').replaceWith(function() { return this.innerHTML + ' '; });
			$fragWrapper.find('th').replaceWith(function() { return '<b>' + this.innerHTML + '</b> '; });
			$fragWrapper.find('code, del, ins, sub, sup').replaceWith(function() { return this.innerHTML; });
			$fragWrapper.find('h1, h2, h3, h4, h5, h6').replaceWith(function()
			{
				var ret = '<b>' + this.innerHTML + '</b>',
					fontSizes = ed.opts.fontSize;

				switch (this.tagName)
				{
					case 'H1': ret = '<span style="font-size: ' + fontSizes[6] + 'px">' + ret + '</span>'; break;
					case 'H2': ret = '<span style="font-size: ' + fontSizes[5] + 'px">' + ret + '</span>'; break;
					case 'H3': ret = '<span style="font-size: ' + fontSizes[4] + 'px">' + ret + '</span>'; break;
					// smaller headers are just bold
				}

				return ret + '<br>';
			});
			$fragWrapper.find('pre').replaceWith(function()
			{
				var inner = this.innerHTML;

				inner = inner
					.replace(/\r?\n/g, '<br>')
					.replace(/\t/g, '    ')
					.replace(/  /g, '&nbsp; ')
					.replace(/  /g, '&nbsp; ')
					.replace(/> /g, '>&nbsp;')
					.replace(/<br> /g, '<br>&nbsp;');

				return inner + '<br>';
			});

			// first we try to move any br tags up to the root if they're only within inline tags...
			$fragWrapper.find('br').each(function(i, br)
			{
				var $parents = $(br).parents().not($fragWrapper);
				if (!$parents.length)
				{
					// at the root of the paste already
					return;
				}

				if ($parents.filter(function(j, el) { return ed.node.isBlock(el); }).length)
				{
					// if we have a block parent, we can't move this
					return;
				}

				var $shiftTarget = $([]),
					shiftIsEl = false,
					$clone,
					ref = br,
					$topParent = $parents.last();

				do
				{
					while (ref.nextSibling)
					{
						$clone = $(ref.nextSibling).clone();
						if (shiftIsEl)
						{
							$shiftTarget.append($clone);
						}
						else
						{
							$shiftTarget = $shiftTarget.add($clone);
						}

						$(ref.nextSibling).remove();
					}
					ref = ref.parentNode;
					if (!ref || $fragWrapper.is(ref))
					{
						break;
					}

					$clone = $(ref).clone().empty();
					$clone.html($shiftTarget);
					$shiftTarget = $clone;
					shiftIsEl = true;
				}
				while (ref.parentNode && !$fragWrapper.is(ref.parentNode));
				// note: this is intentionally checking the ref.parentNode, even though ref has already been moved up.
				// we want to stop when the last tag we cloned is at the root

				$(br).remove();

				$topParent.after($shiftTarget);
				$topParent.after('<br />');
			});

			// Look for root p tags to add extra line breaks since we treat a p as a single break.
			// Try to detect an internal paste and don't add it there
			var copiedText = '',
				pastedText = $fragWrapper[0].textContent.replace(/\s/g, '');

			try
			{
				copiedText = (ed.win.localStorage.getItem('fr-copied-text') || '').replace(/\s/g, '');
			}
			catch (e) {}

			if (copiedText.length && copiedText != pastedText)
			{
				$fragWrapper.find('> p:not([data-xf-p])').each(function()
				{
					if (this.nextSibling)
					{
						$(this).after('<p />');
					}
				});
			}

			$fragWrapper.find('p').removeAttr('data-xf-p');

			frag = $fragWrapper.contents();

			// ...now we split the root level by br tags into p tags. (Make sure we do this after the p doubling
			// since br is a single break
			var node,
				$output = $('<div />'),
				$wrapTarget = null;

			for (var i = 0; i < frag.length; i++)
			{
				node = frag[i];

				if (node.nodeType == Node.ELEMENT_NODE && ed.node.isBlock(node))
				{
					$output.append(node);
					$wrapTarget = null;
				}
				else if (node.nodeType == Node.ELEMENT_NODE && node.tagName == 'BR')
				{
					if (!$wrapTarget)
					{
						// this would generally be two <br> tags in a row
						$output.append('<p />');
					}

					$wrapTarget = null;
				}
				else // text or some other type of element
				{
					if (!$wrapTarget)
					{
						$wrapTarget = $('<p />');
						$output.append($wrapTarget);
					}

					$wrapTarget.append(node);
				}
			}

			var $children = $output.children();
			if ($children.length == 1 && $children.is('p, div'))
			{
				$output = $children;
			}

			return $output.html();
		},

		watchEditorHeight: function()
		{
			var ed = this.ed,
				self = this;

			$(window).onPassive('resize', function()
			{
				var heightLimits = self.getHeightLimits();
				ed.opts.heightMin = heightLimits[0];
				ed.opts.heightMax = heightLimits[1];
				ed.size.refresh();
				XF.layoutChange();
			});
			ed.events.on('focus', function()
			{
				self.scrollToCursorAfterPendingResize();
			});

			//var getHeight = function() { return ed.$el.height(); },
			var getHeight = function() { return ed.$wp.height(); },
				height = getHeight(),
				layoutChangeIfNeeded = function()
				{
					var newHeight = getHeight();
					if (height != newHeight)
					{
						height = newHeight;
						XF.layoutChange();
					}
				};

			ed.events.on('keyup', layoutChangeIfNeeded);
			ed.events.on('commands.after', layoutChangeIfNeeded);
			ed.events.on('html.set', layoutChangeIfNeeded);
			ed.events.on('init', layoutChangeIfNeeded);
			ed.events.on('initialized', layoutChangeIfNeeded);
		},

		getHeightLimits: function()
		{
			var maxHeightOption = this.options.maxHeight,
				minHeightOption = this.options.minHeight,
				maxHeight = null,
				minHeight = null;

			if (this.$target.closest('.overlay').length)
			{
				maxHeightOption = 0.1; // don't grow the editor at all if we are in an overlay
			}

			if (maxHeightOption)
			{
				var viewHeight = $(window).height(),
					height;

				// we can't reliably detect when the keyboard displays, so we need to act like it's always displayed
				if (/(iPad|iPhone|iPod)/g.test(navigator.userAgent))
				{
					viewHeight -= 250;
				}

				if (maxHeightOption > 0)
				{
					if (maxHeightOption <= 1) // example: 0.8 = 80%
					{
						height = viewHeight * maxHeightOption;
					}
					else
					{
						height = maxHeightOption; // example 250 = 250px
					}
				}
				else // example: -100 = window height - 100 px
				{
					height = viewHeight + maxHeightOption;
				}

				maxHeight = Math.floor(height);
				maxHeight = Math.max(maxHeight, 150);
			}

			if (minHeightOption && maxHeight)
			{
				minHeight = Math.min(minHeightOption, maxHeight);
				if (minHeight == maxHeight)
				{
					minHeight -= 1; // prevents an unnecessary scrollbar
				}
			}

			return [minHeight, maxHeight];
		},

		setupUploads: function()
		{
			var t = this,
				ed = this.ed;

			ed.events.on('file.uploaded', function(response)
			{
				this.popups.hide('file.insert');
				this.events.focus();
				return t.handleUploadSuccess(response);
			});

			ed.events.on('file.error', function(details, response)
			{
				this.popups.hide('file.insert');
				t.handleUploadError(details, response);
				this.events.focus();
				return false;
			});

			if (!this.uploadUrl)
			{
				ed.events.on('image.beforeUpload', function()
				{
					return false; // prevent uploading
				});
				ed.events.on('file.beforeUpload', function()
				{
					return false; // prevent uploading
				});
			}

			ed.events.on('image.error', function(details, response)
			{
				if (!response)
				{
					return; // not an uploaded image
				}

				this.popups.hide('image.insert');
				t.handleUploadError(details, response);
				return false;
			});

			ed.events.on('image.uploaded', function(response)
			{
				var onError = function()
				{
					ed.image.remove();
					ed.popups.hide('image.insert');
					ed.events.focus();
					return false;
				};

				var onSuccess = function()
				{
					return true;
				};

				return t.handleUploadSuccess(response, onError, onSuccess);
			});

			var imageInsert = function($img, response)
			{
				if (!response)
				{
					return;
				}

				try
				{
					var json = $.parseJSON(response);
				}
				catch (e)
				{
					return;
				}

				if (json.attachment)
				{
					// clean up the data attributes that were added from our JSON response
					var id = json.attachment.attachment_id,
						attrs = $img[0].attributes,
						re = /^data-/;
					for (var i = attrs.length - 1; i >= 0; i--)
					{
						if (re.test(attrs[i].nodeName))
						{
							$img.removeAttr(attrs[i].nodeName);
						}
					}

					$img.attr('data-attachment', "full:" + id)
						.attr('alt', id);
				}
			};

			ed.events.on('image.inserted', imageInsert);
			ed.events.on('image.replaced', imageInsert);

			ed.events.on('image.loaded', function($img)
			{
				// try to prevent automatic editing of an image once inserted

				if (!ed.popups.isVisible('image.edit'))
				{
					// ... but not if we're not in the edit mode
					return;
				}

				var $editorImage = ed.image.get();
				if (!$editorImage || $editorImage[0] != $img[0])
				{
					// ... and only if it's for this image
					return;
				}

				ed.image.exitEdit(true);

				var range = ed.selection.ranges(0);
				range.setStartAfter($img[0]);
				range.collapse(true);

				var selection = ed.selection.get();
				selection.removeAllRanges();
				selection.addRange(range);

				ed.events.focus();
				t.scrollToCursor();
			});
		},

		handleUploadSuccess: function(response, onError, onSuccess)
		{
			try
			{
				var json = $.parseJSON(response);
			}
			catch (e)
			{
				json = {
					status: 'error',
					errors: [XF.phrase('oops_we_ran_into_some_problems')]
				}
			}

			if (json.status && json.status == 'error')
			{
				XF.alert(json.errors[0]);
				return onError ? onError(json) : false;
			}

			var attachmentManager = this.getAttachmentManager();
			if (attachmentManager && json.attachment)
			{
				attachmentManager.insertUploadedRow(json.attachment);
				return onSuccess ? onSuccess(json, attachmentManager) : false;
			}

			return false;
		},

		handleUploadError: function(details, response)
		{
			var json;

			try
			{
				json = $.parseJSON(response);
			}
			catch (e)
			{
				json = null;
			}

			if (!json || !json.errors)
			{
				json = {
					status: 'error',
					errors: [XF.phrase('oops_we_ran_into_some_problems')]
				};
			}

			XF.alert(json.errors[0]);
		},

		getAttachmentManager: function()
		{
			var $match = this.$target.closest('[data-xf-init~=attachment-manager]');
			if ($match && $match.length)
			{
				return XF.Element.getHandler($match, 'attachment-manager');
			}

			return null;
		},

		isBbCodeView: function()
		{
			if (this.ed.bbCode && this.ed.bbCode.isBbCodeView)
			{
				return this.ed.bbCode.isBbCodeView();
			}
			else
			{
				return false;
			}
		},

		insertContent: function(html, text)
		{
			var ed = this.ed;

			if (this.isBbCodeView())
			{
				if (typeof text !== 'undefined')
				{
					ed.bbCode.insertBbCode(text);
				}
			}
			else
			{
				this.focus();
				ed.html.insert(html);
			}

			this.scrollToCursor();
			this.scrollToCursorAfterPendingResize();
		},

		replaceContent: function(html, text)
		{
			var ed = this.ed;

			if (this.isBbCodeView())
			{
				if (typeof text !== 'undefined')
				{
					ed.bbCode.replaceBbCode(text);
				}
			}
			else
			{
				ed.html.set(html);
			}
		},

		scrollToCursor: function()
		{
			var ed = this.ed;

			if (this.isBbCodeView())
			{
				ed.bbCode.getTextArea().autofocus();
				ed.$box[0].scrollIntoView(true);
			}
			else
			{
				this.focus();

				var $edBox = ed.$box,
					$edWrapper = ed.$wp,
					selEl = ed.selection.endElement(),
					selBottom = selEl.getBoundingClientRect().bottom,
					selVisible = true,
					winHeight = XF.windowHeight();

				if (XF.browser.ios)
				{
					// assume the keyboard takes up approximately this much space
					winHeight -= 250;
				}

				if (selBottom < 0 || selBottom >= winHeight)
				{
					// outside the window
					selVisible = false;
				}
				if ($edWrapper && selVisible)
				{
					var wrapperRect = $edWrapper[0].getBoundingClientRect();

					if (selBottom > wrapperRect.bottom || selBottom < wrapperRect.top)
					{
						// inside the window, but need to scroll the wrapper
						selVisible = false;
					}
				}

				if (!selVisible)
				{
					var boxPos = $edBox[0].getBoundingClientRect();
					if (boxPos.top < 0 || boxPos.bottom >= winHeight)
					{
						if (!XF.browser.ios)
						{
							// don't add in iOS because it shouldn't apply to small screens but this doesn't trigger
							// in iOS as expected
							$edBox.addClass('is-scrolling-to');
						}
						$edBox[0].scrollIntoView(true);
						$edBox.removeClass('is-scrolling-to');
					}

					if ($edWrapper)
					{
						selBottom = selEl.getBoundingClientRect().bottom; // we may have moved the window so recalc

						// attempt to put this in the middle of the screen
						var cursorToTop = $edWrapper[0].scrollTop + selBottom - $edWrapper[0].getBoundingClientRect().top;
						$edWrapper.scrollTop(cursorToTop + winHeight / 2);
					}
					else
					{
						selEl.scrollIntoView();
					}
				}
			}
		},

		scrollToCursorAfterPendingResize: function(forceTrigger)
		{
			// This is to ensure that we keep the cursor visible after the onscreen keyboard appears
			// by trying to determine when this happens and scroll to it.
			var self = this,
				ed = this.ed,
				scrollTimer,
				onResize = function()
				{
					$(window).off('resize', onResize);
					$(window).on('scroll', scrollWatcher);

					if (scrollTimer)
					{
						clearTimeout(scrollTimer);
					}
					scrollTimer = setTimeout(scrollTo, 500);
				},
				scrollWatcher = function()
				{
					if (scrollTimer)
					{
						clearTimeout(scrollTimer);
					}
					scrollTimer = setTimeout(scrollTo, 100);
				},
				scrollTo = function()
				{
					$(window).off('scroll', scrollWatcher);

					if (ed.core.hasFocus())
					{
						self.scrollToCursor();
					}
				};

			$(window).on('resize', onResize);
			setTimeout(function()
			{
				$(window).off('resize', onResize);
			}, 2000);

			if (forceTrigger)
			{
				scrollTimer = setTimeout(scrollTo, 1000);
			}
		}
	});

	XF.EditorMentioner = XF.create({
		ed: null,
		visible: false,
		idleWait: 200,

		idleTimer: null,
		pendingMention: '',
		results: null,

		__construct: function(ed)
		{
			var t = this;

			this.ed = ed;
			this.results = new XF.AutoCompleteResults({
				onInsert: function(name)
				{
					t.insertMention(name);
				},

				clickAttacher: function($li, f)
				{
					t.ed.events.bindClick($li, $li, f);
				}
			});

			ed.events.on('keydown', $.proxy(this, 'keydown'), true);
			ed.events.on('keyup', $.proxy(this, 'keyup'), true);

			ed.events.on('click blur', function() { t.hide(); });
			ed.$wp.onPassive('scroll', function() { t.hide(); });
		},

		keydown: function(e)
		{
			if (!this.visible)
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
			var range = this.ed.selection.ranges(0);
			if (!range || !range.collapsed)
			{
				return null;
			}

			var focus = range.endContainer;
			if (!focus || focus.nodeType != 3)
			{
				// expected to be a text node
				return null;
			}

			var text = focus.nodeValue.substring(0, range.endOffset),
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
						textNode: focus,
						start: lastAt,
						name: afterAt.replace(new RegExp(String.fromCharCode(160), 'g'), ' '),
						range: range
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

			XF.EditorHelpers.focus(this.ed);

			var mentionInfo = this.getCurrentMentionInfo();
			if (!mentionInfo)
			{
				return;
			}

			var node = mentionInfo.textNode,
				text = node.nodeValue,
				afterAtPos = mentionInfo.start + 1,
				range = mentionInfo.range,
				suffix = '\u00a0';

			node.nodeValue =
				text.substr(0, afterAtPos)
				+ mention + suffix
				+ text.substr(afterAtPos + mentionInfo.name.length);

			var offset = afterAtPos + mention.length + suffix.length;
			range.setEnd(node, offset);
			range.collapse(false);

			var selection = this.ed.selection.get();
			selection.removeAllRanges();
			selection.addRange(range);
		},

		show: function(val, results)
		{
			var mentionInfo = this.getCurrentMentionInfo(),
				range = mentionInfo.range,
				$el = this.ed.$el;

			if (!mentionInfo)
			{
				return;
			}

			this.visible = true;
			this.results.showResults(val, results, $el, function($results)
			{
				var elDimensions = $el.dimensions();

				if (!range || !range.getBoundingClientRect)
				{
					var start = range.startContainer,
						$start = start.nodeType == 3 ?  $(start.parentNode) : $(start),
						startDims = $start.dimensions();

					return {
						top: startDims.bottom + 3,
						left: elDimensions.left + 5
					};
				}

				var startRange = range.cloneRange();

				// Set the range to start before the @ and cover it. This works around a problem where the @ is the
				// first character on the line and when the cursor is before it, it's on the previous line.
				startRange.setStart(mentionInfo.textNode, mentionInfo.start);
				startRange.setEnd(mentionInfo.textNode, mentionInfo.start + 1);

				var rect = startRange.getBoundingClientRect(),
					resultsWidth = $results.width(),
					targetTop = rect.bottom + $(window).scrollTop() + 3,
					targetLeft = rect.left;

				if (targetLeft + resultsWidth > elDimensions.right)
				{
					targetLeft = range.getBoundingClientRect().left - resultsWidth;
				}

				if (targetLeft < elDimensions.left)
				{
					targetLeft = elDimensions.left;
				}

				return {
					top: targetTop,
					left: targetLeft
				};
			});
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

	XF.EditorHelpers = {
		setupBlurSelectionWatcher: function(ed)
		{
			var $el = ed.$el,
				trackSelection = false,
				trackKey = 'xf-ed-blur-sel',
				range;

			$(document).on('mousedown keydown', function(e)
			{
				if (!trackSelection)
				{
					// editor isn't known to be focused
					return;
				}
				if (ed.$el[0] == e.target || $.contains(ed.$el[0], e.target))
				{
					// event triggering is the editor or within it, so should maintain selection
					return;
				}
				if (!ed.selection.inEditor())
				{
					// the current selection isn't in the editor, so nothing to save
					return;
				}

				range = ed.selection.ranges(0);
			});

			ed.events.on('blur', function()
			{
				if (range)
				{
					$el.data(trackKey, range);
				}
				else
				{
					$el.removeData(trackKey);
				}

				trackSelection = false;
				range = null;
			}, true);
			ed.events.on('focus', function()
			{
				trackSelection = true;
				range = null;

				setTimeout(function()
				{
					$el.removeData(trackKey);
				}, 0);
			});
			ed.events.on('commands.before', function(cmd)
			{
				var cmdConfig = $.FE.COMMANDS[cmd];
				if (cmdConfig && (typeof cmdConfig.focus == 'undefined' || cmdConfig.focus))
				{
					XF.EditorHelpers.restoreMaintainedSelection(ed);
					// focus will happen in the command
				}
			});
		},

		restoreMaintainedSelection: function(ed)
		{
			var $el = ed.$el,
				blurSelection = $el.data('xf-ed-blur-sel');

			if (!ed.selection.inEditor())
			{
				if (blurSelection)
				{
					ed.markers.remove();
					ed.markers.place(blurSelection, true, 0);
					ed.markers.place(blurSelection, false, 0);
					ed.selection.restore();
				}
				else
				{
					ed.selection.setAtEnd(ed.el);
					ed.selection.restore();
				}
			}
		},

		focus: function(ed)
		{
			XF.EditorHelpers.restoreMaintainedSelection(ed);
			ed.events.focus();
		},

		wrapSelectionText: function(ed, before, after, save)
		{
			if (save)
			{
				ed.selection.save();
			}

			var $markers = ed.$el.find('.fr-marker');
			$markers.first().before(XF.htmlspecialchars(before));
			$markers.last().after(XF.htmlspecialchars(after));
			ed.selection.restore();
			ed.placeholder.hide();
		},

		insertCode: function(ed, type, code)
		{
			var tag, lang, output;

			switch (type.toLowerCase())
			{
				case '': tag = 'CODE'; lang = ''; break;
				default: tag = 'CODE'; lang = type.toLowerCase(); break;
			}

			code = code.replace(/&/g, '&amp;').replace(/</g, '&lt;')
				.replace(/>/g, '&gt;').replace(/"/g, '&quot;')
				.replace(/\t/g, '    ')
				.replace(/\n /g, '\n&nbsp;')
				.replace(/  /g, '&nbsp; ')
				.replace(/  /g, ' &nbsp;') // need to do this twice to catch a situation where there are an odd number of spaces
				.replace(/\n/g, '</p>\n<p>');

			output = '[' + tag + (lang ? '=' + lang : '') + ']' + code + '[/' + tag + ']';
			if (output.match(/\n/))
			{
				output = '<p>' + output + '</p>';
				output = output.replace(/<p><\/p>/g, '<p><br></p>');
			}

			ed.html.insert(output);
		},

		insertSpoiler: function(ed, title)
		{
			var open;
			if (title)
			{
				open = '[SPOILER="' + title + '"]';
			}
			else
			{
				open = '[SPOILER]';
			}

			XF.EditorHelpers.wrapSelectionText(ed, open, '[/SPOILER]', true);
		},

		dialogs: {},

		loadDialog: function (ed, dialog)
		{
			var dialogs = XF.EditorHelpers.dialogs;
			if (dialogs[dialog])
			{
				dialogs[dialog].show(ed);
			}
			else
			{
				console.error("Unknown dialog '" + dialog + "'");
			}
		}
	};

	XF.EditorDialog = XF.create({
		ed: null,
		overlay: null,
		dialog: null,
		cache: true,

		__construct: function(dialog)
		{
			this.dialog = dialog;
		},

		show: function(ed)
		{
			this.ed = ed;

			ed.selection.save();

			XF.loadOverlay(XF.canonicalizeUrl('index.php?editor/dialog&dialog=' + this.dialog), {
				beforeShow: $.proxy(this, 'beforeShow'),
				afterShow: $.proxy(this, 'afterShow'),
				init: $.proxy(this, 'init'),
				cache: this.cache
			});
		},

		init: function(overlay)
		{
			var self = this;

			overlay.on('overlay:hidden', function()
			{
				if (self.ed)
				{
					self.ed.markers.remove();
				}
			});

			this._init(overlay);
		},

		_init: function(overlay) {},

		beforeShow: function(overlay)
		{
			this.overlay = overlay;

			this._beforeShow(overlay);
		},

		_beforeShow: function(overlay) {},

		afterShow: function(overlay)
		{
			this._afterShow(overlay);

			overlay.$overlay.find('textarea, input').first().focus();
		},

		_afterShow: function(overlay) {}
	});

	XF.EditorDialogMedia = XF.extend(XF.EditorDialog, {
		_beforeShow: function(overlay)
		{
			$('#editor_media_url').val('');
		},

		_init: function(overlay)
		{
			$('#editor_media_form').submit($.proxy(this, 'submit'));
		},

		submit: function(e)
		{
			e.preventDefault();

			var ed = this.ed,
				overlay = this.overlay;

			XF.ajax('POST',
				XF.canonicalizeUrl('index.php?editor/media'),
				{ url: $('#editor_media_url').val() },
				function (data)
				{
					if (data.matchBbCode)
					{
						ed.selection.restore();
						ed.html.insert(XF.htmlspecialchars(data.matchBbCode));
						overlay.hide();
					}
					else if (data.noMatch)
					{
						XF.alert(data.noMatch);
					}
					else
					{
						ed.selection.restore();
						overlay.hide();
					}
				}
			);
		}
	});

	XF.EditorDialogSpoiler = XF.extend(XF.EditorDialog, {
		_beforeShow: function(overlay)
		{
			$('#editor_spoiler_title').val('');
		},

		_init: function(overlay)
		{
			$('#editor_spoiler_form').submit($.proxy(this, 'submit'));
		},

		submit: function(e)
		{
			e.preventDefault();

			var ed = this.ed,
				overlay = this.overlay;

			ed.selection.restore();
			XF.EditorHelpers.insertSpoiler(ed, $('#editor_spoiler_title').val());

			overlay.hide();
		}
	});

	XF.EditorDialogCode = XF.extend(XF.EditorDialog, {
		_beforeShow: function(overlay)
		{
			this.ed.$el.blur();
		},

		_init: function(overlay)
		{
			$('#editor_code_form').submit($.proxy(this, 'submit'));
		},

		submit: function(e)
		{
			e.preventDefault();

			var ed = this.ed,
				overlay = this.overlay;

			var $codeMirror = overlay.$container.find('.CodeMirror');
			if ($codeMirror.length)
			{
				var codeMirror = $codeMirror[0].CodeMirror,
					doc = codeMirror.getDoc();

				codeMirror.save();
				doc.setValue('');

				codeMirror.setOption('mode', '');
			}

			var $type = $('#editor_code_type'),
				$code = $('#editor_code_code');

			ed.selection.restore();
			XF.EditorHelpers.insertCode(ed, $type.val(), $code.val());

			overlay.hide();

			$code.val('');
			$type.val('');
		}
	});

	XF.editorStart = {
		started: false,
		custom: [],

		startAll: function()
		{
			if (!XF.editorStart.started)
			{
				XF.editorStart.setupLanguage();
				XF.editorStart.registerCommands();
				XF.editorStart.registerCustomCommands();
				XF.editorStart.registerDialogs();

				$(document).trigger('editor:first-start');

				XF.editorStart.started = true;
			}
		},

		setupLanguage: function()
		{
			var dir = $('html').attr('dir'),
				lang;

			try
			{
				lang = $.parseJSON($('.js-editorLanguage').first().html()) || {};
			}
			catch (e)
			{
				console.error(e);
				lang = {};
			}

			$.FE.LANGUAGE['xf'] = {
				translation: lang,
				direction: dir ? dir.toLowerCase() : 'ltr'
			};
		},

		registerCommands: function()
		{
			$.FE.DefineIcon('xfQuote', { NAME: 'quote-right'});
			$.FE.RegisterCommand('xfQuote', {
				title: 'Quote',
				icon: 'xfQuote',
				undo: true,
				focus: true,
				callback: function()
				{
					XF.EditorHelpers.wrapSelectionText(this, '[QUOTE]', '[/QUOTE]', true);
				}
			});

			$.FE.DefineIcon('xfCode', { NAME: 'code'});
			$.FE.RegisterCommand('xfCode', {
				title: 'Code',
				icon: 'xfCode',
				undo: true,
				focus: true,
				callback: function()
				{
					XF.EditorHelpers.loadDialog(this, 'code');
				}
			});

			$.FE.DefineIcon('xfInlineCode', { NAME: 'terminal'});
			$.FE.RegisterCommand('xfInlineCode', {
				title: 'Inline Code',
				icon: 'xfInlineCode',
				undo: true,
				focus: true,
				callback: function()
				{
					XF.EditorHelpers.wrapSelectionText(this, '[ICODE]', '[/ICODE]', true);
				}
			});

			$.FE.DefineIcon('xfMedia', { NAME: 'video-camera'});
			$.FE.RegisterCommand('xfMedia', {
				title: 'Media',
				icon: 'xfMedia',
				undo: true,
				focus: true,
				callback: function()
				{
					XF.EditorHelpers.loadDialog(this, 'media');
				}
			});

			$.FE.DefineIcon('xfSpoiler', { NAME: 'flag'});
			$.FE.RegisterCommand('xfSpoiler', {
				title: 'Spoiler',
				icon: 'xfSpoiler',
				undo: true,
				focus: true,
				callback: function()
				{
					XF.EditorHelpers.loadDialog(this, 'spoiler');
				}
			});

			$.FE.DefineIcon('xfSmilie', { NAME: 'smile-o'});
			$.FE.RegisterCommand('xfSmilie', {
				title: 'Smilies',
				icon: 'xfSmilie',
				undo: false,
				focus: false,
				callback: function()
				{
					var ed = this;

					var $smilieBox = ed.$oel.data('xfSmilieBox');
					if ($smilieBox)
					{
						$smilieBox.toggleClassTransitioned('is-active');
						ed.$wp.toggleClassTransitioned('smilieBox-active');
					}
					else
					{
						$smilieBox = $('<div class="editorSmilies" />');
						ed.$oel.data('xfSmilieBox', $smilieBox);
						ed.$wp.before($smilieBox);

						XF.ajax('GET',
							XF.canonicalizeUrl('index.php?editor/smilies'),
							{},
							function (data)
							{
								if (data.html)
								{
									XF.setupHtmlInsert(data.html, function($html)
									{
										ed.events.bindClick($smilieBox, 'img.smilie', function(e)
										{
											var $target = $(e.currentTarget),
												$img = $target.clone();

											$img.addClass('fr-draggable');
											XF.EditorHelpers.focus(ed);
											ed.html.insert($('<div />').html($img).html());
										});

										$smilieBox.html($html).addClassTransitioned('is-active');
										ed.$wp.toggleClassTransitioned('smilieBox-active');
									});
								}
							}
						);
					}
				}
			});

			$.FE.DefineIcon('xfDraft', { NAME: 'floppy-o'});
			$.FE.RegisterCommand('xfDraft', {
				type: 'dropdown',
				title: 'Drafts',
				focus: true,
				undo: false,
				options: {
					xfDraftSave: 'Save Draft',
					xfDraftDelete: 'Delete Draft'
				},
				callback: function(cmd, val)
				{
					var $form = this.$el.closest('form');
					if (!$form.length)
					{
						console.error('No parent form to find draft handler');
						return;
					}

					var draftHandler = XF.Element.getHandler($form, 'draft');
					if (!draftHandler)
					{
						console.error('No draft handler on parent form');
						return;
					}

					if (val == 'xfDraftSave')
					{
						draftHandler.triggerSave();
					}
					else if (val == 'xfDraftDelete')
					{
						draftHandler.triggerDelete();
					}
				}
			});

			$.extend($.FE.DEFAULTS, {
				xfBbCodeAttachmentContextInput: 'attachment_hash_combined'
			});
			$.FE.PLUGINS.bbCode = function(ed)
			{
				var _isBbCodeView = false;

				function getButton()
				{
					return ed.$tb.find('.fr-command[data-cmd=xfBbCode]');
				}

				function getBbCodeBox()
				{
					var $oel = ed.$oel;

					var $bbCodeBox = $oel.data('xfBbCodeBox');
					if (!$bbCodeBox)
					{
						var borderAdjust = parseInt(ed.$wp.css('border-bottom-width'), 10)
							+ parseInt(ed.$wp.css('border-top-width'), 10);

						$bbCodeBox = $('<textarea class="input" style="display: none" />');
						$bbCodeBox.css({
							minHeight: ed.opts.heightMin ? (ed.opts.heightMin + borderAdjust) + 'px' : null,
							maxHeight: ed.opts.heightMax ? ed.opts.heightMax + 'px' : null,
							height: ed.opts.height ? (ed.opts.height + borderAdjust) + 'px' : null,
							padding: ed.$el.css('padding')
						});
						$bbCodeBox.attr('name', $oel.data('original-name'));
						$oel.data('xfBbCodeBox', $bbCodeBox);
						ed.$wp.after($bbCodeBox);

						XF.Element.applyHandler($bbCodeBox, 'textarea-handler');
						XF.Element.applyHandler($bbCodeBox, 'user-mentioner');
					}

					return $bbCodeBox;
				}

				function toBbCode(bbCode, skipFocus)
				{
					var $bbCodeBox = getBbCodeBox();

					var apply = function(bbCode, skipFocus)
					{
						_isBbCodeView = true;

						var $smilieBox,
							$button;

						$smilieBox = ed.$oel.data('xfSmilieBox');
						if ($smilieBox && $smilieBox.hasClass('is-active'))
						{
							ed.$wp.toggleClassTransitioned('smilieBox-active', false);
							$smilieBox.toggleClassTransitioned('is-active', false);
						}

						ed.undo.saveStep();
						ed.$el.blur();

						$button = getButton();

						ed.$tb.find(' > .fr-command').not($button).addClass('fr-disabled');
						$button.addClass('fr-active');

						ed.$wp.css('display', 'none');
						ed.$oel.prop('disabled', true);

						$bbCodeBox.val(bbCode)
							.css('display', '')
							.prop('disabled', false)
							.trigger('autosize');

						if (!skipFocus)
						{
							$bbCodeBox.autofocus();
						}

						XF.setIsEditorEnabled(false);
					};

					if (typeof bbCode == 'string')
					{
						apply(bbCode, skipFocus);
					}
					else
					{
						XF.ajax('POST',
							XF.canonicalizeUrl('index.php?editor/to-bb-code'),
							{ html: ed.html.get() },
							function (data) { apply(data.bbCode, skipFocus); }
						);
					}
				}

				function toHtml(html)
				{
					var $bbCodeBox = getBbCodeBox();

					var apply = function(html)
					{
						_isBbCodeView = false;

						var $button = getButton();

						ed.$tb.find(' > .fr-command').not($button).removeClass('fr-disabled');
						$button.removeClass('fr-active');

						ed.$oel.prop('disabled', false);
						ed.html.set(html);
						$bbCodeBox.css('display', 'none').prop('disabled', true);
						ed.$wp.css('display', '');
						ed.events.focus();
						ed.undo.saveStep();

						XF.setIsEditorEnabled(true);
						XF.layoutChange();
					};

					if (typeof html == 'string')
					{
						apply(html);
					}
					else
					{
						var params = { bb_code: $bbCodeBox.val() };

						var $form = ed.$el.closest('form');
						if ($form.length)
						{
							if ($form[0][ed.opts.xfBbCodeAttachmentContextInput])
							{
								params.attachment_hash_combined = $($form[0][ed.opts.xfBbCodeAttachmentContextInput]).val();
							}
						}

						XF.ajax('POST',
							XF.canonicalizeUrl('index.php?editor/to-html'),
							params,
							function (data) { apply(data.editorHtml); }
						);
					}
				}

				function toggle()
				{
					if (_isBbCodeView)
					{
						toHtml();
					}
					else
					{
						toBbCode();
					}
				}

				function isBbCodeView()
				{
					return _isBbCodeView;
				}

				function insertBbCode(bbCode)
				{
					if (!_isBbCodeView)
					{
						return;
					}

					var $bbCodeBox = getBbCodeBox();
					XF.insertIntoTextBox($bbCodeBox, bbCode);
				}

				function replaceBbCode(bbCode)
				{
					if (!_isBbCodeView)
					{
						return;
					}

					var $bbCodeBox = getBbCodeBox();
					XF.replaceIntoTextBox($bbCodeBox, bbCode);
				}

				function getTextArea()
				{
					return (_isBbCodeView ? getBbCodeBox() : null);
				}

				function _init()
				{
					ed.events.on('buttons.refresh', function()
					{
						return !_isBbCodeView;
					});
				}

				return {
					_init: _init,
					toBbCode: toBbCode,
					isBbCodeView: isBbCodeView,
					getTextArea: getTextArea,
					insertBbCode: insertBbCode,
					replaceBbCode: replaceBbCode,
					toHtml: toHtml,
					toggle: toggle
				};
			};

			$.FE.DefineIcon('xfBbCode', { NAME: 'cog'});
			$.FE.RegisterCommand('xfBbCode', {
				title: 'Toggle BB Code',
				icon: 'xfBbCode',
				undo: false,
				focus: false,
				forcedRefresh: true,
				callback: function()
				{
					this.bbCode.toggle();
				}
			});

			$.extend($.FE.DEFAULTS, {
				xfInsertOptions: []
			});
			$.FE.DefineIcon('xfInsert', { NAME: 'ellipsis-h'});
			$.FE.RegisterCommand('xfInsert', {
				type: 'dropdown',
				title: 'Insert',
				icon: 'xfInsert',
				undo: false,
				focus: true,
				html: function()
				{
					var o = '<ul class="fr-dropdown-list">',
						options = this.opts.xfInsertOptions,
						c, info;
					for (var i in options)
					{
						c = options[i];
						info = $.FE.COMMANDS[c];
						o += '<li><a class="fr-command" data-cmd="' + c + '">' + this.icon.create(info.icon || c) + '&nbsp;&nbsp;' + this.language.translate(info.title) + '</a></li>';
					}
					o += '</ul>';

					return o;
				}
			});

			$.extend($.FE.DEFAULTS, {
				xfListOptions: []
			});
			$.FE.DefineIcon('xfList', { NAME: 'list'});
			$.FE.RegisterCommand('xfList', {
				type: 'dropdown',
				title: 'List',
				icon: 'xfList',
				undo: false,
				focus: true,
				html: function()
				{
					var o = '<ul class="fr-dropdown-list">',
						options = this.opts.xfListOptions,
						c, info;
					for (var i in options)
					{
						c = options[i];
						info = $.FE.COMMANDS[c];
						o += '<li><a class="fr-command" data-cmd="' + c + '">' + this.icon.create(info.icon || c) + '&nbsp;&nbsp;' + this.language.translate(info.title) + '</a></li>';
					}
					o += '</ul>';

					return o;
				}
			});
		},

		registerCustomCommands: function()
		{
			var custom;

			try
			{
				custom = $.parseJSON($('.js-editorCustom').first().html()) || {};
			}
			catch (e)
			{
				console.error(e);
				custom = {};
			}

			for (var tag in custom)
			{
				if (!custom.hasOwnProperty(tag))
				{
					continue;
				}

				(function(tag, def)
				{
					// make sure this matches with the disabler in XF\Service\User\SignatureEdit
					var name = 'xfCustom_' + tag,
						tagUpper = tag.toUpperCase(),
						template = {};

					if (def.type == 'fa')
					{
						template = { NAME: def.value };
					}
					else if (def.type == 'image')
					{
						template = {
							template: 'image',
							SRC: '"' + XF.canonicalizeUrl(def.value) + '"',
							ALT: '"' + def.title + '"'
						};
					}

					$.FE.DefineIcon(name, template);
					$.FE.RegisterCommand(name, {
						title: def.title,
						icon: name,
						undo: true,
						focus: true,
						callback: function()
						{
							XF.EditorHelpers.wrapSelectionText(
								this,
								def.option == 'yes' ? '[' + tagUpper + '=]' : '[' + tagUpper + ']',
								'[/' + tagUpper + ']',
								true
							);
						}
					});

					XF.editorStart.custom.push(name);
				})(tag, custom[tag]);
			}
		},

		registerDialogs: function()
		{
			XF.EditorHelpers.dialogs.media = new XF.EditorDialogMedia('media');
			XF.EditorHelpers.dialogs.spoiler = new XF.EditorDialogSpoiler('spoiler');
			XF.EditorHelpers.dialogs.code = new XF.EditorDialogCode('code');
		}
	};

	$(document).one('editor:start', XF.editorStart.startAll);

	XF.Element.register('editor', 'XF.Editor');
}
(jQuery, window, document);