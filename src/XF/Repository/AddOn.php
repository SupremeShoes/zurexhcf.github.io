<?php

namespace XF\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class AddOn extends Repository
{
	/**
	 * @return Finder
	 */
	public function findAddOnsForList()
	{
		return $this->finder('XF:AddOn')->order('title');
	}

	/**
	 * @return Finder
	 */
	public function findActiveAddOnsForList()
	{
		return $this->findAddOnsForList()->where('active', 1);
	}

	public function getInstalledAddOnData()
	{
		return $this->db()->fetchAllKeyed("
			SELECT addon_id, version_id, active
			FROM xf_addon
		", 'addon_id');
	}

	public function getDefaultAddOnId()
	{
		$config = \XF::config();
		if ($config['development']['enabled'])
		{
			return $config['development']['defaultAddOn'];
		}

		return '';
	}

	public function canChangeAddOn()
	{
		return \XF::$debugMode;
	}

	public function getEnabledAddOns()
	{
		$registry = $this->app()->registry();
		return $registry['addOns'];
	}

	public function getDisabledAddOnsCache()
	{
		$registry = $this->app()->registry();
		return $registry['disabledAddOns'];
	}

	public function setDisabledAddOnsCache(array $cache)
	{
		$registry = $this->app()->registry();
		return $registry['disabledAddOns'] = $cache;
	}

	public function convertAddOnIdToUrlVersion($id)
	{
		return str_replace('/', '-', $id);
	}

	public function convertAddOnIdUrlVersionToBase($id)
	{
		return str_replace('-', '/', $id);
	}

	public function inferVersionStringFromId($versionId)
	{
		$versionString = '';
		$revVersionId = strrev($versionId);

		// Match our traditional version ID with an optional '90' prefix. Used in XFMG to offset legacy versioning.
		// The '90' prefix can be repeated up to four times if a longer ID is needed.
		// Has also been recommended to add-on devs as a convention for overcoming similar legacy version issues.
		// Note: The regex works backwards on the reversed version ID.
		if (preg_match('/^(\d)(\d)(\d{2})(\d{2})(\d{1,2})(?:09){0,4}$/', $revVersionId, $matches))
		{
			$matches = array_map('strrev', $matches);
			list($null, $build, $status, $patch, $minor, $major) = $matches;

			$versionString = intval($major) . '.' . intval($minor) . '.' . intval($patch);
			switch ($status)
			{
				case 1:
				case 2:
					$versionString .= ' Alpha';
					if ($status == 2)
					{
						$build += 10;
					}
					break;

				case 3:
				case 4:
					$versionString .= ' Beta';
					if ($status == 4)
					{
						$build += 10;
					}
					break;

				case 5:
				case 6:
					$versionString .= ' Release Candidate';
					if ($status == 6)
					{
						$build += 10;
					}
					break;

				case 7:
				case 8:
					if ($status == 8)
					{
						$build += 10;
					}
					if ($build > 0)
					{
						$versionString .= ".$build";
						$build = 0;
					}
					break;
				case 9:
					$versionString .= ' Patch Level';
					break;
			}

			if ($build)
			{
				$build = intval($build);
				$versionString .= " $build";
			}
		}

		return $versionString;
	}
}