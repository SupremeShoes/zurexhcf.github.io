<?php

namespace XF\Admin\View\Banning\Emails;

class Export extends \XF\Mvc\View
{
	public function renderXml()
	{
		/** @var \DOMDocument $document */
		$document = $this->params['xml'];

		$this->response->setDownloadFileName('banned_emails.xml');

		return $document->saveXML();
	}
}