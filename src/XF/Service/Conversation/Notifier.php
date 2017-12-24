<?php

namespace XF\Service\Conversation;

use XF\Service\AbstractService;

class Notifier extends AbstractService
{
	protected $conversation;

	protected $onlyNotifyUsers = null;

	public function __construct(\XF\App $app, \XF\Entity\ConversationMaster $conversation)
	{
		parent::__construct($app);

		$this->conversation = $conversation;
	}

	public function addNotificationLimit($limit)
	{
		if (!is_array($this->onlyNotifyUsers))
		{
			$this->onlyNotifyUsers = [];
		}

		if (is_array($limit))
		{
			foreach ($limit AS $l)
			{
				if ($l instanceof \XF\Entity\User)
				{
					$this->onlyNotifyUsers[] = $l->user_id;
				}
				else
				{
					$this->onlyNotifyUsers[] = intval($l);
				}
			}
		}
		else if ($limit instanceof \XF\Entity\User)
		{
			$this->onlyNotifyUsers[] = $limit->user_id;
		}
		else
		{
			$this->onlyNotifyUsers[] = intval($limit);
		}

		return $this;
	}

	public function notifyCreate()
	{
		$message = $this->conversation->FirstMessage;
		$users = $this->_getRecipientUsers();

		return $this->_sendNotifications('create', $users, $message);
	}

	public function notifyReply(\XF\Entity\ConversationMessage $message)
	{
		$users = $this->_getRecipientUsers();

		return $this->_sendNotifications('reply', $users, $message);
	}

	public function notifyInvite(array $users, \XF\Entity\User $inviter)
	{
		$message = $this->conversation->FirstMessage;

		return $this->_sendNotifications('invite', $users, $message, $inviter);
	}

	protected function _getRecipientUsers()
	{
		$finder = $this->conversation->getRelationFinder('Recipients');
		$finder->where('recipient_state', 'active')
			->with(['User', 'User.Option'], true)
			->pluckFrom('User', 'user_id');
		return $finder->fetch()->toArray();
	}

	protected function _sendNotifications(
		$actionType, array $notifyUsers, \XF\Entity\ConversationMessage $message = null, \XF\Entity\User $sender = null
	)
	{
		if (!$sender && $message)
		{
			$sender = $message->User;
		}

		$usersEmailed = [];

		/** @var \XF\Entity\User $user */
		foreach ($notifyUsers AS $user)
		{
			if (!$this->_canUserReceiveNotification($user, $sender))
			{
				continue;
			}

			$template = 'conversation_' . $actionType;

			$params = [
				'receiver' => $user,
				'sender' => $sender,
				'conversation' => $this->conversation,
				'message' => $message
			];

			$this->app->mailer()->newMail()
				->setToUser($user)
				->setTemplate($template, $params)
				->queue();

			$usersEmailed[$user->user_id] = $user;
		}

		return $usersEmailed;
	}

	protected function _canUserReceiveNotification(\XF\Entity\User $user, \XF\Entity\User $sender = null)
	{
		if (is_array($this->onlyNotifyUsers) && !in_array($user->user_id, $this->onlyNotifyUsers))
		{
			return false;
		}

		return (
			$user->Option->email_on_conversation
			&& $user->user_state == 'valid'
			&& !$user->is_banned
			&& (!$sender || $sender->user_id != $user->user_id)
		);
	}
}