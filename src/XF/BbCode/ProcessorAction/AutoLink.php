<?php

namespace XF\BbCode\ProcessorAction;

use XF\BbCode\Processor;

class AutoLink implements FiltererInterface
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	protected $autoEmbed = true;
	protected $autoEmbedLink = '';
	protected $maxEmbed = PHP_INT_MAX;
	protected $embedSites = [];

	protected $embedRemaining = PHP_INT_MAX;

	protected $urlToPageTitle = false;
	protected $urlToTitleFormat = '';
	protected $urlToTitleTimeLimit = 10;

	protected $startTime;

	public function __construct(\XF\App $app, array $config = [])
	{
		$this->app = $app;

		$baseConfig = [
			'autoEmbed' => true,
			'autoEmbedLink' => '',
			'maxEmbed' => PHP_INT_MAX,
			'embedSites' => [],
			'urlToPageTitle' => false,
			'urlToTitleFormat' => '',
			'urlToTitleTimeLimit' => 10
		];
		$config = array_replace($baseConfig, $config);

		$this->autoEmbed = $config['autoEmbed'];
		$this->autoEmbedLink = $config['autoEmbedLink'];
		$this->maxEmbed = $config['maxEmbed'];
		$this->embedSites = $config['embedSites'];

		$this->urlToPageTitle = $config['urlToPageTitle'];
		$this->urlToTitleFormat = $config['urlToTitleFormat'];
		$this->urlToTitleTimeLimit = $config['urlToTitleTimeLimit'];

		$this->startTime = microtime(true);
	}

	public function addFiltererHooks(FiltererHooks $hooks)
	{
		$hooks->addSetupHook('filterSetup')
			->addStringHook('filterString')
			->addTagHook('url', 'filterUrlTag');
	}

	public function filterSetup(array $ast)
	{
		$this->embedRemaining = $this->maxEmbed;

		$mediaTotal = 0;
		$f = function(array $tree) use (&$mediaTotal, &$f)
		{
			foreach ($tree AS $entry)
			{
				if (is_array($entry))
				{
					if ($entry['tag'] == 'media')
					{
						$mediaTotal++;
					}

					$f($entry['children']);
				}
			}
		};

		$f($ast);

		$this->embedRemaining -= $mediaTotal;
	}

	public function filterUrlTag(array $tag, array $options, Processor $processor)
	{
		if ($this->autoEmbed)
		{
			$url = $processor->renderSubTreePlain($tag['children']);
			if (empty($tag['option']) || $tag['option'] == $url)
			{
				$output = $this->autoLinkUrl($url);

				// replacing this URL tag with something else
				$this->adjustTagUsageCount($processor, 'url', -1);
				$this->incrementMatchedTag($processor, $output);
				return $output;
			}
		}

		return null;
	}

	public function filterString($string, array $options, Processor $processor)
	{
		if (!empty($options['stopAutoLink']))
		{
			return $string;
		}

		$string = preg_replace_callback(
			'#(?<=[^a-z0-9@-]|^)(https?://|www\.)[^\s"<>{}`]+#iu',
			function ($match) use ($processor)
			{
				$output = $this->autoLinkUrl($match[0]);
				$this->incrementMatchedTag($processor, $output);
				return $output;
			},
			$string
		);

		if (strpos($string, '@') !== false)
		{
			// assertion to prevent matching email in url matched above (user:pass@example.com)
			$string = preg_replace_callback(
				'#[a-z0-9.+_-]+@[a-z0-9-]+(\.[a-z]+)+(?![^\s"]*\[/url\])#iu',
				function ($match) use ($processor)
				{
					$this->incrementTagUsageCount($processor, 'email');
					return '[email]' . $match[0] . '[/email]';
				},
				$string
			);
		}

		return $string;
	}

	public function autoLinkUrl($url)
	{
		$link = $this->app->stringFormatter()->prepareAutoLinkedUrl($url);

		if ($link['url'] === $link['linkText'])
		{
			if ($this->autoEmbed
				&& $this->embedRemaining > 0
				&& ($mediaTag = $this->getEmbedBbCode($link['url']))
			)
			{
				$tag = $mediaTag;
				$this->embedRemaining--;
			}
			else
			{
				$tag = $this->getUrlBbCode($link['url']);
			}
		}
		else
		{
			$tag = '[URL="' . $link['url'] . '"]' . $link['linkText'] . '[/URL]';
		}

		return $tag . $link['suffixText'];
	}

	protected function getUrlBbCode($url)
	{
		if ($this->urlToPageTitle)
		{
			$title = $this->getUrlTitle($url);
			if ($title)
			{
				$format = $this->urlToTitleFormat ?: '{title}';
				$tokens = [
					'{title}' => $title,
					'{url}' => $url
				];
				$linkTitle = strtr($format, $tokens);

				$tag = '[URL="' . $url . '"]' . $linkTitle . '[/URL]';
			}
			else
			{
				$tag = '[URL]' . $url . '[/URL]';
			}
		}
		else
		{
			$tag = '[URL]' . $url . '[/URL]';
		}

		return $tag;
	}

	protected function getValidRequestUrl($url)
	{
		$requestUrl = preg_replace('/#.*$/', '', $url);
		if (preg_match_all('/[^A-Za-z0-9._~:\/?#\[\]@!$&\'()*+,;=%-]/', $requestUrl, $matches))
		{
			foreach ($matches[0] AS $match)
			{
				$requestUrl = str_replace($match[0], '%' . strtoupper(dechex(ord($match[0]))), $requestUrl);
			}
		}

		if ($this->canFetchUrlHtml($requestUrl))
		{
			return $requestUrl;
		}

		return false;
	}

	protected function canFetchUrlHtml($requestUrl)
	{
		if ($requestUrl != $this->app->stringFormatter()->censorText($requestUrl))
		{
			return false;
		}

		if ($this->urlToTitleTimeLimit && microtime(true) - $this->startTime > $this->urlToTitleTimeLimit)
		{
			return false;
		}

		return true;
	}

	protected function fetchUrlHtml($requestUrl)
	{
		$response = $this->app->http()->reader()->getUntrusted(
			$requestUrl,
			[
				'time' => 5,
				'bytes' => 1.5 * 1024 * 1024
			]
		);
		if (!$response || $response->getStatusCode() != 200)
		{
			return false;
		}

		$charset = null;

		$contentType = $response->getHeader('Content-type');
		if ($contentType)
		{
			$parts = explode(';', $contentType, 2);

			$type = trim($parts[0]);
			if ($type != 'text/html')
			{
				return false;
			}

			if (isset($parts[1]) && preg_match('/charset=([-a-z0-9_]+)/i', trim($parts[1]), $match))
			{
				$charset = $match[1];
			}
		}

		return [
			'body' => $response->getBody()->read(50 * 1024),
			'charset' => $charset
		];
	}

	protected function getUrlTitle($url)
	{
		if (!$requestUrl = $this->getValidRequestUrl($url))
		{
			return false;
		}

		if ($fetchResults = $this->fetchUrlHtml($requestUrl))
		{
			$body = $fetchResults['body'];
			$charset = $fetchResults['charset'];

			$title = '';
			if (preg_match('#<meta[^>]+property="(og:|twitter:)title"[^>]*content="([^">]+)"#siU', $body, $match))
			{
				$title = isset($match[2]) ? $match[2] : '';
			}
			if (!$title && preg_match('#<title[^>]*>(.*)</title>#siU', $body, $match))
			{
				$title = $match[1];
			}
			if (!$title)
			{
				return false;
			}

			if (!$charset)
			{
				preg_match('/charset=([^;"\\s]+|"[^;"]+")/i', $body, $contentTypeMatch);

				if (isset($contentTypeMatch[1]))
				{
					$charset = trim($contentTypeMatch[1], " \t\n\r\0\x0B\"");
				}

				if (!$charset)
				{
					$charset = 'windows-1252';
				}
			}

			$title = \XF::cleanString($title);

			// note: assumes charset is ascii compatible
			if (preg_match('/[\x80-\xff]/', $title))
			{
				$newString = false;
				if (function_exists('iconv'))
				{
					$newString = @iconv($charset, 'utf-8//IGNORE', $title);
				}
				if (!$newString && function_exists('mb_convert_encoding'))
				{
					$newString = @mb_convert_encoding($title, 'utf-8', $charset);
				}
				$title = ($newString ? $newString : preg_replace('/[\x80-\xff]/', '', $title));
			}

			$title = utf8_unhtml($title, true);
			$title = preg_replace('/[\xF0-\xF7].../', '', $title);
			$title = preg_replace('/[\xF8-\xFB]..../', '', $title);

			$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$title = utf8_unhtml($title);
			$title = str_replace("\n", ' ', trim($title));
			$title = \XF::cleanString($title);

			if (!strlen($title))
			{
				return false;
			}

			$bbCodeContainer = $this->app->bbCode();

			/** @var \XF\BbCode\ProcessorAction\AnalyzeUsage $usage */
			$usage = $bbCodeContainer->processorAction('usage');

			$bbCodeContainer->processor()
				->addProcessorAction('usage', $usage)
				->render($title, $bbCodeContainer->parser(), $bbCodeContainer->rules('base'));

			if ($usage->getSmilieCount() || $usage->getTotalTagCount())
			{
				$title = "[PLAIN]{$title}[/PLAIN]";
			}

			return $title;
		}
		else
		{
			return false;
		}
	}

	protected function getEmbedBbCode($url)
	{
		$match = $this->app->repository('XF:BbCodeMediaSite')->urlMatchesMediaSiteList($url, $this->embedSites);
		if (!$match)
		{
			return null;
		}

		$matchBbCode = '[MEDIA=' . $match['media_site_id'] . ']' . $match['media_id'] . '[/MEDIA]';

		if (!empty($match['site']->oembed_enabled))
		{
			$this->cacheOembedResponse($match['site'], $match['media_id']);
		}

		if ($this->autoEmbedLink)
		{
			$matchBbCode .= "\n" . str_replace('{$url}', "{$url}", $this->autoEmbedLink) . "\n";
		}

		return $matchBbCode;
	}

	protected function cacheOembedResponse($site, $mediaId)
	{
		/** @var \XF\Service\Oembed $oEmbedService */
		$oEmbedService = $this->app->service('XF:Oembed');
		$oEmbedService->getOembed($site->media_site_id, $mediaId);
	}

	protected function incrementMatchedTag(Processor $processor, $output)
	{
		if (preg_match('#^\[([a-z0-9_]+)#i', $output, $match))
		{
			$this->incrementTagUsageCount($processor, strtolower($match[1]));
		}
	}

	protected function incrementTagUsageCount(Processor $processor, $tag)
	{
		$this->adjustTagUsageCount($processor, $tag, 1);
	}

	protected function adjustTagUsageCount(Processor $processor, $tag, $adjust)
	{
		$usage = $processor->getAnalyzer('usage');
		if ($usage && $usage instanceof AnalyzeUsage)
		{
			$usage->adjustTagCount($tag, $adjust);
		}
	}

	public static function factory(\XF\App $app, array $config = [])
	{
		$options = $app->options();

		$autoEmbed = $options->autoEmbedMedia;

		$baseConfig = [
			'autoEmbed' => (bool)$autoEmbed['embedType'], // 0 is false, otherwise true
			'autoEmbedLink' => $autoEmbed['embedType'] == 2 ? $autoEmbed['linkBbCode'] : '',
			'maxEmbed' => ($options->messageMaxMedia ? $options->messageMaxMedia : PHP_INT_MAX),
			'embedSites' => null,
			'urlToPageTitle' => $options->urlToPageTitle['enabled'],
			'urlToTitleFormat' => $options->urlToPageTitle['format']
		];

		$config = array_replace($baseConfig, $config);
		if ($config['embedSites'] === null)
		{
			$config['embedSites'] = $app->repository('XF:BbCodeMediaSite')->findActiveMediaSites()->fetch();
		}

		return new static($app, $config);
	}
}