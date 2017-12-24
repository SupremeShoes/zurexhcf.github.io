<?php

namespace XF\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class LikedContent extends Repository
{
	/**
	 * @param string $contentType
	 * @param int $contentId
	 * @param int $userId
	 *
	 * @return \XF\Entity\LikedContent|null
	 */
	public function getLikeByContentAndLiker($contentType, $contentId, $userId)
	{
		return $this->finder('XF:LikedContent')->where([
			'content_type' => $contentType,
			'content_id' => $contentId,
			'like_user_id' => $userId
		])->fetchOne();
	}

	/**
	 * @param string $contentType
	 * @param int $contentId
	 *
	 * @return Finder
	 */
	public function findContentLikes($contentType, $contentId)
	{
		return $this->finder('XF:LikedContent')
			->where([
				'content_type' => $contentType,
				'content_id' => $contentId
			])->setDefaultOrder('like_date', 'DESC');
	}

	/**
	 * @param $likeUserId
	 *
	 * @return Finder
	 */
	public function findLikesByLikeUserId($likeUserId)
	{
		if ($likeUserId instanceof \XF\Entity\User)
		{
			$likeUserId = $likeUserId->user_id;
		}

		return $this->finder('XF:LikedContent')
			->where('like_user_id', $likeUserId)
			->setDefaultOrder('like_date');
	}

	public function toggleLike($contentType, $contentId, \XF\Entity\User $likeUser, $publish = true)
	{
		$like = $this->getLikeByContentAndLiker($contentType, $contentId, $likeUser->user_id);
		if (!$like)
		{
			$this->insertLike($contentType, $contentId, $likeUser, $publish);
			return true;
		}
		else
		{
			$like->delete();
			return false;
		}
	}

	public function insertLike($contentType, $contentId, \XF\Entity\User $likeUser, $publish = true)
	{
		if (!$likeUser->user_id)
		{
			throw new \InvalidArgumentException("Guests cannot like content");
		}

		$likeHandler = $this->getLikeHandler($contentType, true);

		$entity = $likeHandler->getContent($contentId);
		if (!$entity)
		{
			throw new \InvalidArgumentException("No entity found for '$contentType' with ID $contentId");
		}

		$like = $this->em->create('XF:LikedContent');
		$like->content_type = $contentType;
		$like->content_id = $contentId;
		$like->like_user_id = $likeUser->user_id;
		$like->content_user_id = $likeHandler->getContentUserId($entity);
		$like->is_counted = $likeHandler->likesCounted($entity);
		$like->save();

		if ($publish)
		{
			if ($like->Owner)
			{
				$likeHandler->sendLikeAlert($like->Owner, $like->Liker, $contentId, $entity);
			}
			if ($like->Liker)
			{
				$likeHandler->publishLikeNewsFeed($like->Liker, $contentId, $entity);
			}
		}

		return $like;
	}

	/**
	 * @return \XF\Like\AbstractHandler[]
	 */
	public function getLikeHandlers()
	{
		$handlers = [];

		foreach (\XF::app()->getContentTypeField('like_handler_class') AS $contentType => $handlerClass)
		{
			if (class_exists($handlerClass))
			{
				$handlerClass = \XF::extendClass($handlerClass);
				$handlers[$contentType] = new $handlerClass($contentType);
			}
		}

		return $handlers;
	}

	/**
	 * @param string $type
	 * @param bool $throw
	 *
	 * @return \XF\Like\AbstractHandler|null
	 */
	public function getLikeHandler($type, $throw = false)
	{
		$handlerClass = \XF::app()->getContentTypeFieldValue($type, 'like_handler_class');
		if (!$handlerClass)
		{
			if ($throw)
			{
				throw new \InvalidArgumentException("No like handler for '$type'");
			}
			return null;
		}

		if (!class_exists($handlerClass))
		{
			if ($throw)
			{
				throw new \InvalidArgumentException("Like handler for '$type' does not exist: $handlerClass");
			}
			return null;
		}

		$handlerClass = \XF::extendClass($handlerClass);
		return new $handlerClass($type);
	}

	/**
	 * @param \XF\Entity\LikedContent[] $likes
	 */
	public function addContentToLikes($likes)
	{
		$contentMap = [];
		foreach ($likes AS $key => $like)
		{
			$contentType = $like->content_type;
			if (!isset($contentMap[$contentType]))
			{
				$contentMap[$contentType] = [];
			}
			$contentMap[$contentType][$key] = $like->content_id;
		}

		foreach ($contentMap AS $contentType => $contentIds)
		{
			$handler = $this->getLikeHandler($contentType);
			if (!$handler)
			{
				continue;
			}

			$data = $handler->getContent($contentIds);

			foreach ($contentIds AS $likeId => $contentId)
			{
				$content = isset($data[$contentId]) ? $data[$contentId] : null;
				$likes[$likeId]->setContent($content);
			}
		}
	}

	public function rebuildContentLikeCache($contentType, $contentId, $throw = true)
	{
		$likeHandler = $this->getLikeHandler($contentType, $throw);
		if (!$likeHandler)
		{
			if ($throw)
			{
				throw new \InvalidArgumentException("No like handler found for '$contentType'");
			}
			return false;
		}

		$entity = $likeHandler->getContent($contentId);
		if (!$entity)
		{
			if ($throw)
			{
				throw new \InvalidArgumentException("No entity found for '$contentType' with ID $contentId");
			}
			return false;
		}

		$count = $this->db()->fetchOne("
			SELECT COUNT(*)
			FROM xf_liked_content
			WHERE content_type = ? AND content_id = ?
		", [$contentType, $contentId]);

		if ($count)
		{
			$latest = $this->db()->fetchAll("
				SELECT user.user_id, user.username
				FROM xf_liked_content AS liked
				INNER JOIN xf_user AS user ON (liked.like_user_id = user.user_id)
				WHERE liked.content_type = ? AND liked.content_id = ?
				ORDER BY liked.like_date DESC
				LIMIT 5
			", [$contentType, $contentId]);
		}
		else
		{
			$latest = [];
		}

		$likeHandler->updateContentLikes($entity, $count, $latest);

		return true;
	}

	public function recalculateLikeIsCounted($contentType, $contentIds, $updateLikeCount = true)
	{
		$likeHandler = $this->getLikeHandler($contentType, true);

		if (!is_array($contentIds))
		{
			$contentIds = [$contentIds];
		}
		if (!$contentIds)
		{
			return;
		}

		$entities = $likeHandler->getContent($contentIds);
		$enableIds = [];
		$disableIds = [];

		foreach ($entities AS $id => $entity)
		{
			if ($likeHandler->likesCounted($entity))
			{
				$enableIds[] = $id;
			}
			else
			{
				$disableIds[] = $id;
			}
		}

		if ($enableIds)
		{
			$this->fastUpdateLikeIsCounted($contentType, $enableIds, true, $updateLikeCount);
		}
		if ($disableIds)
		{
			$this->fastUpdateLikeIsCounted($contentType, $disableIds, false, $updateLikeCount);
		}
	}

	public function fastUpdateLikeIsCounted($contentType, $contentIds, $newValue, $updateLikeCount = true)
	{
		if (!is_array($contentIds))
		{
			$contentIds = [$contentIds];
		}
		if (!$contentIds)
		{
			return;
		}

		$newDbValue = $newValue ? 1 : 0;
		$oldDbValue = $newValue ? 0 : 1;

		$db = $this->db();
		if ($updateLikeCount)
		{
			$updates = $db->fetchPairs("
				SELECT content_user_id, COUNT(*)
				FROM xf_liked_content
				WHERE content_type = ?
					AND content_id IN (" . $db->quote($contentIds) . ")
					AND is_counted = ?
				GROUP BY content_user_id
			", [$contentType, $oldDbValue]);
			if ($updates)
			{
				$db->beginTransaction();

				$db->update('xf_liked_content',
					['is_counted' => $newDbValue],
					'content_type = ?
						AND content_id IN (' . $db->quote($contentIds) . ')
						AND is_counted = ?',
					[$contentType, $oldDbValue]
				);

				$operator = $newDbValue ? '+' : '-';
				unset($updates[0]);
				foreach ($updates AS $userId => $totalChange)
				{
					$db->query("
						UPDATE xf_user
						SET like_count = GREATEST(0, like_count {$operator} ?)
						WHERE user_id = ?
					", [$totalChange, $userId]);
				}

				$db->commit();
			}
		}
		else
		{
			$db->update('xf_liked_content',
				['is_counted' => $newDbValue],
				'content_type = ?
					AND content_id IN (' . $db->quote($contentIds) . ')
					AND is_counted = ?',
				[$contentType, $oldDbValue]
			);
		}
	}

	public function fastDeleteLikes($contentType, $contentIds, $updateLikeCount = true)
	{
		if (!is_array($contentIds))
		{
			$contentIds = [$contentIds];
		}
		if (!$contentIds)
		{
			return;
		}

		$db = $this->db();

		if ($updateLikeCount)
		{
			$updates = $db->fetchPairs("
				SELECT content_user_id, COUNT(*)
				FROM xf_liked_content
				WHERE content_type = ?
					AND content_id IN (" . $db->quote($contentIds) . ")
					AND is_counted = 1
				GROUP BY content_user_id
			", $contentType);
		}
		else
		{
			$updates = [];
		}

		$db->beginTransaction();
		if ($updates)
		{
			unset($updates[0]);
			foreach ($updates AS $userId => $totalChange)
			{
				$db->query("
					UPDATE xf_user
					SET like_count = GREATEST(0, like_count - ?)
					WHERE user_id = ?
				", [$totalChange, $userId]);
			}
		}

		$db->delete('xf_liked_content',
			'content_type = ? AND content_id IN (' . $db->quote($contentIds) . ')',
			$contentType
		);

		$db->commit();
	}

	public function getUserLikeCount($userId)
	{
		if ($userId instanceof \XF\Entity\User)
		{
			$userId = $userId->user_id;
		}

		return $this->db()->fetchOne("
			SELECT COUNT(*)
			FROM xf_liked_content
			WHERE content_user_id = ?
				AND is_counted = 1
		", $userId);
	}

	/**
	 * @param $userId
	 *
	 * @return Finder
	 */
	public function findUserLikes($userId)
	{
		if ($userId instanceof \XF\Entity\User)
		{
			$userId = $userId->user_id;
		}

		$finder = $this->finder('XF:LikedContent')
			->with('Liker')
			->where('content_user_id', $userId)
			->setDefaultOrder('like_date', 'DESC');

		return $finder;
	}
}