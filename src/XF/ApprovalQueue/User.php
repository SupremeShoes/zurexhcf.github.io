<?php

namespace XF\ApprovalQueue;

use XF\Entity\ApprovalQueue;
use XF\Mvc\Entity\Entity;

class User extends AbstractHandler
{
	protected function canViewContent(Entity $content, &$error = null)
	{
		return true;
	}

	protected function canActionContent(Entity $content, &$error = null)
	{
		return \XF::visitor()->canApproveRejectUser();
	}

	public function getEntityWith()
	{
		return ['Profile'];
	}

	public function getTemplateData(ApprovalQueue $unapprovedItem)
	{
		$templateData = parent::getTemplateData($unapprovedItem);

		/** @var \XF\Entity\User $user */
		$user = $unapprovedItem->Content;

		// If we suspect this user to be a spammer, let's also grab their recent change logs.
		// This will highlight spam-like changes to their account they made after being sent to the approval queue.
		if ($user->isPossibleSpammer())
		{
			$changeRepo = \XF::repository('XF:ChangeLog');
			$changeFinder = $changeRepo->findChangeLogsByContent('user', $user->user_id);

			// just get 5 most recent
			$changes = $changeFinder->fetch(5);
			$changeRepo->addDataToLogs($changes);

			$templateData['user'] = $user;
			$templateData['changesGrouped'] = $changeRepo->groupChangeLogs($changes);
		}

		return $templateData;
	}

	public function actionApprove(\XF\Entity\User $user)
	{
		$user->user_state = 'valid';
		$user->save();

		\XF::app()->logger()->logModeratorAction('user', $user, 'approved');

		$notify = $this->getInput('notify', $user->user_id);
		if ($notify)
		{
			\XF::app()->mailer()->newMail()
				->setTemplate('user_account_approved', ['user' => $user])
				->setToUser($user)
				->send();
		}

		/** @var \XF\Service\User\Welcome $userWelcome */
		$userWelcome = \XF::app()->service('XF:User\Welcome', $user);
		$userWelcome->send();
	}

	public function actionReject(\XF\Entity\User $user)
	{
		$notify = $this->getInput('notify', $user->user_id);
		$reason = $this->getInput('reason', $user->user_id);

		$user->rejectUser($reason, \XF::visitor());

		if ($notify)
		{
			\XF::app()->mailer()->newMail()
				->setTemplate('user_account_rejected', ['user' => $user, 'reason' => $reason])
				->setToUser($user)
				->send();
		}

		\XF::app()->logger()->logModeratorAction('user', $user, 'rejected', ['reason' => $reason]);
	}
}