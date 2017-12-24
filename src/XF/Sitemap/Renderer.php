<?php

namespace XF\Sitemap;

class Renderer
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	/**
	 * @var \XF\Entity\SitemapLog|null
	 */
	protected $sitemap;

	public function __construct(\XF\App $app, \XF\Entity\SitemapLog $sitemap = null)
	{
		$this->app = $app;
		$this->sitemap = $sitemap;
	}

	public function outputSitemap(\XF\Http\Response $response, $counter = 0)
	{
		$counter = intval($counter);

		$sitemap = $this->sitemap;

		$response->header('X-Robots-Tag', 'noindex');

		if (!$sitemap)
		{
			$response->httpCode(404);
			$response->contentType('text/plain');
			$response->body('no sitemap');
			return $response;
		}

		if ($counter <= 0)
		{
			if ($sitemap->file_count > 1)
			{
				$response->contentType('application/xml');
				$response->setDownloadFileName('sitemap-index.xml', true);
				$response->body($this->buildIndex($sitemap));
				return $response;
			}

			$counter = 1;
		}

		$fileName = $sitemap->getAbstractedSitemapFileName($counter);
		$fs = \XF::fs();
		if ($fs->has($fileName))
		{
			$response->contentType('application/xml');

			if ($sitemap->is_compressed)
			{
				$response->header('content-encoding', 'gzip');
			}

			$response->setDownloadFileName(
				$sitemap->file_count > 1 ? 'sitemap-' . $counter . '.xml' : 'sitemap.xml',
				true
			);

			$stream = $fs->readStream($fileName);
			$response->body($response->responseStream($stream));
		}
		else
		{
			$response->httpCode(404);
			$response->contentType('text/plain');
			$response->body('invalid sitemap file');
		}

		return $response;
	}

	public function buildIndex(\XF\Entity\SitemapLog $sitemap)
	{
		$output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		$options = $this->app->options();
		$boardUrl = $options->boardUrl;

		if ($options->useFriendlyUrls)
		{
			$sitemapBase = $boardUrl . '/sitemap-%d.xml';
		}
		else
		{
			$sitemapBase = $boardUrl . '/sitemap.php?c=%d';
		}

		for ($i = 1; $i <= $sitemap->file_count; $i++)
		{
			$url = sprintf($sitemapBase, $i);
			$output .= "\t"
				. '<sitemap>'
				. '<loc>' . htmlspecialchars($url) . '</loc>'
				. '<lastmod>' . gmdate(\DateTime::W3C, $sitemap->complete_date) . '</lastmod>'
				. '</sitemap>' . "\n";
		}

		$output .= '</sitemapindex>';

		return $output;
	}
}