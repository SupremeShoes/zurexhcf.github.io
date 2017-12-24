<?php

namespace XF\Repository;

class ThreadPrefix extends AbstractPrefix
{
	protected function getRegistryKey()
	{
		return 'threadPrefixes';
	}

	protected function getClassIdentifier()
	{
		return 'XF:ThreadPrefix';
	}
}