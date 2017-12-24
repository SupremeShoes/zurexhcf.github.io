<?php

namespace XF\Alert;

use XF\Mvc\Entity\Entity;

class Post extends AbstractHandler
{
	public function getEntityWith()
	{
		$visitor = \XF::visitor();

		return ['Thread', 'Thread.Forum', 'Thread.Forum.Node.Permissions|' . $visitor->permission_combination_id];
	}

	public function getOptOutActions()
	{
		return [
			'insert',
			'quote',
			'mention',
			'like'
		];
	}

	public function getOptOutDisplayOrder()
	{
		return 100;
	}
}