<?php

namespace XF\Admin\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\FormAction;

class Development extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertDebugMode();
	}

	public function actionIndex()
	{
		return $this->view('XF:Development', 'development');
	}
}