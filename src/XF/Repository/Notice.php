<?php

namespace XF\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class Notice extends Repository
{
	/**
	 * @return Finder
	 */
	public function findNoticesForList()
	{
		return $this->finder('XF:Notice')->order(['display_order']);
	}

	public function getNoticeTypes()
	{
		return [
			'block' => \XF::phrase('block'),
			'scrolling' => \XF::phrase('scrolling'),
			'floating' => \XF::phrase('floating')
		];
	}

	public function getDismissedNoticesForUser(\XF\Entity\User $user)
	{
		return $this->db()->fetchAllKeyed('
			SELECT *
			FROM xf_notice_dismissed
			WHERE user_id = ?
		', 'notice_id', $user->user_id);
	}

	public function dismissNotice(\XF\Entity\Notice $notice, \XF\Entity\User $user)
	{
		$fields = [
			'notice_id' => $notice->notice_id,
			'user_id' => $user->user_id,
			'dismiss_date' => time()
		];
		return $this->db()->insert(
			'xf_notice_dismissed', $fields, false, false, 'IGNORE'
		);
	}

	public function restoreDismissedNotices(\XF\Entity\User $user)
	{
		return $this->db()->delete('xf_notice_dismissed', 'user_id = ?', $user->user_id);
	}

	public function resetNoticeDismissal(\XF\Entity\Notice $notice)
	{
		$this->db()->delete('xf_notice_dismissed', 'notice_id = ?', $notice->notice_id);
		\XF::registry()->set('noticesLastReset', time());
	}

	public function rebuildNoticeCache()
	{
		$cache = [];

		$notices = $this->finder('XF:Notice')
			->where('active', 1)
			->order('display_order')
			->keyedBy('notice_id');

		foreach ($notices->fetch() AS $noticeId => $notice)
		{
			$cache[$noticeId] = $notice->toArray(false);
		}

		\XF::registry()->set('notices', $cache);
		return $cache;
	}

	public function  getTotalGroupedNotices(array $groupedNotices)
	{
		$total = 0;

		foreach ($groupedNotices AS $notices)
		{
			$total += count($notices);
		}

		return $total;
	}
}