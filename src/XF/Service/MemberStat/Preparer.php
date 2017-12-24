<?php

namespace XF\Service\MemberStat;

use XF\Entity\MemberStat;

class Preparer extends \XF\Service\AbstractService
{
	/**
	 * @var MemberStat
	 */
	protected $memberStat;

	/**
	 * @var \XF\Searcher\User
	 */
	protected $userSearcher;

	protected $validOnly = true;

	public function __construct(\XF\App $app, MemberStat $memberStat)
	{
		parent::__construct($app);
		$this->setMemberStat($memberStat);
		$this->setUserSearcher();
	}

	public function setMemberStat(MemberStat $memberStat)
	{
		$this->memberStat = $memberStat;
	}

	/**
	 * @return MemberStat
	 */
	public function getMemberStat()
	{
		return $this->memberStat;
	}

	public function setUserSearcher()
	{
		$this->userSearcher = $this->app->searcher('XF:User');
	}

	/**
	 * @return \XF\Searcher\User
	 */
	public function getUserSearcher()
	{
		return $this->userSearcher;
	}

	public function setValidOnly($validOnly)
	{
		$this->validOnly = $validOnly;
	}

	public function cache()
	{
		$memberStat = $this->memberStat;
		$results = $this->getResultsData();

		$memberStat->cache_expiry = \XF::$time + ($memberStat->cache_lifetime * 60);
		$memberStat->cache_results = $results;

		$memberStat->save();

		return $results;
	}

	public function getResults($forOverview = false)
	{
		$memberStat = $this->memberStat;

		if ($forOverview && !$memberStat->overview_display)
		{
			return [];
		}

		$results = $memberStat->cache_results;

		if (!is_array($results))
		{
			if ($memberStat->cache_lifetime > 0)
			{
				// the cache is null, but the stat is cacheable so cache it
				$results = $this->cache();
			}
			else
			{
				// no cache defined so just get the data
				$results = $this->getResultsData();
			}
		}
		else
		{
			// allow cache to expire + 50% before re-caching
			$gracePeriod = $memberStat->cache_lifetime * 60 / 2;
			if (\XF::$time > $memberStat->cache_expiry + $gracePeriod && !$forOverview)
			{
				$results = $this->cache();
			}
		}

		return $results;
	}

	public function setCriteria($criteria)
	{
		if (!$criteria)
		{
			return;
		}
		$this->userSearcher->setCriteria($criteria);
	}

	public function applyCallback($class, $method, \XF\Mvc\Entity\Finder $finder, $additionalParams = [])
	{
		if (!$class || !$method)
		{
			return null;
		}

		$memberStat = $this->memberStat;

		$class = $this->app->extendClass($class);
		if (\XF\Util\Php::validateCallbackPhrased($class, $method))
		{
			$results = call_user_func_array([$class, $method], [$memberStat, $finder] + $additionalParams);
			return $results;
		}
		else
		{
			return null;
		}
	}

	public function setOrder($order, $direction)
	{
		if (!$order || !$direction)
		{
			$order = 'message_count';
			$direction = 'desc';
		}
		$this->userSearcher->setOrder($order, $direction);
	}

	protected function prepareCacheResults($order, array $cacheResults)
	{
		switch ($order)
		{
			case 'like_count':
			case 'message_count':
			case 'trophy_points':
				$cacheResults = array_map(function($value)
				{
					return \XF::language()->numberFormat($value);
				}, $cacheResults);
				break;

			case 'register_date':
			case 'last_activity':
				$cacheResults = array_map(function($value)
				{
					return \XF::language()->dateTime($value);
				}, $cacheResults);
				break;
		}

		return $cacheResults;
	}

	protected function getResultsData()
	{
		$memberStat = $this->memberStat;
		$searcher = $this->userSearcher;

		$this->setCriteria($memberStat->criteria);
		$this->setOrder($memberStat->sort_order, $memberStat->sort_direction);

		$finder = $searcher->getFinder();
		if ($this->validOnly)
		{
			$finder->isValidUser();
		}

		$results = $this->applyCallback($memberStat->callback_class, $memberStat->callback_method, $finder);

		if (!is_array($results)) // callback may have fetched the data already, if not, we'll do it here
		{
			if ($memberStat->show_value)
			{
				$valueField = $memberStat->sort_order;
				$finder->where($valueField, '>', 0);
			}
			else
			{
				$valueField = null;
			}

			$results = $finder->fetch($memberStat->user_limit * 3);

			if ($valueField)
			{
				$results = $results->pluckNamed($valueField, 'user_id');
				$results = $this->prepareCacheResults($valueField, $results);
			}
			else
			{
				$results = $results->pluck(function(\XF\Entity\User $user)
				{
					return [$user->user_id, null];
				}, false);
			}
		}

		return $results;
	}
}