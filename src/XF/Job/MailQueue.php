<?php

namespace XF\Job;

class MailQueue extends AbstractJob
{
	public function run($maxRunTime)
	{
		if ($queue = $this->app->mailQueue())
		{
			$queue->run($maxRunTime);

			if ($queue->hasMore())
			{
				return $this->resume();
			}
			else
			{
				return $this->complete();
			}
		}
		else
		{
			return $this->complete();
		}
	}

	public function getStatusMessage()
	{
		return '';
	}

	public function canCancel()
	{
		return false;
	}

	public function canTriggerByChoice()
	{
		return false;
	}
}