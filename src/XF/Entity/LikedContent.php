<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
use XF\Repository;

/**
 * COLUMNS
 * @property int|null like_id
 * @property string content_type
 * @property int content_id
 * @property int like_user_id
 * @property int like_date
 * @property int content_user_id
 * @property bool is_counted
 *
 * GETTERS
 * @property Entity|null Content
 *
 * RELATIONS
 * @property \XF\Entity\User Liker
 * @property \XF\Entity\User Owner
 */
class LikedContent extends Entity
{
	public function canView(&$error = null)
	{
		$handler = $this->getHandler();
		$content = $this->Content;

		if ($handler && $content)
		{
			return $handler->canViewContent($content, $error);
		}
		else
		{
			return false;
		}
	}

	public function getHandler()
	{
		return $this->getLikeRepo()->getLikeHandler($this->content_type);
	}

	/**
	 * @return Entity|null
	 */
	public function getContent()
	{
		$handler = $this->getHandler();
		return $handler ? $handler->getContent($this->content_id) : null;
	}

	public function setContent(Entity $content = null)
	{
		$this->_getterCache['Content'] = $content;
	}

	public function render()
	{
		$handler = $this->getHandler();
		return $handler ? $handler->render($this) : '';
	}

	protected function _postSave()
	{
		if ($this->isInsert())
		{
			if ($this->is_counted)
			{
				$this->adjustUserLikeCount($this->content_user_id, 1);
			}
		}
		else
		{
			if ($this->isChanged('content_user_id'))
			{
				if ($this->getExistingValue('is_counted'))
				{
					$this->adjustUserLikeCount($this->getExistingValue('content_user_id'), -1);
				}
				if ($this->is_counted)
				{
					$this->adjustUserLikeCount($this->content_user_id, 1);
				}
			}
			else if ($this->isChanged('is_counted'))
			{
				// either now counted (increment) or no longer counted (decrement)
				$this->adjustUserLikeCount($this->content_user_id, $this->is_counted ? 1 : -1);
			}
		}

		if ($this->isChanged(['content_type', 'content_id', 'content_user_id', 'like_date', 'like_user_id']))
		{
			$this->rebuildContentLikeCache();
		}
	}

	protected function _postDelete()
	{
		if ($this->is_counted)
		{
			$this->adjustUserLikeCount($this->content_user_id, -1);
		}
		$this->rebuildContentLikeCache();

		$handler = $this->getHandler();
		if ($handler)
		{
			$handler->removeLikeAlert($this);
			$handler->unpublishLikeNewsFeed($this);
		}
	}

	protected function adjustUserLikeCount($userId, $amount)
	{
		if (!$userId)
		{
			return;
		}

		$this->db()->query("
			UPDATE xf_user
			SET like_count = GREATEST(0, like_count + ?)
			WHERE user_id = ?
		", [$amount, $userId]);
	}

	protected function rebuildContentLikeCache()
	{
		$repo = $this->getLikeRepo();
		$repo->rebuildContentLikeCache($this->content_type, $this->content_id);
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_liked_content';
		$structure->shortName = 'XF:LikedContent';
		$structure->primaryKey = 'like_id';
		$structure->columns = [
			'like_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'content_type' => ['type' => self::STR, 'maxLength' => 25, 'required' => true],
			'content_id' => ['type' => self::UINT, 'required' => true],
			'like_user_id' => ['type' => self::UINT, 'required' => true],
			'like_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'content_user_id' => ['type' => self::UINT, 'required' => true],
			'is_counted' => ['type' => self::BOOL, 'default' => true],
		];
		$structure->getters = [
			'Content' => true
		];
		$structure->relations = [
			'Liker' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$like_user_id']],
				'primary' => true
			],
			'Owner' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [['user_id', '=', '$content_user_id']],
				'primary' => true
			]
		];

		return $structure;
	}

	/**
	 * @return \XF\Repository\LikedContent
	 */
	protected function getLikeRepo()
	{
		return $this->repository('XF:LikedContent');
	}
}