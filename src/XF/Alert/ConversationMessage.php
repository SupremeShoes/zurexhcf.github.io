<?php

namespace XF\Alert;

class ConversationMessage extends AbstractHandler
{
	public function getEntityWith()
	{
		return ['Conversation'];
	}

	public function getOptOutActions()
	{
		return ['like'];
	}

	public function getOptOutDisplayOrder()
	{
		return 25000;
	}
}