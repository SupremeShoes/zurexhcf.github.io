<?php

namespace XF\AddOn;

class AddOn implements \ArrayAccess
{
	/**
	 * @var \XF\Entity\AddOn
	 */
	protected $installedAddOn;

	/**
	 * @var \XF\AddOn\AbstractSetup
	 */
	protected $setup;

	protected $addOnId;
	protected $legacyAddOnId;

	protected $addOnDir;
	protected $buildDir;
	protected $dataDir;
	protected $filesDir;
	protected $outputDir;
	protected $releasesDir;

	protected $jsonPath;
	protected $json = [];

	protected $buildJsonPath;
	protected $buildJson = [];

	protected $missingFiles = [];

	public function __construct($addOnOrId)
	{
		if ($addOnOrId instanceof \XF\Entity\AddOn)
		{
			$this->installedAddOn = $addOnOrId;
			$this->addOnId = $addOnOrId->addon_id;
		}
		else
		{
			$this->addOnId = $addOnOrId;
		}

		$ds = DIRECTORY_SEPARATOR;

		$this->addOnDir = \XF::getAddOnDirectory() . $ds . $this->prepareAddOnIdForPath();
		$this->buildDir = $this->addOnDir . $ds . '_build';
		$this->dataDir = $this->addOnDir . $ds . '_data';
		$this->filesDir = $this->addOnDir . $ds . '_files';
		$this->outputDir = $this->addOnDir . $ds . '_output';
		$this->releasesDir = $this->addOnDir . $ds. '_releases';

		$this->jsonPath = $this->addOnDir . $ds . 'addon.json';
		if (file_exists($this->jsonPath))
		{
			$this->json = $this->prepareJsonFile(
				json_decode(file_get_contents($this->jsonPath), true)?: []
			);
		}
		else
		{
			$this->missingFiles[] = 'addon.json';
		}

		if (!$this->installedAddOn && !empty($this->json['legacy_addon_id']))
		{
			$this->legacyAddOnId = $this->json['legacy_addon_id'];
			$this->installedAddOn = \XF::em()->find('XF:AddOn', $this->legacyAddOnId);
		}

		$this->buildJsonPath = $this->addOnDir . $ds . 'build.json';
		$this->buildJson = [
			'additional_files' => [],
			'minify' => [],
			'rollup' => [],
			'exec' => []
		];
		if (file_exists($this->buildJsonPath))
		{
			$buildJson = @json_decode(file_get_contents($this->buildJsonPath), true);

			$this->buildJson = array_replace(
				$this->buildJson, $buildJson ?: []
			);
		}
	}

	public function getInstalledAddOn()
	{
		return $this->installedAddOn;
	}

	public function getAddOnId()
	{
		return $this->addOnId;
	}

	public function getAddOnIdUrl()
	{
		return \XF::app()->repository('XF:AddOn')->convertAddOnIdToUrlVersion($this->getAddOnId());
	}

	public function prepareVersionForFilename()
	{
		$versionString = preg_replace('/[^a-z0-9-_. ]/i', '', $this->version_string);
		return trim(preg_replace('/\s{2,}/', ' ', $versionString));
	}

	public function prepareAddOnIdForFilename()
	{
		if (strpos($this->addOnId, '/') !== false)
		{
			return str_replace('/', '-', $this->addOnId);
		}
		else
		{
			return $this->addOnId;
		}
	}

	public function prepareAddOnIdForPath()
	{
		if (strpos($this->addOnId, '/') !== false)
		{
			return str_replace('/', DIRECTORY_SEPARATOR, $this->addOnId);
		}
		else
		{
			return $this->addOnId;
		}
	}

	public function prepareAddOnIdForClass()
	{
		if (strpos($this->addOnId, '/') !== false)
		{
			return str_replace('/', '\\', $this->addOnId);
		}
		else
		{
			return $this->addOnId;
		}
	}

	public function getMissingFiles()
	{
		return $this->missingFiles;
	}

	public function getAddOnDirectory()
	{
		return $this->addOnDir;
	}

	public function getBuildDirectory()
	{
		return $this->buildDir;
	}

	public function getDataDirectory()
	{
		return $this->dataDir;
	}

	public function getFilesDirectory()
	{
		return $this->filesDir;
	}

	public function getReleasesDirectory()
	{
		return $this->releasesDir;
	}

	public function getReleasePath()
	{
		$addOnId = $this->prepareAddOnIdForFilename();
		$versionString = $this->prepareVersionForFilename();

		return $this->releasesDir . DIRECTORY_SEPARATOR . "$addOnId-$versionString.zip";
	}

	public function getJsonPath()
	{
		return $this->jsonPath;
	}

	public function getJson()
	{
		return $this->json;
	}

	public function getJsonHash()
	{
		return \XF\Util\Hash::hashTextFile($this->jsonPath, 'sha256');
	}

	public function prepareJsonFile(array $json = [])
	{
		return array_replace([
			'legacy_addon_id' => '',
			'title' => '',
			'description' => '',
			'version_id' => 0,
			'version_string' => '',
			'dev' => '',
			'dev_url' => '',
			'faq_url' => '',
			'support_url' => '',
			'extra_urls' => [],
			'require' => [],
			'icon' => ''
		], $json);
	}

	public function getBuildJsonPath()
	{
		return $this->buildJsonPath;
	}

	public function getBuildJson()
	{
		return $this->buildJson;
	}

	public function getIconPath()
	{
		return $this->addOnDir . DIRECTORY_SEPARATOR . $this->icon;
	}

	public function getHashesPath()
	{
		return $this->addOnDir . DIRECTORY_SEPARATOR . 'hashes.json';
	}

	public function getHashes()
	{
		return json_decode(file_get_contents($this->getHashesPath()), true);
	}

	public function getSetupPath()
	{
		return $this->addOnDir . DIRECTORY_SEPARATOR . 'Setup.php';
	}

	/**
	 * @return AbstractSetup|false
	 */
	public function getSetup()
	{
		if ($this->setup === null)
		{
			$this->setup = false;

			if (file_exists($this->getSetupPath()))
			{
				$class = $this->prepareAddOnIdForClass() . '\\Setup';
				if (class_exists($class))
				{
					$setup = new $class($this, \XF::app());
					if ($setup instanceof AbstractSetup)
					{
						$this->setup = $setup;
					}
				}
			}
		}

		return $this->setup;
	}

	public function isInstalled()
	{
		if ($this->installedAddOn === null)
		{
			return false;
		}

		$lastAction = $this->installedAddOn->getLastActionStep('install');
		if ($lastAction !== null)
		{
			// we haven't completed the install steps yet, don't consider as installed
			return false;
		}

		return true;
	}

	public function isActive()
	{
		return $this->isInstalled()
			&& $this->installedAddOn->active;
	}

	public function isLegacy()
	{
		return $this->isInstalled()
			&& $this->installedAddOn->is_legacy;
	}

	public function isDevOutputAvailable()
	{
		return \XF::app()->developmentOutput()->isAddOnOutputAvailable($this->getAddOnId());
	}

	public function hasMissingFiles()
	{
		return count($this->missingFiles) > 0;
	}

	public function hasPendingChanges()
	{
		return $this->isInstalled()
			&& !$this->isLegacy()
			&& (!$this->isFileVersionValid()
				|| $this->isJsonHashChanged()
			);
	}

	public function hasFaIcon()
	{
		if ($this->icon && !$this->hasIcon() && strpos($this->icon, 'fa-') === 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public function hasIcon()
	{
		if ($this->icon && file_exists($this->getIconPath()))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public function hasHashes()
	{
		return file_exists($this->getHashesPath());
	}

	public function hasSetup()
	{
		return file_exists($this->getSetupPath());
	}

	public function isAvailable()
	{
		return count($this->missingFiles) == 0;
	}

	public function isFileVersionValid()
	{
		return $this->isInstalled()
			&& $this->isAvailable()
			&& $this->installedAddOn->version_id == $this->json['version_id'];
	}

	public function isJsonHashChanged()
	{
		return (
			$this->isAvailable() &&
			$this->getJsonHash() !== $this->installedAddOn->json_hash
		);
	}

	public function isJsonVersionNewer()
	{
		return ($this->json['version_id'] > $this->installedAddOn->version_id);
	}

	public function getJsonVersion()
	{
		return [
			'version_id' => $this->json['version_id'],
			'version_string' => $this->json['version_string']
		];
	}

	public function canUpgrade()
	{
		return $this->isInstalled()
			&& $this->isJsonHashChanged()
			&& $this->isJsonVersionNewer()
			&& $this->canEdit();
	}

	public function checkRequirements(&$errors = [], &$warnings = [])
	{
		$addOns = \XF::app()->container('addon.cache');
		foreach ((array)$this->require AS $productKey => $requirement)
		{
			if (!is_array($requirement))
			{
				continue;
			}
			list ($version, $product) = $requirement;

			$enabled = false;
			$versionValid = false;

			if (strpos($productKey, 'php-ext') === 0)
			{
				$parts = explode('/', $productKey, 2);
				if (isset($parts[1]))
				{
					$enabled = phpversion($parts[1]) !== false;
					$versionValid = ($version === '*') || (version_compare(phpversion($parts[1]), $version) === 1);
				}
			}
			elseif (strpos($productKey, 'php') === 0)
			{
				$enabled = true;
				$versionValid = (version_compare(phpversion(), $version) === 1);
			}
			elseif (strpos($productKey, 'mysql') === 0)
			{
				$mySqlVersion = \XF::db()->getServerVersion();
				if ($mySqlVersion)
				{
					$enabled = true;
					$versionValid = (version_compare(strtolower($mySqlVersion), $version) === 1);
				}
			}
			else
			{
				$enabled = isset($addOns[$productKey]);
				$versionValid = ($version === '*' || ($enabled && $addOns[$productKey] >= $version));
			}

			if (!$enabled || !$versionValid)
			{
				$errors[] = "{$this->json['title']} requires $product.";
			}
		}

		if ($this->hasSetup())
		{
			$setupClass = '\\' . $this->prepareAddOnIdForClass() . '\\Setup';

			/** @var AbstractSetup $setup */
			$setup = new $setupClass($this, \XF::app());

			if ($setup instanceof CheckRequirementsInterface)
			{
				$e = new \LogicException('Usage of XF\AddOn\CheckRequirementsInterface is deprecated and will be removed in the future.');
				\XF::logException($e);
			}

			$setup->checkRequirements($errors, $warnings);
		}
	}

	public function passesHealthCheck(&$missing = [], &$inconsistent = [])
	{
		$missing = [];
		$inconsistent = [];

		if (!$this->hasHashes())
		{
			return;
		}

		$rootPath = \XF::getRootDirectory();
		foreach ((array)$this->getHashes() AS $path => $hash)
		{
			$fullPath = $rootPath . DIRECTORY_SEPARATOR . $path;

			if (!file_exists($fullPath))
			{
				$missing[] = $path;
				continue;
			}

			if (\XF\Util\Hash::hashTextFile($fullPath, 'sha256') !== $hash)
			{
				$inconsistent[] = $path;
				continue;
			}
		}
	}

	public function updatePendingAction($action, $step)
	{
		if ($this->installedAddOn)
		{
			$this->installedAddOn->fastUpdate('last_pending_action', "$action:$step");
		}
	}

	public function resetPendingAction()
	{
		if ($this->installedAddOn)
		{
			$this->installedAddOn->fastUpdate('last_pending_action', null);
		}
	}

	public function preInstall()
	{
		if (!$this->installedAddOn)
		{
			$json = $this->getJson();

			$installed = \XF::em()->create('XF:AddOn');
			$installed->bulkSet([
				'addon_id' => $this->getAddOnId(),
				'title' => $json['title'],
				'version_string' => $json['version_string'],
				'version_id' => $json['version_id'],
				'active' => true,
				'is_processing' => true,
				'json_hash' => $this->getJsonHash(),
				'last_pending_action' => 'install:0'
			]);
			$installed->save();

			$this->installedAddOn = $installed;
		}
	}

	public function postInstall(array &$stateChanges)
	{
		$setup = $this->getSetup();
		if ($setup)
		{
			$setup->postInstall($stateChanges);
		}

		$this->resetPendingAction();
	}

	public function preUpgrade()
	{
		$installed = $this->installedAddOn;
		if (!$installed)
		{
			throw new \LogicException("Add-on is not installed");
		}

		$isLegacy = $this->isLegacy();
		if ($isLegacy)
		{
			if ($this->legacyAddOnId)
			{
				// Installed add-on is the legacy ID in this case, update it to the new add-on ID.
				$installed->addon_id = $this->addOnId;
			}

			$installed->is_legacy = false;
		}

		// as we will be importing data after this, we don't need to bother doing this now
		$installed->setOption('rebuild_active_change', false);
		$installed->active = true;
		$installed->is_processing = true;

		$installed->saveIfChanged();
		$installed->resetOption('rebuild_active_change');
	}

	public function postUpgrade(array &$stateChanges)
	{
		$setup = $this->getSetup();
		if ($setup)
		{
			$installed = $this->installedAddOn;
			$previousVersion = $installed ? $installed->version_id : null;
			$setup->postUpgrade($previousVersion, $stateChanges);
		}

		$this->resetPendingAction();
		$this->syncFromJson();
	}

	public function preUninstall()
	{
		$installed = $this->installedAddOn;
		if (!$installed)
		{
			throw new \LogicException("Add-on is not installed");
		}

		$installed->is_processing = true;
		$installed->saveIfChanged();
	}

	public function preRebuild()
	{
		$installed = $this->installedAddOn;
		if (!$installed)
		{
			throw new \LogicException("Add-on is not installed");
		}

		$installed->is_processing = true;
		$installed->saveIfChanged();
	}

	public function postRebuild()
	{
		$this->syncFromJson();
	}

	public function onActiveChange($newActive, array &$jobList)
	{
		$setup = $this->getSetup();
		if ($setup)
		{
			$setup->onActiveChange($newActive, $jobList);
		}
	}

	public function postDataImport()
	{
		// all data will be imported, re-enable this so postX methods will have access to their methods
		$installed = $this->installedAddOn;
		if (!$installed)
		{
			throw new \LogicException("Add-on is not installed");
		}

		$installed->is_processing = false;
		$installed->saveIfChanged();

		\XF::repository('XF:Option')->updateOption('jsLastUpdate', \XF::$time);
	}

	public function syncFromJson($extraChanges = [])
	{
		$installed = $this->installedAddOn;
		if (!$installed)
		{
			throw new \LogicException("Add-on is not installed");
		}

		$json = $this->getJson();

		$installed->bulkSet($extraChanges);
		$installed->bulkSet([
			'title' => $json['title'],
			'version_string' => $json['version_string'],
			'version_id' => $json['version_id'],
			'json_hash' => $this->getJsonHash()
		]);
		$installed->saveIfChanged();
	}

	public function canInstall()
	{
		return !$this->isInstalled()
			&& $this->isAvailable();
	}

	public function canUninstall()
	{
		return $this->isInstalled()
			&& $this->canEdit()
			&& (
				!$this->json || ($this->isFileVersionValid() && !$this->hasMissingFiles())
			);
	}

	public function canEdit()
	{
		return $this->installedAddOn
			&& $this->installedAddOn->canEdit();
	}

	public function canRebuild()
	{
		// as long as the JSON is for the correct version, we're ok
		return $this->isInstalled() && $this->isFileVersionValid() && $this->canEdit();
	}

	public function offsetGet($key)
	{
		switch ($key)
		{
			case 'addon': return $this->getInstalledAddOn();
			case 'addon_id': return $this->getAddOnId();
			case 'addon_id_url': return $this->getAddOnIdUrl();
			case 'json': return $this->getJson();
			case 'json_version_id': return $this->json['version_id'];
			case 'json_version_string': return $this->json['version_string'];
			case 'missing_files': return $this->getMissingFiles();
			case 'additional_files': return $this->getBuildJson()['additional_files'];
			case 'legacy_addon_id': return $this->legacyAddOnId;
		}

		if ($this->installedAddOn && isset($this->installedAddOn[$key]))
		{
			return $this->installedAddOn[$key];
		}

		if (isset($this->json[$key]))
		{
			return $this->json[$key];
		}

		return null;
	}

	public function __get($key)
	{
		return $this->offsetGet($key);
	}

	public function offsetExists($key)
	{
		return $this->offsetGet($key) !== null;
	}

	public function offsetSet($key, $value)
	{
		throw new \LogicException("Cannot write to an add-on class");
	}

	public function offsetUnset($key)
	{
		throw new \LogicException("Cannot write to an add-on class");
	}
}