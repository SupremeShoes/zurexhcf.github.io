<?php

namespace XF\Pub\Controller;

use XF\Mvc\ParameterBag;

class ApprovalQueue extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		if (!\XF::visitor()->is_moderator)
		{
			throw $this->exception($this->noPermission());
		}
	}

	public function actionIndex()
	{
		$approvalQueueRepo = $this->getApprovalQueueRepo();

		$unapprovedItems = $approvalQueueRepo->findUnapprovedContent()->fetch();

		if ($unapprovedItems->count() != $this->app->unapprovedCounts['total'])
		{
			$approvalQueueRepo->rebuildUnapprovedCounts();
		}

		$approvalQueueRepo->addContentToUnapprovedItems($unapprovedItems);
		$approvalQueueRepo->cleanUpInvalidRecords($unapprovedItems);
		$unapprovedItems = $approvalQueueRepo->filterViewableUnapprovedItems($unapprovedItems);

		$viewParams = [
			'unapprovedItems' => $unapprovedItems->slice(0, 50)
		];
		return $this->view('XF:ApprovalQueue\Listing', 'approval_queue', $viewParams);
	}

	public function actionProcess()
	{
		$approvalQueueRepo = $this->getApprovalQueueRepo();

		$queue = $this->filter('queue', 'array');
		foreach ($queue AS $contentType => $actions)
		{
			$handler = $approvalQueueRepo->getApprovalQueueHandler($contentType);
			foreach ($actions AS $contentId => $action)
			{
				if (!$action)
				{
					continue;
				}
				$handler->performAction($action, $handler->getContent($contentId));
			}
		}

		return $this->redirect($this->buildLink('approval-queue'));
	}

	/**
	 * @return \XF\Repository\ApprovalQueue
	 */
	protected function getApprovalQueueRepo()
	{
		return $this->repository('XF:ApprovalQueue');
	}

	public static function getActivityDetails(array $activities)
	{
		return \XF::phrase('performing_moderation_duties');
	}
}