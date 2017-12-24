<?php

namespace XF\Spam\Cleaner;

class Thread extends AbstractHandler
{
	public function canCleanUp(array $options = [])
	{
		return !empty($options['action_threads']);
	}

	public function cleanUp(array &$log, &$error = null)
	{
		$app = \XF::app();

		$threadsFinder = $app->finder('XF:Thread');
		$threads = $threadsFinder->where('user_id', $this->user->user_id)->fetch();

		if ($threads->count())
		{
			$submitter = $app->container('spam.contentSubmitter');
			$submitter->submitSpam('thread', $threads->keys());

			$action = $app->options()->spamThreadAction;
			if ($action['action'] == 'move')
			{
				$log['thread'] = [
					'action' => 'moved',
					'threadIds' => []
				];

				foreach ($threads AS $threadId => $thread)
				{
					if (!isset($log['thread']['threadIds'][$thread->node_id]))
					{
						$log['thread']['threadIds'][$thread->node_id] = [];
					}

					$log['thread']['threadIds'][$thread->node_id][] = $thread->thread_id;

					/** @var \XF\Entity\Thread $thread */
					$thread->setOption('log_moderator', false);
					$thread->node_id = $action['node_id'];
					if ($thread->discussion_state == 'moderated')
					{
						$thread->discussion_state = 'visible';
					}
					$thread->save();
				}
			}
			else // deleted
			{
				$deleteType = ($action['action'] == 'delete' ? 'hard' : 'soft');

				$log['thread'] = [
					'action' => 'deleted',
					'deleteType' => $deleteType,
					'threadIds' => []
				];

				foreach ($threads AS $threadId => $thread)
				{
					$log['thread']['threadIds'][] = $threadId;

					/** @var \XF\Entity\Thread $thread */
					$thread->setOption('log_moderator', false);
					if ($deleteType == 'soft')
					{
						$thread->softDelete();
					}
					else
					{
						$thread->delete();
					}
				}
			}
		}

		return true;
	}

	public function restore(array $log, &$error = null)
	{
		$threadsFinder = \XF::app()->finder('XF:Thread');

		if ($log['action'] == 'moved')
		{
			foreach ($log['threadIds'] AS $nodeId => $threadIds)
			{
				$threads = $threadsFinder->where('thread_id', $threadIds)->fetch();
				foreach ($threads AS $thread)
				{
					/** @var \XF\Entity\Thread $thread */
					$thread->setOption('log_moderator', false);
					$thread->node_id = $nodeId;
					$thread->save();
				}
			}
		}
		else // deleted
		{
			if ($log['deleteType'] == 'soft')
			{
				$threads = $threadsFinder->where('thread_id', $log['threadIds'])->fetch();
				foreach ($threads AS $thread)
				{
					/** @var \XF\Entity\Thread $thread */
					$thread->setOption('log_moderator', false);
					$thread->discussion_state = 'visible';
					$thread->save();
				}
			}
		}

		return true;
	}
}