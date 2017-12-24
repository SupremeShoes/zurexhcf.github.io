<?php

namespace XF\Help;

use XF\Mvc\Controller;
use XF\Mvc\Reply\View;

class Terms
{
	public static function renderTerms(Controller $controller, View &$response)
	{
		$tosUrl = \XF::app()->container('tosUrl');
		if (!$tosUrl)
		{
			return $controller->redirectPermanently($controller->buildLink('index'));
		}
		else
		{
			return $controller->redirectPermanently($controller->buildLink($tosUrl));
		}
	}
}