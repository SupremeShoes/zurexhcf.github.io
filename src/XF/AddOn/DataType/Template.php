<?php

namespace XF\AddOn\DataType;

class Template extends AbstractDataType
{
	public function getShortName()
	{
		return 'XF:Template';
	}

	public function getContainerTag()
	{
		return 'templates';
	}

	public function getChildTag()
	{
		return 'template';
	}

	public function exportAddOnData($addOnId, \DOMElement $container)
	{
		$entries = $this->finder()
			->where('addon_id', $addOnId)
			->where('style_id', 0)
			->order('title')->fetch();
		foreach ($entries AS $entry)
		{
			$node = $container->ownerDocument->createElement($this->getChildTag());

			$this->exportMappedAttributes($node, $entry);
			$this->exportCdata($node, $entry->template);

			$container->appendChild($node);
		}

		return $entries->count() ? true : false;
	}

	public function importAddOnData($addOnId, \SimpleXMLElement $container, $start = 0, $maxRunTime = 0)
	{
		$startTime = microtime(true);

		$entries = $this->getEntries($container, $start);
		if (!$entries)
		{
			return false;
		}

		$conditions = [];
		foreach ($entries AS $entry)
		{
			$conditions[] = [
				'type' => (string)$entry['type'],
				'title' => (string)$entry['title'],
			];
		}
		if ($conditions)
		{
			$existing = $this->finder()
				->whereOr($conditions)
				->where('style_id', 0)
				->fetch()
				->groupBy('type', 'title');
		}
		else
		{
			$existing = [];
		}

		$i = 0;
		$last = 0;

		foreach ($entries AS $entry)
		{
			$i++;

			if ($i <= $start)
			{
				continue;
			}

			$type = (string)$entry['type'];
			$title = (string)$entry['title'];
			$entity = isset($existing[$type][$title]) ? $existing[$type][$title] : $this->create();
			$entity->setOption('check_duplicate', false);
			$entity->getBehavior('XF:DevOutputWritable')->setOption('write_dev_output', false);

			$this->importMappedAttributes($entry, $entity);
			$entity->style_id = 0;
			$entity->template = $this->getCdataValue($entry);
			$entity->addon_id = $addOnId;
			$entity->save(true, false);

			if ($this->resume($maxRunTime, $startTime))
			{
				$last = $i;
				break;
			}
		}
		return ($last ?: false);
	}

	public function deleteOrphanedAddOnData($addOnId, \SimpleXMLElement $container)
	{
		$existing = $this->findAllForType($addOnId)->where('style_id', 0)->fetch()->groupBy('type', 'title');
		if (!$existing)
		{
			return;
		}

		$entries = $this->getEntries($container) ?: [];

		foreach ($entries AS $entry)
		{
			$type = (string)$entry['type'];
			$title = (string)$entry['title'];

			if (isset($existing[$type][$title]))
			{
				unset($existing[$type][$title]);
			}
		}

		array_walk_recursive($existing, function($entity)
		{
			if ($entity instanceof \XF\Entity\Template)
			{
				$entity->getBehavior('XF:DevOutputWritable')->setOption('write_dev_output', false);
				$entity->delete();
			}
		});
	}

	protected function getMappedAttributes()
	{
		return [
			'type',
			'title',
			'version_id',
			'version_string'
		];
	}
}