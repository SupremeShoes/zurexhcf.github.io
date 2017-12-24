<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null conversation_id
 * @property string title
 * @property int user_id
 * @property string username
 * @property int start_date
 * @property bool open_invite
 * @property bool conversation_open
 * @property int reply_count
 * @property int recipient_count
 * @property int first_message_id
 * @property int last_message_date
 * @property int last_message_id
 * @property int last_message_user_id
 * @property string last_message_username
 * @property array recipients
 *
 * GETTERS
 * @property array message_ids
 * @property array last_message_cache
 * @property \XF\Draft draft_reply
 *
 * RELATIONS
 * @property \XF\Entity\ConversationRecipient[] Recipients
 * @property \XF\Entity\ConversationUser[] Users
 * @property \XF\Entity\ConversationMessage FirstMessage
 * @property \XF\Entity\ConversationMessage LastMessage
 * @property \XF\Entity\User LastMessageUser
 * @property \XF\Entity\User Starter
 * @property \XF\Entity\Draft[] DraftReplies
 */
class ConversationMaster extends Entity
{
	public function canEdit()
	{
		return ($this->user_id == \XF::visitor()->user_id);
	}

	public function canInvite()
	{
		$visitor = \XF::visitor();

		if ($visitor->hasPermission('conversation', 'alwaysInvite'))
		{
			return true;
		}

		if (!$this->open_invite && !$this->canEdit())
		{
			return false;
		}

		$remaining = $this->getRemainingRecipientsCount();
		return ($remaining == -1 || $remaining > 0);
	}

	public function canReply()
	{
		// if a conversation isn't open, we want the creator to know by not showing the reply box
		return $this->conversation_open;
	}

	public function canView(&$error = null)
	{
		$visitor = \XF::visitor();
		return $this->Users[$visitor->user_id] ? true : false;
	}

	public function canUploadAndManageAttachments()
	{
		$visitor = \XF::visitor();

		return ($visitor->user_id && $visitor->hasPermission('conversation', 'uploadAttachment'));
	}

	/**
	 * @return \XF\Draft
	 */
	public function getDraftReply()
	{
		return \XF\Draft::createFromEntity($this, 'DraftReplies');
	}

	public function getRemainingRecipientsCount(User $user = null)
	{
		$maxRecipients = $this->getMaximumAllowedRecipients($user);
		if ($maxRecipients == -1 || !$this->exists())
		{
			return $maxRecipients;
		}
		else
		{
			$remaining = ($maxRecipients - $this->recipient_count + 1); // +1 represents self; self doesn't count
			return max(0, $remaining);
		}
	}

	public function getMaximumAllowedRecipients(User $user = null)
	{
		$user = $user ?: \XF::visitor();
		return $user->hasPermission('conversation', 'maxRecipients');
	}

	public function getNewMessage(User $user = null)
	{
		$message = $this->_em->create('XF:ConversationMessage');

		$message->conversation_id = $this->_getDeferredValue(function()
		{
			return $this->conversation_id;
		}, 'save');

		if ($user)
		{
			$message->user_id = $user->user_id;
			$message->username = $user->username;
		}

		return $message;
	}

	public function getNewRecipient(User $user)
	{
		$recipient = $this->_em->create('XF:ConversationRecipient');
		$recipient->conversation_id = $this->conversation_id;
		$recipient->user_id = $user->user_id;

		return $recipient;
	}

	/**
	 * @return array
	 */
	public function getMessageIds()
	{
		return $this->db()->fetchAllColumn("
			SELECT message_id
			FROM xf_conversation_message
			WHERE conversation_id = ?
			ORDER BY message_date
		", $this->conversation_id);
	}

	/**
	 * @return array
	 */
	public function getLastMessageCache()
	{
		return [
			'message_id' => $this->last_message_id,
			'user_id' => $this->last_message_user_id,
			'username' => $this->last_message_username,
			'message_date' => $this->last_message_date
		];
	}

	public function messageAdded(ConversationMessage $message)
	{
		if (!$this->first_message_id)
		{
			$this->first_message_id = $message->message_id;
		}
		else
		{
			$this->reply_count++;
		}

		if ($message->message_date >= $this->last_message_date)
		{
			$this->last_message_date = $message->message_date;
			$this->last_message_id = $message->message_id;
			$this->last_message_user_id = $message->user_id;
			$this->last_message_username = $message->username;

			foreach ($this->Recipients AS $recipient)
			{
				if ($recipient->recipient_state == 'deleted_ignored')
				{
					continue;
				}

				/** @var \XF\Entity\ConversationUser $conversationUser */
				$conversationUser = $recipient->getRelationOrDefault('ConversationUser');

				if ($recipient->recipient_state == 'deleted')
				{
					$conversationUser->conversation_id = $this->conversation_id;
					$conversationUser->owner_user_id = $recipient->user_id;
					$conversationUser->is_starred = false;
				}

				$recipient->recipient_state = 'active';

				$conversationUser->reply_count = $this->reply_count;
				$conversationUser->last_message_date = $message->message_date;
				$conversationUser->last_message_id = $message->message_id;
				$conversationUser->last_message_user_id = $message->user_id;
				$conversationUser->last_message_username = $message->username;
				$conversationUser->is_unread = true;

				$recipient->save(); // saves $conversationUser too
			}
		}

		unset($this->_getterCache['message_ids']);
	}

	public function recipientRemoved(ConversationRecipient $recipient)
	{
		$users = $this->Users;
		if (!$users->count())
		{
			$this->delete();
		}
	}

	protected function _postDelete()
	{
		$db = $this->db();

		$db->query("
			UPDATE xf_user AS user
			INNER JOIN xf_conversation_user AS cuser ON
				(cuser.owner_user_id = user.user_id AND cuser.conversation_id = ? AND cuser.is_unread = 1)
			SET user.conversations_unread = user.conversations_unread - 1
			WHERE user.conversations_unread > 0
		", $this->conversation_id);

		$db->delete('xf_conversation_recipient', 'conversation_id = ?', $this->conversation_id);
		$db->delete('xf_conversation_user', 'conversation_id = ?', $this->conversation_id);

		$messageIds = $this->message_ids;
		if ($messageIds)
		{
			$db->delete('xf_conversation_message', 'conversation_id = ?', $this->conversation_id);

			/** @var \XF\Repository\Attachment $attachRepo */
			$attachRepo = $this->repository('XF:Attachment');
			$attachRepo->fastDeleteContentAttachments('conversation_message', $messageIds);

			/** @var \XF\Repository\LikedContent $likeRepo */
			$likeRepo = $this->repository('XF:LikedContent');
			$likeRepo->fastDeleteLikes('conversation_message', $messageIds);
		}
	}

	public function rebuildRecipientCache()
	{
		$this->repository('XF:Conversation')->rebuildConversationRecipientCache($this);
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_conversation_master';
		$structure->shortName = 'XF:ConversationMaster';
		$structure->primaryKey = 'conversation_id';
		$structure->contentType = 'conversation';
		$structure->columns = [
			'conversation_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'title' => ['type' => self::STR, 'maxLength' => 150,
				'required' => 'please_enter_valid_title',
				'censor' => true
			],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
			'start_date' => ['type' => self::UINT, 'default' => 0],
			'open_invite' => ['type' => self::BOOL, 'default' => false],
			'conversation_open' => ['type' => self::BOOL, 'default' => true],
			'reply_count' => ['type' => self::UINT, 'default' => 0],
			'recipient_count' => ['type' => self::UINT, 'default' => 0],
			'first_message_id' => ['type' => self::UINT, 'default' => 0],
			'last_message_date' => ['type' => self::UINT, 'default' => 0],
			'last_message_id' => ['type' => self::UINT, 'default' => 0],
			'last_message_user_id' => ['type' => self::UINT, 'default' => 0],
			'last_message_username' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
			'recipients' => ['type' => self::SERIALIZED_ARRAY, 'default' => []]
		];
		$structure->getters = [
			'message_ids' => true,
			'last_message_cache' => true,
			'draft_reply' => true
		];
		$structure->relations = [
			'Recipients' => [
				'entity' => 'XF:ConversationRecipient',
				'type' => self::TO_MANY,
				'conditions' => 'conversation_id',
				'key' => 'user_id',
			],
			'Users' => [
				'entity' => 'XF:ConversationUser',
				'type' => self::TO_MANY,
				'conditions' => 'conversation_id',
				'key' => 'owner_user_id'
			],
			'FirstMessage' => [
				'entity' => 'XF:ConversationMessage',
				'type' => self::TO_ONE,
				'conditions' => [
					['message_id', '=', '$first_message_id']
				],
				'primary' => true
			],
			'LastMessage' => [
				'entity' => 'XF:ConversationMessage',
				'type' => self::TO_ONE,
				'conditions' => [
					['message_id', '=', '$last_message_id']
				],
				'primary' => true
			],
			'LastMessageUser' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => [
					['user_id', '=', '$last_message_user_id']
				],
				'primary' => true
			],
			'Starter' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true
			],
			'DraftReplies' => [
				'entity' => 'XF:Draft',
				'type' => self::TO_MANY,
				'conditions' => [
					['draft_key', '=', 'conversation-reply-', '$conversation_id']
				],
				'key' => 'user_id'
			]
		];

		return $structure;
	}
}