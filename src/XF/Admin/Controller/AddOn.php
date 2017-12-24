<?php

namespace XF\Admin\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class AddOn extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertAdminPermission('addOn');
	}

	public function actionIndex()
	{
		$upgradeable = [];
		$installed = [];
		$installable = [];
		$legacy = [];
		$skippable = [];

		foreach ($this->getAddOnManager()->getAllAddOns() AS $id => $addOn)
		{
			if (isset($skippable[$id]))
			{
				continue;
			}

			if ($addOn->canUpgrade())
			{
				$skip = $addOn->legacy_addon_id;
				if ($skip)
				{
					$skippable[$skip] = $skip;
					unset($legacy[$skip]);
				}
				$upgradeable[$id] = $addOn;
			}
			else if ($addOn->isLegacy())
			{
				$legacy[$id] = $addOn;
			}
			else if ($addOn->isInstalled())
			{
				$installed[$id] = $addOn;
			}
			else if ($addOn->canInstall())
			{
				$installable[$id] = $addOn;
			}
		}

		$viewParams = [
			'upgradeable' => $upgradeable,
			'installed' => $installed,
			'installable' => $installable,
			'legacy' => $legacy,
			'total' => count($upgradeable) + count($installed) + count($installable) + count($legacy),
			'disabled' => $this->getAddOnRepo()->getDisabledAddOnsCache()
		];
		return $this->view('XF:AddOn\Listing', 'addon_list', $viewParams);
	}

	public function actionIcon(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		
		if (!$addOn->hasIcon())
		{
			return $this->notFound(\XF::phrase('this_add_on_does_not_have_icon_specified'));
		}

		$this->setResponseType('raw');

		$viewParams = [
			'icon' => $addOn->getIconPath()
		];
		return $this->view('XF:AddOn\Icon', '', $viewParams);
	}

	public function actionToggle(ParameterBag $params)
	{
		$this->assertValidCsrfToken($this->filter('t', 'str'));
		
		$addOn = $this->assertAddOnEntityExists($params->addon_id_url);
		if (!$addOn->canEdit())
		{
			return $this->noPermission();
		}

		$addOn->active = $addOn->active ? false : true;
		$addOn->save();

		return $this->redirect($this->buildLink('add-ons'));
	}

	public function actionMassToggle()
	{
		/** @var \XF\Repository\AddOn $addOnRepo */
		$addOnRepo = $this->repository('XF:AddOn');

		/** @var \XF\Mvc\Entity\ArrayCollection | \XF\Entity\AddOn[] $addOns */
		$addOns = $addOnRepo->findAddOnsForList()
			->where('addon_id', '<>', 'XF')
			->fetch();

		if ($this->filter('enable', 'bool'))
		{
			if ($this->isPost())
			{
				$toEnable = $this->filter('to_enable', 'array-str');

				$this->app->db()->beginTransaction();

				foreach ($toEnable AS $addOnId)
				{
					if (!isset($addOns[$addOnId]))
					{
						continue;
					}

					$addOn = $addOns[$addOnId];
					$addOn->active = 1;
					$addOn->save(true, false);
				}

				$this->app->db()->commit();

				$addOnRepo->setDisabledAddOnsCache([]);

				return $this->redirect($this->buildLink('add-ons'));
			}
			else
			{
				$viewParams = [
					'addOns' => $addOns,
					'disabled' => $addOnRepo->getDisabledAddOnsCache() ?: $addOns->toArray()
				];
				return $this->view('XF:AddOn\MassDisable', 'addon_mass_enable', $viewParams);
			}
		}
		else
		{
			$enabled = $addOnRepo->getEnabledAddOns();
			unset($enabled['XF']);

			if ($this->isPost())
			{
				$this->app->db()->beginTransaction();

				$cache = [];
				foreach (array_keys($enabled) AS $addOnId)
				{
					if (!isset($addOns[$addOnId]))
					{
						continue;
					}

					$addOn = $addOns[$addOnId];
					$addOn->active = 0;
					$addOn->save(true, false);

					$cache[] = $addOnId;
				}

				$this->app->db()->commit();

				$addOnRepo->setDisabledAddOnsCache($cache);

				return $this->redirect($this->buildLink('add-ons'));
			}
			else
			{
				if (!$enabled)
				{
					return $this->error(\XF::phrase('there_currently_no_add_ons_to_disable'));
				}

				$viewParams = [
					'addOns' => $addOns,
					'disabled' => $addOnRepo->getDisabledAddOnsCache()
				];
				return $this->view('XF:AddOn\MassDisable', 'addon_mass_disable', $viewParams);
			}
		}
	}

	public function actionControls(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);

		$json = $addOn->getJson();
		if (!isset($json['options']) || $json['options'] === null)
		{
			$relationFinder = $this->finder('XF:OptionGroupRelation');
			$relations = $relationFinder
				->with('Option', true)
				->with('OptionGroup', true)
				->where('Option.addon_id', $addOn->addon_id)
				->where('OptionGroup.debug_only', 0)
				->fetch();
			$hasOptions = ($relations->count() > 0);
		}
		else if (isset($json['options']) && strlen($json['options']))
		{
			$hasOptions = $json['options'];
		}
		else
		{
			$hasOptions = false;
		}

		$templates = $this->finder('XF:Template')
			->where('addon_id', $addOn->addon_id)
			->fetch()
			->groupBy('type');

		$hasPublicTemplates = isset($templates['public']);
		$hasEmailTemplates = isset($templates['email']);

		if (\XF::$debugMode)
		{
			$hasAdminTemplates = isset($templates['admin']);
		}
		else
		{
			$hasAdminTemplates = false;
		}

		$phraseFinder = $this->finder('XF:Phrase');
		$phrases = $phraseFinder->where('addon_id', $addOn->addon_id)->fetch();
		$hasPhrases = ($phrases->count() > 0);

		$viewParams = [
			'addOn' => $addOn,

			'hasOptions' => $hasOptions,
			'hasPublicTemplates' => $hasPublicTemplates,
			'hasEmailTemplates' => $hasEmailTemplates,
			'hasAdminTemplates' => $hasAdminTemplates,
			'hasPhrases' => $hasPhrases,

			'style' => $this->plugin('XF:Style')->getActiveEditStyle(),
			'masterStyle' => $this->repository('XF:Style')->getMasterStyle(),
			'language' => $this->plugin('XF:Language')->getActiveEditLanguage()
		];
		return $this->view('XF:AddOn\Controls', 'addon_controls', $viewParams);
	}

	public function actionOptions(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);

		list($groups, $options) = $this->repository('XF:Option')->getGroupsAndOptionsForAddOn($addOn->addon_id);

		$seenOptionIds = [];
		$groupedOptions = [];

		foreach ($options AS $idGroup => $option)
		{
			list($optionId, $groupId) = explode('-', $idGroup, 2);

			if (isset($seenOptionIds[$optionId]))
			{
				// ensure options which relate to multiple groups only appear once
				continue;
			}
			$seenOptionIds[$optionId] = true;

			$groupedOptions[$groupId][] = $option;
		}

		foreach ($groups AS $groupId => $group)
		{
			if (!isset($groupedOptions[$groupId]))
			{
				// Remove any empty groups
				unset($groups[$groupId]);
			}
		}

		$viewParams = [
			'addOn' => $addOn,
			'groups' => $groups,
			'groupedOptions' => $groupedOptions
		];
		return $this->view('XF:AddOn\Options', 'addon_options', $viewParams);
	}

	public function actionSyncChanges(ParameterBag $params)
	{
		$this->assertValidCsrfToken($this->filter('t', 'str'));

		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		if (!$addOn->hasPendingChanges() || !\XF::$debugMode)
		{
			return $this->noPermission();
		}

		$json = $addOn->getJson();

		if ($addOn->version_id > $json['version_id'])
		{
			return $this->error(\XF::phrase('downgrading_existing_add_on_is_not_supported'));
		}

		$addOn->syncFromJson();

		return $this->redirect($this->buildLink('add-ons'));
	}

	public function actionRebuild(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		if (!$addOn->canRebuild())
		{
			return $this->error(\XF::phrase('this_add_on_cannot_be_rebuilt'));
		}

		list ($warnings, $errors) = $this->getAddOnWarningsAndErrors($addOn);

		if ($this->isPost())
		{
			$addOn->preRebuild();

			$dataManager = $this->app->addOnDataManager();
			$dataManager->enqueueImportAddOnData($addOn);

			return $this->redirect($this->getFinalizeUrl($addOn, 'rebuild'));
		}
		else
		{
			$viewParams = [
				'addOn' => $addOn,
				'warnings' => $warnings,
				'errors' => $errors
			];
			return $this->view('XF:AddOn\Rebuild', 'addon_rebuild', $viewParams);
		}
	}

	public function actionInstall(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		if (!$addOn->canInstall())
		{
			return $this->error(\XF::phrase('this_add_on_cannot_be_installed'));
		}

		list ($warnings, $errors) = $this->getAddOnWarningsAndErrors($addOn);

		if ($this->isPost())
		{
			// this applies to add-on changes as well, so we want to ensure errors here are shown and logged
			\XF::app()->error()->setIgnorePendingUpgrade(true);

			$input = $this->filter([
				'_xfProcessing' => 'bool',
				'params' => 'json-array',
				'count' => 'uint',
				'finished' => 'bool'
			]);

			if ($input['finished'])
			{
				$dataManager = $this->app->addOnDataManager();
				$dataManager->enqueueImportAddOnData($addOn);

				return $this->redirect($this->getFinalizeUrl($addOn, 'install'));
			}
			else
			{
				$setup = $addOn->getSetup();

				if ($input['_xfProcessing'])
				{
					$result = $setup ? $setup->install($input['params']) : null;
				}
				else
				{
					$result = null;
					$addOn->preInstall();
				}

				return $this->displayAddOnActionStep(
					$addOn, $result, \XF::phrase('installing'), 'add-ons/install', $input['count']
				);
			}
		}
		else
		{
			$viewParams = [
				'addOn' => $addOn,
				'warnings' => $warnings,
				'errors' => $errors
			];
			return $this->view('XF:AddOn\Install', 'addon_install', $viewParams);
		}
	}

	public function actionUpgrade(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		if (!$addOn->canUpgrade())
		{
			return $this->error(\XF::phrase('this_add_on_cannot_be_upgraded'));
		}

		list ($warnings, $errors) = $this->getAddOnWarningsAndErrors($addOn);

		if ($this->isPost())
		{
			// this applies to add-on changes as well, so we want to ensure errors here are shown and logged
			\XF::app()->error()->setIgnorePendingUpgrade(true);

			$input = $this->filter([
				'_xfProcessing' => 'bool',
				'params' => 'json-array',
				'count' => 'uint',
				'finished' => 'bool'
			]);

			if ($input['finished'])
			{
				$dataManager = $this->app->addOnDataManager();
				$dataManager->enqueueImportAddOnData($addOn);

				return $this->redirect($this->getFinalizeUrl($addOn, 'upgrade'));
			}
			else
			{
				$setup = $addOn->getSetup();

				if ($input['_xfProcessing'])
				{
					$result = $setup ? $setup->upgrade($input['params']) : null;
				}
				else
				{
					$result = null;
					$addOn->preUpgrade();
				}

				return $this->displayAddOnActionStep(
					$addOn, $result, \XF::phrase('upgrading'), 'add-ons/upgrade', $input['count']
				);
			}
		}
		else
		{
			$viewParams = [
				'addOn' => $addOn,
				'warnings' => $warnings,
				'errors' => $errors
			];
			return $this->view('XF:AddOn\Upgrade', 'addon_upgrade', $viewParams);
		}
	}

	public function actionUninstall(ParameterBag $params)
	{
		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		if (!$addOn->canUninstall())
		{
			return $this->error(\XF::phrase('this_add_on_cannot_be_uninstalled_like_files_missing'));
		}

		if ($this->isPost())
		{
			// this applies to add-on changes as well, so we want to ensure errors here are shown and logged
			\XF::app()->error()->setIgnorePendingUpgrade(true);

			$input = $this->filter([
				'_xfProcessing' => 'bool',
				'params' => 'json-array',
				'count' => 'uint',
				'finished' => 'bool'
			]);

			if ($input['finished'])
			{
				$addOn->getInstalledAddOn()->delete();
				return $this->redirect($this->buildLink('add-ons'));
			}
			else
			{
				$setup = $addOn->getSetup();

				if ($input['_xfProcessing'])
				{
					$result = $setup ? $setup->uninstall($input['params']) : null;
				}
				else
				{
					$result = null;
					$addOn->preUninstall();
				}

				return $this->displayAddOnActionStep(
					$addOn, $result, \XF::phrase('uninstalling'), 'add-ons/uninstall', $input['count']
				);
			}
		}
		else
		{
			$viewParams = [
				'addOn' => $addOn
			];
			return $this->view('XF:AddOn\Uninstall', 'addon_uninstall', $viewParams);
		}
	}

	protected function getFinalizeUrl(\XF\AddOn\AddOn $addOn, $action)
	{
		return $this->buildLink('add-ons/finalize', $addOn, [
			't' => $this->app['csrf.token'],
			'a' => $action
		]);
	}

	public function actionFinalize(ParameterBag $params)
	{
		$this->assertValidCsrfToken($this->filter('t', 'str'));

		$addOn = $this->assertAddOnAvailable($params->addon_id_url);
		$action = $this->filter('a', 'str');

		// TODO: check whether the the import job is still enqueued. If so, it won't have completed successfully.

		$installed = $addOn->getInstalledAddOn();
		if ($installed)
		{
			// this is a sanity check, it shouldn't happen
			$installed->is_processing = false;
			$installed->save();
		}

		$redirect = $this->buildLink('add-ons');
		$stateChanges = [];

		if ($action == 'upgrade')
		{
			$addOn->postUpgrade($stateChanges);
		}
		else if ($action == 'install')
		{
			$addOn->postInstall($stateChanges);
		}
		else if ($action == 'rebuild')
		{
			$addOn->postRebuild();
		}

		if (!empty($stateChanges['redirect']))
		{
			$redirect = $stateChanges['redirect'];
		}

		return $this->redirect($redirect);
	}

	protected function displayAddOnActionStep(
		\XF\AddOn\AddOn $addOn,
		\XF\AddOn\StepResult $result = null,
		$actionText, $actionRoute, $count = 0
	)
	{
		$isProcessing = $this->filter('_xfProcessing', 'bool');

		if (!$result)
		{
			if ($isProcessing)
			{
				$finished = true;
			}
			else
			{
				$finished = false;
				$isProcessing = true;
			}

			$params = [];
		}
		else
		{
			$finished = false;
			$params = $result->params;
			$params['step'] = $result->step;
			if ($result->version)
			{
				$params['version_id'] = $result->version;
			}
		}

		$viewParams = [
			'addOn' => $addOn,
			'actionText' => $actionText,
			'actionRoute' => $actionRoute,

			'isProcessing' => $isProcessing,

			'finished' => $finished,
			'params' => $params,
			'count' => $count + 1
		];
		return $this->view('XF:AddOn\RunStep', 'addon_run_step', $viewParams);
	}

	protected function getAddOnWarningsAndErrors(\XF\AddOn\AddOn $addOn)
	{
		\XF\Util\Php::resetOpcache();

		$addOn->checkRequirements($errors, $warnings);
		$addOn->passesHealthCheck($missing, $inconsistent);

		$devMode = $this->app->config('development')['enabled'];

		if ($missing)
		{
			if (count($missing) > 5)
			{
				$errors[] = \XF::phrase('this_add_on_cannot_be_installed_because_x_files_are_missing', ['missing' => count($missing)]);
			}
			else
			{
				$errors[] = \XF::phrase('this_add_on_cannot_be_installed_because_following_files_are_missing_x', ['missing' => implode(', ', $missing)]);
			}
		}
		if ($inconsistent)
		{
			if (count($inconsistent) > 5)
			{
				$warnings[] = \XF::phrase('this_add_on_contains_x_files_which_have_unexpected_contents', ['inconsistent' => count($inconsistent)]);
			}
			else
			{
				$warnings[] = \XF::phrase('this_add_on_contains_following_files_which_have_unexpected_contents_x', ['inconsistent' => implode(', ', $inconsistent)]);
			}
		}
		if (!$addOn->hasHashes() && !$devMode)
		{
			$warnings[] = \XF::phrase('hashes_file_for_this_add_on_missing');
		}
		if ($addOn->isDevOutputAvailable() && $devMode)
		{
			$warnings[] = \XF::phrase('development_mode_is_enabled_and_dev_output_is_available');
		}
		else if (\XF::$debugMode && !$devMode)
		{
			$warnings[] = \XF::phrase('debug_mode_is_enabled_changes_will_be_overwritten');
		}

		return [$warnings, $errors];
	}

	/**
	 * @param string $id
	 * @param array|string|null $with
	 * @param null|string $phraseKey
	 *
	 * @return \XF\Entity\AddOn
	 */
	protected function assertAddOnEntityExists($id, $with = null, $phraseKey = null)
	{
		$id = $this->getAddOnRepo()->convertAddOnIdUrlVersionToBase($id);

		/** @var \XF\Entity\AddOn $addOnEnt */
		$addOnEnt = $this->assertRecordExists('XF:AddOn', $id, $with, $phraseKey);
		return $addOnEnt;
	}

	/**
	 * @param string $id
	 *
	 * @return \XF\AddOn\AddOn
	 */
	protected function assertAddOnAvailable($id)
	{
		$id = $this->getAddOnRepo()->convertAddOnIdUrlVersionToBase($id);

		$addOn = $this->getAddOnManager()->getById($id);
		if (!$addOn)
		{
			throw $this->exception($this->error(\XF::phrase('requested_page_not_found'), 404));
		}

		return $addOn;
	}

	/**
	 * @return \XF\AddOn\Manager
	 */
	protected function getAddOnManager()
	{
		return $this->app->addOnManager();
	}

	/**
	 * @return \XF\Repository\AddOn
	 */
	protected function getAddOnRepo()
	{
		return $this->repository('XF:AddOn');
	}

	protected function postDispatchController($action, ParameterBag $params, AbstractReply &$reply)
	{
		$reply->setPageParam('breadcrumbPath', 'addOns');
	}
}