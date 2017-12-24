<?php

namespace XF\Import\Data;

class Node extends AbstractEmulatedData
{
	/**
	 * @var AbstractNode|null
	 */
	protected $typeData;

	public function getImportType()
	{
		return 'node';
	}

	public function getEntityShortName()
	{
		return 'XF:Node';
	}

	public function setType($nodeTypeId, AbstractNode $typeData)
	{
		$this->node_type_id = $nodeTypeId;
		$this->typeData = $typeData;

		return $this;
	}

	protected function preSave($oldId)
	{
		if (!$this->typeData)
		{
			throw new \LogicException("Must provide a node type and data");
		}
	}

	protected function postSave($oldId, $newId)
	{
		$this->typeData->node_id = $newId;
		$this->typeData->save($oldId);

		\XF::runOnce('nodeImport', function()
		{
			/** @var \XF\Service\Node\RebuildNestedSet $service */
			$service = \XF::service('XF:Node\RebuildNestedSet', 'XF:Node', [
				'parentField' => 'parent_node_id'
			]);
			$service->rebuildNestedSetInfo();
		});
	}
}