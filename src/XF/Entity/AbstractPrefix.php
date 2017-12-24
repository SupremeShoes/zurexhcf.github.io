<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;
use XF\Template\Compiler\Syntax\Str;

abstract class AbstractPrefix extends Entity
{
	abstract protected function getClassIdentifier();

	protected static function getContentType()
	{
		throw new \LogicException('The phrase group must be overridden.');
	}

	public function isUsableByUser(\XF\Entity\User $user = null)
	{
		$user = $user ?: \XF::visitor();

		foreach ($this->allowed_user_group_ids AS $userGroupId)
		{
			if ($userGroupId == -1 || $user->isMemberOf($userGroupId))
			{
				return true;
			}
		}

		return false;
	}

	public function getPhraseName()
	{
		return static::getContentType() . '_prefix.' . $this->prefix_id;
	}

	/**
	 * @return \XF\Phrase|string
	 */
	public function getTitle()
	{
		return $this->prefix_id ? \XF::phrase($this->getPhraseName(), [], false) : '';
	}

	public function getMasterPhrase()
	{
		$phrase = $this->MasterTitle;
		if (!$phrase)
		{
			$phrase = $this->_em->create('XF:Phrase');
			$phrase->title = $this->_getDeferredValue(function() { return $this->getPhraseName(); }, 'save');
			$phrase->language_id = 0;
			$phrase->addon_id = '';
		}

		return $phrase;
	}

	protected function _postSave()
	{
		$this->rebuildPrefixCaches();
	}

	protected function _postDelete()
	{
		if ($this->MasterTitle)
		{
			$this->MasterTitle->delete();
		}

		$this->rebuildPrefixCaches();
	}

	protected function rebuildPrefixCaches()
	{
		$repo = $this->getPrefixRepo();

		\XF::runOnce($this->getContentType() . 'PrefixCaches', function() use ($repo)
		{
			$repo->rebuildPrefixMaterializedOrder();
			$repo->rebuildPrefixCache();
		});
	}

	protected static function setupDefaultStructure(Structure $structure, $table, $shortName)
	{
		$structure->table = $table;
		$structure->shortName = $shortName;
		$structure->primaryKey = 'prefix_id';
		$structure->columns = [
			'prefix_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'prefix_group_id' => ['type' => self::UINT, 'default' => 0],
			'display_order' => ['type' => self::UINT, 'forced' => true, 'default' => 1],
			'materialized_order' => ['type' => self::UINT, 'forced' => true, 'default' => 0],
			'css_class' => ['type' => self::STR, 'maxLength' => 50, 'default' => 'label label--primary'],
			'allowed_user_group_ids' => ['type' => self::LIST_COMMA, 'default' => [-1]]
		];
		$structure->getters = [
			'title' => true
		];
		$structure->relations = [
			'MasterTitle' => [
				'entity' => 'XF:Phrase',
				'type' => self::TO_ONE,
				'conditions' => [
					['language_id', '=', 0],
					['title', '=', static::getContentType() . '_prefix.', '$prefix_id']
				]
			],
			'PrefixGroup' => [
				'entity' => $shortName . 'Group',
				'type' => self::TO_ONE,
				'conditions' => 'prefix_group_id',
				'primary' => true
			]
		];
	}

	/**
	 * @return \XF\Repository\AbstractPrefix
	 */
	protected function getPrefixRepo()
	{
		return $this->repository($this->getClassIdentifier());
	}
}