<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

abstract class AbstractCategoryTree extends Entity
{
	abstract public function getCategoryListExtras();

	public function hasChildren()
	{
		return ($this->rgt - $this->lft) > 1;
	}

	protected function _getBreadcrumbs($includeSelf, $linkType, $link)
	{
		/** @var \XF\Mvc\Router $router */
		$router = $this->app()->container('router.' . $linkType);
		$structure = $this->structure();

		$output = [];
		if ($this->breadcrumb_data)
		{
			foreach ($this->breadcrumb_data AS $crumb)
			{
				$output[] = [
					'value' => $crumb['title'],
					'href' => $router->buildLink($link, $crumb),
					$structure->primaryKey => $crumb[$structure->primaryKey]
				];
			}
		}

		if ($includeSelf)
		{
			$output[] = [
				'value' => $this->title,
				'href' => $router->buildLink($link, $this),
				$structure->primaryKey => $this->{$structure->primaryKey}
			];
		}

		return $output;
	}

	protected static function addCategoryTreeStructureElements(Structure $structure)
	{
		$structure->columns['parent_category_id'] = ['type' => self::UINT, 'default' => 0];
		$structure->columns['display_order'] = ['type' => self::UINT, 'default' => 1];
		$structure->columns['lft'] = ['type' => self::UINT, 'default' => 0];
		$structure->columns['rgt'] = ['type' => self::UINT, 'default' => 0];
		$structure->columns['depth'] = ['type' => self::UINT, 'default' => 0];
		$structure->columns['breadcrumb_data'] = ['type' => self::SERIALIZED_ARRAY, 'default' => []];

		$structure->behaviors['XF:TreeStructured'] = [
			'parentField' => 'parent_category_id',
			'permissionContentType' => $structure->contentType
		];

		$structure->relations['Permissions'] = [
			'entity' => 'XF:PermissionCacheContent',
			'type' => self::TO_MANY,
			'conditions' => [
				['content_type', '=', $structure->contentType],
				['content_id', '=', '$' . $structure->primaryKey]
			],
			'key' => 'permission_combination_id',
			'proxy' => true
		];
	}
}