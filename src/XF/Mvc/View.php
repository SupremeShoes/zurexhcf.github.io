<?php

namespace XF\Mvc;

use XF\HTTP\Response;
use XF\Mvc\Renderer\AbstractRenderer;

class View
{
	/**
	 * @var Renderer\AbstractRenderer
	 */
	protected $renderer;

	/**
	 * @var \XF\HTTP\Response
	 */
	protected $response;

	/**
	 * @var string
	 */
	protected $templateName = '';

	/**
	 * @var array
	 */
	protected $params = [];

	public function __construct(AbstractRenderer $renderer, Response $response, $templateName = '', array $params = [])
	{
		$this->renderer = $renderer;
		$this->response = $response;
		$this->templateName = $templateName;
		$this->params = $params;
	}

	public function getTemplateName()
	{
		return $this->templateName;
	}

	public function getParams()
	{
		return $this->params;
	}

	public function renderTemplate($templateName, array $params = [])
	{
		$templater = $this->renderer->getTemplater();

		if (!strpos($templateName, ':') && strpos($this->templateName, ':'))
		{
			list($type) = $templater->getTemplateTypeAndName($templateName);
			$templateName = $type . ':' . $templateName;
		}

		return $templater->renderTemplate($templateName, $params);
	}
}