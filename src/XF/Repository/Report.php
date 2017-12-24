<?php

namespace XF\Repository;

use XF\Mvc\Entity\Repository;
use XF\Mvc\Entity\Finder;

class Report extends Repository
{
	protected $handlerCache = [];

	/**
	 * @param array $state
	 *
	 * @return \XF\Finder\Report
	 */
	public function findReports($state = ['open', 'assigned'], $timeFrame = null)
	{
		$finder = $this->finder('XF:Report');

		$finder->inTimeFrame($timeFrame)
			->order('last_modified_date', 'desc');

		if ($state)
		{
			$finder->where('report_state', $state);
		}

		return $finder;
	}

	/**
	 * @param $type
	 * @param bool $throw
	 *
	 * @return \XF\Report\AbstractHandler|null
	 */
	public function getReportHandler($type, $throw = false)
	{
		if (isset($this->handlerCache[$type]))
		{
			return $this->handlerCache[$type];
		}

		$handlerClass = \XF::app()->getContentTypeFieldValue($type, 'report_handler_class');
		if (!$handlerClass)
		{
			if ($throw)
			{
				throw new \InvalidArgumentException("No report handler for '$type'");
			}
			return null;
		}

		if (!class_exists($handlerClass))
		{
			if ($throw)
			{
				throw new \InvalidArgumentException("Report handler for '$type' does not exist: $handlerClass");
			}
			return null;
		}

		$handlerClass = \XF::extendClass($handlerClass);
		$handler = new $handlerClass($type);

		$this->handlerCache[$type] = $handler;

		return $handler;
	}

	public function getModeratorsWhoCanHandleReport(\XF\Entity\Report $report)
	{
		$handler = $report->getHandler();

		/** @var \XF\Repository\Moderator $moderatorRepo */
		$moderatorRepo = $this->repository('XF:Moderator');

		$moderators = $moderatorRepo->findModeratorsForList()->fetch();

		if ($moderators->count())
		{
			foreach ($moderators AS $id => $moderator)
			{
				$canView = \XF::asVisitor($moderator->User,
					function() use ($handler, $report) { return $handler->canView($report); }
				);
				if (!$canView)
				{
					unset($moderators[$id]);
				}
			}
		}

		return $moderators;
	}

	/**
	 * @param \XF\Mvc\Entity\ArrayCollection $reports
	 */
	public function filterViewableReports($reports)
	{
		return $reports->filter(function(\XF\Entity\Report $report)
		{
			$handler = $this->getReportHandler($report->content_type);
			if (!$handler)
			{
				return false;
			}
			return $handler->canView($report);
		});
	}

	/**
	 * @param \XF\Entity\Report[] $reports
	 */
	public function addContentToReports($reports)
	{
		$contentMap = [];
		foreach ($reports AS $key => $report)
		{
			$contentType = $report->content_type;
			if (!isset($contentMap[$contentType]))
			{
				$contentMap[$contentType] = [];
			}
			$contentMap[$contentType][$key] = $report->content_id;
		}

		foreach ($contentMap AS $contentType => $contentIds)
		{
			$handler = $this->getReportHandler($contentType);
			if (!$handler)
			{
				continue;
			}
			$data = $handler->getContent($contentIds);
			foreach ($contentIds AS $reportId => $contentId)
			{
				$content = isset($data[$contentId]) ? $data[$contentId] : null;
				$reports[$reportId]->setContent($content);
			}
		}
	}

	public function rebuildReportCounts()
	{
		$cache = [
			'total' => $this->db()->fetchOne("SELECT COUNT(*) FROM xf_report WHERE report_state IN('open', 'assigned')"),
			'lastModified' => time()
		];

		\XF::registry()->set('reportCounts', $cache);
		return $cache;
	}
}