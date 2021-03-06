<?php

namespace XF\Service\Post;

use XF\Entity\Post;
use XF\Entity\User;

class Merger extends \XF\Service\AbstractService
{
	/**
	 * @var Post
	 */
	protected $target;

	/**
	 * @var \XF\Service\Post\Preparer
	 */
	protected $postPreparer;

	protected $alert = false;
	protected $alertReason = '';

	protected $log = true;

	/**
	 * @var \XF\Entity\Thread[]
	 */
	protected $sourceThreads = [];

	/**
	 * @var \XF\Entity\Post[]
	 */
	protected $sourcePosts = [];

	public function __construct(\XF\App $app, Post $target)
	{
		parent::__construct($app);

		$this->target = $target;
		$this->postPreparer = $this->service('XF:Post\Preparer', $this->target);
	}

	public function getTarget()
	{
		return $this->target;
	}

	public function setSendAlert($alert, $reason = null)
	{
		$this->alert = (bool)$alert;
		if ($reason !== null)
		{
			$this->alertReason = $reason;
		}
	}

	public function setLog($log)
	{
		$this->log = (bool)$log;
	}

	public function setMessage($message, $format = true)
	{
		return $this->postPreparer->setMessage($message, $format);
	}

	public function merge($sourcePostsRaw)
	{
		if ($sourcePostsRaw instanceof \XF\Mvc\Entity\AbstractCollection)
		{
			$sourcePostsRaw = $sourcePostsRaw->toArray();
		}
		else if ($sourcePostsRaw instanceof Post)
		{
			$sourcePostsRaw = [$sourcePostsRaw];
		}
		else if (!is_array($sourcePostsRaw))
		{
			throw new \InvalidArgumentException('Posts must be provided as collection, array or entity');
		}

		if (!$sourcePostsRaw)
		{
			return false;
		}

		$db = $this->db();

		/** @var Post[] $sourcePosts */
		$sourcePosts = [];

		/** @var \XF\Entity\Thread[] $sourceThreads */
		$sourceThreads = [];

		foreach ($sourcePostsRaw AS $sourcePost)
		{
			$sourcePost->setOption('log_moderator', false);
			$sourcePosts[$sourcePost->post_id] = $sourcePost;

			/** @var \XF\Entity\Thread $sourceThread */
			$sourceThread = $sourcePost->Thread;
			if (!isset($sourceThreads[$sourceThread->thread_id]))
			{
				$sourceThread->setOption('log_moderator', false);
				$sourceThreads[$sourceThread->thread_id] = $sourceThread;
			}
		}

		$this->sourceThreads = $sourceThreads;
		$this->sourcePosts = $sourcePosts;

		$target = $this->target;
		$target->setOption('log_moderator', false);

		$db->beginTransaction();

		$this->moveDataToTarget();
		$this->updateTargetData();
		$this->updateSourceData();
		$this->updateUserCounters();

		if ($this->alert)
		{
			$this->sendAlert();
		}

		$this->finalActions();

		$target->save();

		$db->commit();

		return true;
	}

	protected function moveDataToTarget()
	{
		$db = $this->db();
		$target = $this->target;

		$sourcePosts = $this->sourcePosts;
		$sourcePostIds = array_keys($sourcePosts);
		$sourceIdsQuoted = $db->quote($sourcePostIds);

		$rows = $db->update('xf_attachment',
			['content_id' => $target->post_id],
			"content_id IN ($sourceIdsQuoted) AND content_type = 'post'"
		);

		$target->attach_count += $rows;

		foreach ($sourcePosts AS $sourcePost)
		{
			$sourcePost->delete();
		}
	}

	protected function updateTargetData()
	{
		/** @var \XF\Entity\Thread $targetThread */
		$targetThread = $this->target->Thread;

		$targetThread->rebuildCounters();
		$targetThread->save();

		/** @var \XF\Repository\Thread $threadRepo */
		$threadRepo = $this->repository('XF:Thread');
		$threadRepo->rebuildThreadPostPositions($targetThread->thread_id);
		$threadRepo->rebuildThreadUserPostCounters($targetThread->thread_id);
	}

	protected function updateSourceData()
	{
		/** @var \XF\Repository\Thread $threadRepo */
		$threadRepo = $this->repository('XF:Thread');

		foreach ($this->sourceThreads AS $sourceThread)
		{
			$sourceThread->rebuildCounters();

			$sourceThread->save(); // has to be saved for the delete to work (if needed).

			if (array_key_exists($sourceThread->first_post_id, $this->sourcePosts) && $sourceThread->reply_count == 0)
			{
				$sourceThread->delete(); // first post has been moved out, no other replies, thread now empty
			}
			else
			{
				$threadRepo->rebuildThreadPostPositions($sourceThread->thread_id);
				$threadRepo->rebuildThreadUserPostCounters($sourceThread->thread_id);
			}

			$sourceThread->Forum->rebuildCounters();
			$sourceThread->Forum->save();
		}
	}

	protected function updateUserCounters()
	{
		$target = $this->target;
		$targetThread = $target->Thread;

		$targetMessagesCount = (
			$targetThread->Forum && $targetThread->Forum->count_messages
			&& $targetThread->discussion_state == 'visible'
		);
		$targetLikesCount = ($targetThread->discussion_state == 'visible');

		$sourcesMessagesCount = [];
		$sourcesLikesCount = [];
		foreach ($this->sourceThreads AS $id => $sourceThread)
		{
			$sourcesMessagesCount[$id] = (
				$sourceThread->Forum && $sourceThread->Forum->count_messages
				&& $sourceThread->discussion_state == 'visible'
			);
			$sourcesLikesCount[$id] = ($sourceThread->discussion_state == 'visible');
		}

		$likesEnable = [];
		$likesDisable = [];
		$userMessageCountAdjust = [];

		foreach ($this->sourcePosts AS $id => $post)
		{
			if ($post['message_state'] != 'visible')
			{
				continue; // everything will stay the same in the new thread
			}

			$sourceMessagesCount = $sourcesMessagesCount[$post->thread_id];
			$sourceLikesCount = $sourcesLikesCount[$post->thread_id];

			if ($post['likes'])
			{
				if ($sourceLikesCount && !$targetLikesCount)
				{
					$likesDisable[] = $id;
				}
				else if (!$sourceLikesCount && $targetLikesCount)
				{
					$likesEnable[] = $id;
				}
			}

			$userId = $post->user_id;
			if ($userId)
			{
				if ($sourceMessagesCount && !$targetMessagesCount)
				{
					if (!isset($userMessageCountAdjust[$userId]))
					{
						$userMessageCountAdjust[$userId] = 0;
					}
					$userMessageCountAdjust[$userId]--;
				}
				else if (!$sourceMessagesCount && $targetMessagesCount)
				{
					if (!isset($userMessageCountAdjust[$userId]))
					{
						$userMessageCountAdjust[$userId] = 0;
					}
					$userMessageCountAdjust[$userId]++;
				}
			}
		}

		if ($likesDisable)
		{
			/** @var \XF\Repository\LikedContent $likeRepo */
			$likeRepo = $this->repository('XF:LikedContent');
			$likeRepo->fastUpdateLikeIsCounted('post', $likesDisable, false);
		}
		if ($likesEnable)
		{
			/** @var \XF\Repository\LikedContent $likeRepo */
			$likeRepo = $this->repository('XF:LikedContent');
			$likeRepo->fastUpdateLikeIsCounted('post', $likesEnable, true);
		}
		foreach ($userMessageCountAdjust AS $userId => $adjust)
		{
			if ($adjust)
			{
				$this->db()->query("
					UPDATE xf_user
					SET message_count = GREATEST(0, message_count + ?)
					WHERE user_id = ?
				", [$adjust, $userId]);
			}
		}
	}

	protected function sendAlert()
	{
		/** @var \XF\Repository\Post $postRepo */
		$postRepo = $this->repository('XF:Post');

		$alerted = [];
		foreach ($this->sourcePosts AS $sourcePost)
		{
			if (isset($alerted[$sourcePost->user_id]))
			{
				continue;
			}

			if ($sourcePost->message_state == 'visible' && $sourcePost->user_id != \XF::visitor()->user_id)
			{
				$postRepo->sendModeratorActionAlert($sourcePost, 'merge', $this->alertReason);
				$alerted[$sourcePost->user_id] = true;
			}
		}
	}

	protected function finalActions()
	{
		$target = $this->target;
		$postIds = array_keys($this->sourcePosts);

		if ($postIds)
		{
			$this->app->jobManager()->enqueue('XF:SearchIndex', [
				'content_type' => 'post',
				'content_ids' => $postIds
			]);
		}

		if ($this->log)
		{
			$this->app->logger()->logModeratorAction('post', $target, 'merge_target',
				['ids' => implode(', ', $postIds)]
			);
		}
	}
}