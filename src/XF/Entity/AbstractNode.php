<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * @property Node $Node
 */
abstract class AbstractNode extends Entity
{
	abstract public function getNodeTemplateRenderer($depth);

	public function canView(&$error = null)
	{
		return \XF::visitor()->hasNodePermission($this->node_id, 'view');
	}

	public function getNodeListExtras()
	{
		return [];
	}

	/**
	 * @return string|null
	 */
	public function getNodeName()
	{
		return $this->Node ? $this->Node->node_name : null;
	}

	/**
	 * @return string|null
	 */
	public function getTitle()
	{
		return $this->Node ? $this->Node->title : '';
	}

	/**
	 * @return string|null
	 */
	public function getDescription()
	{
		return $this->Node ? $this->Node->description : '';
	}

	/**
	 * @return int
	 */
	public function getDepth()
	{
		return $this->Node ? $this->Node->depth : 0;
	}

	public function getBreadcrumbs($includeSelf = true, $linkType = 'public')
	{
		return $this->Node ? $this->Node->getBreadcrumbs($includeSelf, $linkType) : [];
	}

	public static function getListedWith()
	{
		return [];
	}

	protected static function addDefaultNodeElements(Structure $structure)
	{
		$structure->getters['node_name'] = ['getter' => 'getNodeName', 'cache' => false];
		$structure->getters['title'] = ['getter' => 'getTitle', 'cache' => false];
		$structure->getters['description'] = ['getter' => 'getDescription', 'cache' => false];
		$structure->getters['depth'] = ['getter' => 'getDepth', 'cache' => false];

		$structure->relations['Node'] = [
			'entity' => 'XF:Node',
			'type' => self::TO_ONE,
			'conditions' => 'node_id',
			'primary' => true,
			'cascadeDelete' => true
		];

		$structure->defaultWith[] = 'Node';
	}
}