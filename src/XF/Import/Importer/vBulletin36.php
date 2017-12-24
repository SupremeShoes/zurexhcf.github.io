<?php

namespace XF\Import\Importer;

class vBulletin36 extends vBulletin
{
	public static function getListInfo()
	{
		return [
			'target' => 'XenForo',
			'source' => 'vBulletin 3.6',
			'beta' => true
		];
	}

	public function getSteps()
	{
		$steps = parent::getSteps();

		$removeSteps = [
			'visitorMessages',
			'threadPrefixes',
			'postEditHistory',
			'infractions',
			'contentTags'
		];

		foreach ($removeSteps AS $step)
		{
			unset($steps[$step]);
		}

		// remove dependency on thread prefixes
		$steps['threads']['depends'] = ['forums'];

		return $steps;
	}
}