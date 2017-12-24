<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

abstract class AbstractFieldMap extends Entity
{
	public function getContainerId()
	{
		return $this->getValue(self::getContainerKey());
	}

	public static function getContainerKey()
	{
		throw new \LogicException('This must be overridden.');
	}

	protected static function setupDefaultStructure(Structure $structure, $table, $shortName, $fieldIdentifier)
	{
		$containerKey = static::getContainerKey();

		$structure->table = $table;
		$structure->shortName = $shortName;
		$structure->primaryKey = [$containerKey, 'field_id'];
		$structure->columns = [
			$containerKey => ['type' => self::UINT, 'required' => true],
			'field_id' => ['type' => self::UINT, 'required' => true]
		];
		$structure->getters = [];
		$structure->relations = [
			'Field' => [
				'entity' => $fieldIdentifier,
				'type' => self::TO_ONE,
				'conditions' => 'field_id',
				'primary' => true
			]
		];

		return $structure;
	}
}