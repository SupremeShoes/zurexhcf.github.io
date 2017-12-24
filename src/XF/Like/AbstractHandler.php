<?php

namespace XF\Like;

use XF\Entity\LikedContent;
use XF\Mvc\Entity\Entity;

abstract class AbstractHandler
{
	protected $contentType;

	protected $contentCacheFields = [
		'count' => 'likes',
		'recent' => 'like_users'
	];

	public function __construct($contentType)
	{
		$this->contentType = $contentType;
	}

	abstract public function likesCounted(Entity $entity);

	public function canViewContent(Entity $entity, &$error = null)
	{
		if (method_exists($entity, 'canView'))
		{
			return $entity->canView($error);
		}

		throw new \LogicException("Could not determine content viewability; please override");
	}

	public function getTemplateName()
	{
		return 'public:like_item_' . $this->contentType;
	}

	public function getTemplateData(LikedContent $like, Entity $content = null)
	{
		if (!$content)
		{
			$content = $like->Content;
		}

		return [
			'like' => $like,
			'content' => $content
		];
	}

	public function render(LikedContent $like, Entity $content = null)
	{
		if (!$content)
		{
			$content = $like->Content;
			if (!$content)
			{
				return '';
			}
		}

		$template = $this->getTemplateName();
		$data = $this->getTemplateData($like, $content);

		return \XF::app()->templater()->renderTemplate($template, $data);
	}

	public function updateContentLikes(Entity $entity, $count, array $latestLikes)
	{
		$countField = isset($this->contentCacheFields['count']) ? $this->contentCacheFields['count'] : false;
		$recentField = isset($this->contentCacheFields['recent']) ? $this->contentCacheFields['recent'] : false;

		if (!$countField && !$recentField)
		{
			return;
		}

		if ($countField)
		{
			$entity->$countField = $count;
		}
		if ($recentField)
		{
			$entity->$recentField = $latestLikes;
		}

		$entity->save();
	}

	public function updateRecentCacheForUserChange($oldUserId, $newUserId, $oldUserName, $newUserName)
	{
		if (empty($this->contentCacheFields['recent']))
		{
			return;
		}

		$entityType = \XF::app()->getContentTypeEntity($this->contentType, false);
		if (!$entityType)
		{
			return;
		}

		$structure = \XF::em()->getEntityStructure($entityType);

		// note that xf_liked_content must already be updated
		$oldFind = 'i:' . intval($oldUserId) . ';s:8:"username";s:' . strlen($oldUserName) . ':"' . $oldUserName . '";';
		$newReplace = 'i:' . intval($newUserId) . ';s:8:"username";s:' . strlen($newUserName) . ':"' . $newUserName . '";';

		$recentField = $this->contentCacheFields['recent'];
		$table = $structure->table;
		$primaryKey = $structure->primaryKey;

		\XF::db()->query("
			UPDATE (
				SELECT content_id FROM xf_liked_content
				WHERE content_type = ?
				AND like_user_id = ?
			) AS temp
			INNER JOIN {$table} AS like_table ON (like_table.`$primaryKey` = temp.content_id)
			SET like_table.`{$recentField}` = REPLACE(like_table.`{$recentField}`, ?, ?)
		", [$this->contentType, $newUserId, $oldFind, $newReplace]);
	}

	public function getContentLikeCaches(Entity $entity)
	{
		$countField = isset($this->contentCacheFields['count']) ? $this->contentCacheFields['count'] : false;
		$recentField = isset($this->contentCacheFields['recent']) ? $this->contentCacheFields['recent'] : false;
		$output = [];

		if ($countField)
		{
			$output['count'] = $entity->$countField;
		}
		if ($recentField)
		{
			$output['recent'] = $entity->$recentField;
		}

		return $output;
	}

	public function sendLikeAlert(\XF\Entity\User $receiver, \XF\Entity\User $sender, $contentId, Entity $content)
	{
		$canView = \XF::asVisitor($receiver, function() use ($content)
		{
			return $this->canViewContent($content);
		});
		if (!$canView)
		{
			return false;
		}

		/** @var \XF\Repository\UserAlert $alertRepo */
		$alertRepo = \XF::repository('XF:UserAlert');
		return $alertRepo->alertFromUser(
			$receiver, $sender, $this->contentType, $contentId, 'like', $this->getExtraDataForAlertOrFeed($content, 'alert')
		);
	}

	public function removeLikeAlert(LikedContent $like)
	{
		/** @var \XF\Repository\UserAlert $alertRepo */
		$alertRepo = \XF::repository('XF:UserAlert');
		$alertRepo->fastDeleteAlertsFromUser($like->like_user_id, $this->contentType, $like->content_id, 'like');
	}

	public function publishLikeNewsFeed(\XF\Entity\User $sender, $contentId, Entity $content)
	{
		/** @var \XF\Repository\NewsFeed $newsFeedRepo */
		$newsFeedRepo = \XF::repository('XF:NewsFeed');
		$newsFeedRepo->publish(
			$this->contentType, $contentId, 'like', $sender->user_id, $sender->username,
			$this->getExtraDataForAlertOrFeed($content, 'feed')
		);
	}

	public function unpublishLikeNewsFeed(LikedContent $like)
	{
		/** @var \XF\Repository\NewsFeed $newsFeedRepo */
		$newsFeedRepo = \XF::repository('XF:NewsFeed');
		$newsFeedRepo->unpublish($this->contentType, $like->content_id, $like->like_user_id, 'like');
	}

	protected function getExtraDataForAlertOrFeed(Entity $content, $context)
	{
		return [];
	}

	public function getContentUserId(Entity $entity)
	{
		if (isset($entity->user_id))
		{
			return $entity->user_id;
		}
		else if (isset($entity->User))
		{
			$user = $entity->User;
			if ($user instanceof \XF\Entity\User)
			{
				return $user->user_id;
			}
			else
			{
				throw new \LogicException("Found a User relation but it did not match a user; please override");
			}
		}

		throw new \LogicException("Could not determine content user ID; please override");
	}

	public function getEntityWith()
	{
		return [];
	}

	public function getContent($id)
	{
		return \XF::app()->findByContentType($this->contentType, $id, $this->getEntityWith());
	}

	public function getContentType()
	{
		return $this->contentType;
	}
}