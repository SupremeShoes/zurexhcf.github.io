<?php

namespace XF\Import\Data;

class Forum extends AbstractNode
{
	public function getImportType()
	{
		return 'forum';
	}

	public function getEntityShortName()
	{
		return 'XF:Forum';
	}
}