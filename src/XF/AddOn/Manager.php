<?php

namespace XF\AddOn;

class Manager
{
	protected $addOnDir;

	protected $jsonInfo;

	/**
	 * @var \XF\Entity\AddOn[]|null
	 */
	protected $installedAddOns;

	/**
	 * @var AddOn[]|null
	 */
	protected $allAddOns;

	public function __construct($addOnDir)
	{
		$this->addOnDir = rtrim($addOnDir, '/\\');
	}

	/**
	 * @return AddOn[]
	 */
	public function getAllAddOns()
	{
		if (!is_array($this->allAddOns))
		{
			$installed = $this->getInstalledEntities();
			$handlers = [];

			foreach ($this->getAllJsonInfo() AS $id => $info)
			{
				$existing = isset($installed[$id]) ? $installed[$id] : null;
				$handlers[$id] = $this->loadAddOnClass($existing, $info);
			}

			foreach ($installed AS $id => $existing)
			{
				if (!isset($handlers[$id]))
				{
					$handlers[$id] = $this->loadAddOnClass($existing);
				}
			}

			$handlers = \XF\Util\Arr::columnSort($handlers, 'title', 'strcasecmp');

			$this->allAddOns = $handlers;
		}

		return $this->allAddOns;
	}

	/**
	 * @return AddOn[]
	 */
	public function getInstalledAddOns()
	{
		$addOns = $this->getAllAddOns();
		foreach ($addOns AS $k => $addOn)
		{
			if (!$addOn->isInstalled())
			{
				unset($addOns[$k]);
			}
		}

		return $addOns;
	}

	public function getById($addOnId)
	{
		$addOnId = $this->coerceAddOnId($addOnId);

		$installedList = $this->getInstalledEntities();
		$installed = (isset($installedList[$addOnId]) ? $installedList[$addOnId] : null);
		$jsonInfo = $this->getAddOnJsonInfo($addOnId);

		if (!$installed && !$jsonInfo)
		{
			return null;
		}

		return $this->loadAddOnClass($installed, $jsonInfo);
	}

	protected function coerceAddOnId($addOnId)
	{
		$addOnIds = $this->getAvailableAddOnIds();

		$index = array_search(strtoupper($addOnId), array_map('strtoupper', $addOnIds));

		if ($index !== false)
		{
			return $addOnIds[$index];
		}

		return $addOnId;
	}

	/**
	 * @return \XF\Entity\AddOn[]
	 */
	protected function getInstalledEntities()
	{
		if (!is_array($this->installedAddOns))
		{
			$this->installedAddOns = \XF::em()->getFinder('XF:AddOn')->fetch()->toArray();
			unset($this->installedAddOns['XF']);
		}

		return $this->installedAddOns;
	}

	public function resetAddOnCache()
	{
		$this->installedAddOns = null;
		$this->allAddOns = null;
	}

	protected function isValidDir(\DirectoryIterator $entry)
	{
		/** @var \DirectoryIterator $entry */
		if (!$entry->isDir()
			|| $entry->isDot()
			|| !preg_match('/^[a-z0-9_]+$/i', $entry->getBasename())
		)
		{
			return false;
		}

		return true;
	}

	protected function prepareAddOnIdForPath($addOnId)
	{
		if (strpos($addOnId, '/') !== false)
		{
			$addOnId = str_replace('/', DIRECTORY_SEPARATOR, $addOnId);
		}
		return $addOnId;
	}

	public function isDirAddOnRoot(\DirectoryIterator $entry)
	{
		$ds = DIRECTORY_SEPARATOR;

		$pathname = $entry->getPathname();
		$addOnJson = "{$pathname}{$ds}addon.json";
		$outputDir = "{$pathname}{$ds}_output";
		$dataDir = "{$pathname}{$ds}_data";

		if (file_exists($addOnJson) || file_exists($outputDir) || file_exists($dataDir))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public function getAvailableAddOnIds()
	{
		$addOnIds = [];
		foreach (new \DirectoryIterator($this->addOnDir) AS $entry)
		{
			if (!$this->isValidDir($entry))
			{
				continue;
			}

			if ($this->isDirAddOnRoot($entry) || $entry->getBasename() == 'XF')
			{
				$addOnIds[] = $entry->getBasename();
			}
			else
			{
				$vendorPrefix = $entry->getBasename();
				foreach (new \DirectoryIterator($entry->getPathname()) AS $addOnDir)
				{
					if (!$this->isValidDir($addOnDir))
					{
						continue;
					}

					if ($this->isDirAddOnRoot($addOnDir))
					{
						$addOnIds[] = "$vendorPrefix/{$addOnDir->getBasename()}";
					}
				}
			}
		}
		return $addOnIds;
	}

	protected function getAllJsonInfo()
	{
		if (!is_array($this->jsonInfo))
		{
			$available = [];

			$addOnIds = $this->getAvailableAddOnIds();
			foreach ($addOnIds AS $addOnId)
			{
				$addOnIdDir = $this->prepareAddOnIdForPath($addOnId);
				foreach (new \DirectoryIterator($this->addOnDir . DIRECTORY_SEPARATOR . $addOnIdDir) AS $file)
				{
					$hasJson = ($file->isFile() && $file->getBasename() === 'addon.json');
					if ($hasJson)
					{
						$available[$addOnId] = $this->getAddOnJsonInfo($addOnId);
						break;
					}
				}
			}

			$this->jsonInfo = $available;
		}

		return $this->jsonInfo;
	}

	protected function getAddOnJsonInfo($addOnId)
	{
		if (!preg_match('#^[a-z][a-z0-9]*(/[a-z][a-z0-9]*)?$#i', $addOnId))
		{
			return null;
		}

		$file = $this->getAddOnJsonFile($addOnId);
		if (!file_exists($file) || !is_readable($file))
		{
			return null;
		}

		$json = json_decode(file_get_contents($file), true);
		$json['addon_id'] = $addOnId;
		return $json;
	}

	public function getAddOnPath($addOnId)
	{
		$addOnIdDir = $this->prepareAddOnIdForPath($addOnId);
		return $this->addOnDir . DIRECTORY_SEPARATOR . $addOnIdDir;
	}

	public function getAddOnJsonFile($addOnId)
	{
		return $this->getAddOnPath($addOnId) . DIRECTORY_SEPARATOR . 'addon.json';
	}

	/**
	 * @param $addOnOrId
	 *
	 * @return AddOn
	 */
	protected function loadAddOnClass($addOnOrId, array $jsonInfo = null)
	{
		if (!$addOnOrId)
		{
			$addOnOrId = isset($jsonInfo['addon_id']) ? $jsonInfo['addon_id'] : null;
		}
		if (!$addOnOrId)
		{
			throw new \InvalidArgumentException("Must provide an existing add-on or add-on JSON");
		}

		return new AddOn($addOnOrId);
	}
}