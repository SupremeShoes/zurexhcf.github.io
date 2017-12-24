<?php

namespace XF\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class UserUpgrade extends Repository
{
	/**
	 * @return Finder
	 */
	public function findUserUpgradesForList()
	{
		return $this->finder('XF:UserUpgrade')
			->setDefaultOrder('display_order');
	}

	/**
	 * @return Finder
	 */
	public function findActiveUserUpgradesForList()
	{
		$finder = $this->finder('XF:UserUpgradeActive');

		$finder
			->with(['Upgrade', 'User', 'PurchaseRequest.PaymentProfile'])
			->setDefaultOrder('start_date', 'DESC');

		return $finder;
	}

	/**
	 * @return Finder
	 */
	public function findExpiredUserUpgradesForList()
	{
		$finder = $this->finder('XF:UserUpgradeExpired');

		$finder
			->with(['Upgrade', 'User', 'PurchaseRequest.PaymentProfile'])
			->setDefaultOrder('end_date', 'DESC');

		return $finder;
	}

	public function getFilteredUserUpgradesForList()
	{
		$visitor = \XF::visitor();

		$finder = $this->findUserUpgradesForList()
			->with('Active|'
				. $visitor->user_id
				. '.PurchaseRequest'
			);

		$purchased = [];
		$upgrades = $finder->fetch();

		if ($visitor->user_id && $upgrades->count())
		{
			foreach ($upgrades AS $upgradeId => $upgrade)
			{
				if (isset($upgrade->Active[$visitor->user_id]))
				{
					// purchased
					$purchased[$upgradeId] = $upgrade;
					unset($upgrades[$upgradeId]); // can't buy again

					// remove any upgrades disabled by this
					foreach ($upgrade['disabled_upgrade_ids'] AS $disabledId)
					{
						unset($upgrades[$disabledId]);
					}
				}
				else if (!$upgrade->can_purchase)
				{
					unset($upgrades[$upgradeId]);
				}
			}
		}

		return [$upgrades, $purchased];
	}

	public function getUpgradeTitlePairs()
	{
		return $this->findUserUpgradesForList()->fetch()->pluck(function($e, $k)
		{
			return [$k, $e->title];
		});
	}

	public function getUserUpgradeCount()
	{
		return $this->finder('XF:UserUpgrade')
			->where('can_purchase', 1)
			->total();
	}

	public function rebuildUpgradeCount()
	{
		$cache = $this->getUserUpgradeCount();
		\XF::registry()->set('userUpgradeCount', $cache);
		return $cache;
	}

	public function downgradeExpiredUpgrades()
	{
		/** @var \XF\Entity\UserUpgradeActive[] $expired */
		$expired = $this->finder('XF:UserUpgradeActive')
			->with('User')
			->where('end_date', '<', \XF::$time)
			->where('end_date', '>', 0)
			->order('end_date')
			->fetch(1000);

		foreach ($expired AS $active)
		{
			$upgrade = $active->Upgrade;

			if ($upgrade->recurring)
			{
				// For recurring payments give a 24 hour grace period
				if ($active->end_date + 86400 >= \XF::$time)
				{
					continue;
				}
			}

			/** @var \XF\Service\User\Downgrade $downgradeService */
			$downgradeService = $this->app()->service('XF:User\Downgrade', $active->Upgrade, $active->User, $active);
			$downgradeService->downgrade();
		}
	}
}