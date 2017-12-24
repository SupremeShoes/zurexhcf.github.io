<?php

namespace XF\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class Smilie extends Repository
{
	/**
	 * @return Finder
	 */
	public function findSmiliesForList($displayInEditorOnly = false)
	{
		$finder = $this->finder('XF:Smilie')
			->order('display_order');

		if ($displayInEditorOnly)
		{
			$finder->where('display_in_editor', 1);
		}

		return $finder;
	}

	public function getSmilieListData($displayInEditorOnly = false)
	{
		$smilies = $this->findSmiliesForList($displayInEditorOnly)
			->fetch();

		$smilieCategories = $this->getSmilieCategoryRepo()
			->findSmilieCategoriesForList(true);

		return [
			'smilieCategories' => $smilieCategories,
			'totalSmilies' => $smilies->count(),
			'smilies' => $smilies->groupBy('smilie_category_id')
		];
	}

	public function findSmiliesByText($matchText)
	{
		if (!is_array($matchText))
		{
			$matchText = preg_split('/\r?\n/', $matchText, -1, PREG_SPLIT_NO_EMPTY);
		}

		if (!$matchText)
		{
			return [];
		}

		$matches = [];
		foreach ($this->finder('XF:Smilie')->fetch() AS $smilie)
		{
			$smilieText = preg_split('/\r?\n/', $smilie['smilie_text'], -1, PREG_SPLIT_NO_EMPTY);

			$textMatch = array_intersect($matchText, $smilieText);
			foreach ($textMatch AS $text)
			{
				$matches[$text] = $smilie;
			}
		}

		return $matches;
	}

	public function getSmilieCacheData()
	{
		$smilies = $this->finder('XF:Smilie')
			->order(['display_order', 'title'])
			->fetch();

		$cache = [];

		foreach ($smilies AS $smilieId => $smilie)
		{
			$smilie = $smilie->toArray();

			$cache[$smilieId] = $smilie;
			$cache[$smilieId]['smilieText'] = preg_split('/\r?\n/', $smilie['smilie_text'], -1, PREG_SPLIT_NO_EMPTY);

			if (!$smilie['sprite_mode'] || !$smilie['sprite_params'])
			{
				unset($cache[$smilieId]['sprite_params']);
			}

			unset($cache[$smilieId]['sprite_mode'], $cache[$smilieId]['smilie_text']);
		}

		return $cache;
	}

	public function rebuildSmilieCache()
	{
		$cache = $this->getSmilieCacheData();
		\XF::registry()->set('smilies', $cache);
		return $cache;
	}

	public function getSmilieSpriteCacheData()
	{
		$smilies = $this->finder('XF:Smilie')
			->order(['display_order', 'title'])
			->fetch();

		$cache = [];

		foreach ($smilies AS $smilieId => $smilie)
		{
			if ($smilie->sprite_mode && !empty($smilie->sprite_params))
			{
				$cache[$smilieId] = ['sprite_css' => sprintf('width: %1$dpx; height: %2$dpx; background: url(\'%3$s\') no-repeat %4$dpx %5$dpx;',
					(int)$smilie->sprite_params['w'],
					(int)$smilie->sprite_params['h'],
					htmlspecialchars($smilie->image_url),
					(int)$smilie->sprite_params['x'],
					(int)$smilie->sprite_params['y']
				)];

				if (!empty($smilie->sprite_params['bs']))
				{
					$cache[$smilieId]['sprite_css'] .= ' background-size: ' . htmlspecialchars($smilie->sprite_params['bs']);
				}
			}
		}

		return $cache;
	}

	public function rebuildSmilieSpriteCache()
	{
		$cache = $this->getSmilieSpriteCacheData();
		\XF::registry()->set('smilieSprites', $cache);
		$this->repository('XF:Style')->updateAllStylesLastModifiedDate();
		return $cache;
	}

	/**
	 * @return SmilieCategory
	 */
	protected function getSmilieCategoryRepo()
	{
		return $this->repository('XF:SmilieCategory');
	}
}