<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null message_id
 * @property int conversation_id
 * @property int message_date
 * @property int user_id
 * @property string username
 * @property string message
 * @property int attach_count
 * @property int ip_id
 * @property array|null embed_metadata
 * @property int likes
 * @property array like_users
 *
 * RELATIONS
 * @property \XF\Entity\ConversationMaster Conversation
 * @property \XF\Entity\User User
 * @property \XF\Entity\Attachment[] Attachments
 * @property \XF\Entity\LikedContent[] Likes
 */
class ConversationMessage extends Entity implements QuotableInterface, \XF\BbCode\RenderableContentInterface
{
	public function canView(&$error = null)
	{
		if (!\XF::visitor()->user_id || !$this->Conversation || !$this->Conversation->canView($error))
		{
			return false;
		}

		return true;
	}

	public function canEdit(&$error = null)
	{
		/** @var \XF\Entity\ConversationMaster $conversation */
		$conversation = $this->Conversation;
		$visitor = \XF::visitor();
		if (!$visitor->user_id || !$conversation)
		{
			return false;
		}

		if (!$conversation->conversation_open)
		{
			$error = \XF::phraseDeferred('conversation_is_closed');
			return false;
		}

		if ($visitor->hasPermission('conversation', 'editAnyMessage'))
		{
			return true;
		}

		if ($this->user_id == $visitor->user_id && $visitor->hasPermission('conversation', 'editOwnMessage'))
		{
			$editLimit = $visitor->hasPermission('conversation', 'editOwnMessageTimeLimit');
			if ($editLimit != -1 && (!$editLimit || $this->message_date < \XF::$time - 60 * $editLimit))
			{
				$error = \XF::phraseDeferred('message_edit_time_limit_expired', ['minutes' => $editLimit]);
				return false;
			}

			return true;
		}

		return false;
	}

	public function canReport(&$error = null, User $asUser = null)
	{
		$asUser = $asUser ?: \XF::visitor();
		return $asUser->canReport($error);
	}

	public function canLike(&$error = null)
	{
		$visitor = \XF::visitor();
		if (!$visitor->user_id)
		{
			return false;
		}

		if ($this->user_id == $visitor->user_id)
		{
			$error = \XF::phraseDeferred('liking_own_content_cheating');
			return false;
		}
		
		return $visitor->hasPermission('conversation', 'like');
	}

	public function isLiked()
	{
		$visitor = \XF::visitor();
		if (!$visitor->user_id)
		{
			return false;
		}

		return isset($this->Likes[$visitor->user_id]);
	}

	public function isUnread($lastRead = null)
	{
		if ($lastRead === null)
		{
			$user = \XF::visitor();
		}
		else if ($lastRead instanceof User)
		{
			$user = $lastRead;
			$lastRead = null;
		}
		else
		{
			$user = null;
			$lastRead = intval($lastRead);
		}

		if ($user)
		{
			if (!isset($this->Conversation->Recipients[$user->user_id]))
			{
				return false;
			}
			$lastRead = $this->Conversation->Recipients[$user->user_id]->last_read_date;
		}

		return ($lastRead < $this->message_date);
	}

	public function isAttachmentEmbedded($attachmentId)
	{
		if (!$this->embed_metadata)
		{
			return false;
		}

		if ($attachmentId instanceof Attachment)
		{
			$attachmentId = $attachmentId->attachment_id;
		}

		return isset($this->embed_metadata['attachments'][$attachmentId]);
	}

	public function isIgnored()
	{
		return \XF::visitor()->isIgnoring($this->user_id);
	}

	public function getQuoteWrapper($inner)
	{
		return '[QUOTE="'
			. ($this->User ? $this->User->username : $this->username)
			. ', convMessage: ' . $this->message_id
			. ($this->User ? ", member: $this->user_id" : '')
			. '"]'
			. $inner
			. "[/QUOTE]\n";
	}

	public function getBbCodeRenderOptions($context, $type)
	{
		return [
			'entity' => $this,
			'user' => $this->User,
			'attachments' => $this->attach_count ? $this->Attachments : [],
			'viewAttachments' => true
		];
	}

	protected function _postSave()
	{
		$this->updateConversationRecord();
	}

	protected function updateConversationRecord()
	{
		if (!$this->Conversation || !$this->Conversation->exists())
		{
			// inserting a conversation, don't try to write to it
			return;
		}

		if ($this->isInsert())
		{
			$this->Conversation->messageAdded($this);
			$this->Conversation->save();
		}
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_conversation_message';
		$structure->shortName = 'XF:ConversationMessage';
		$structure->primaryKey = 'message_id';
		$structure->contentType = 'conversation_message';
		$structure->columns = [
			'message_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'conversation_id' => ['type' => self::UINT, 'required' => true],
			'message_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'username' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
			'message' => ['type' => self::STR,
				'required' => 'please_enter_valid_message'
			],
			'attach_count' => ['type' => self::UINT, 'max' => 65535, 'default' => 0],
			'ip_id' => ['type' => self::UINT, 'default' => 0],
			'embed_metadata' => ['type' => self::JSON_ARRAY, 'nullable' => true, 'default' => null],
			'likes' => ['type' => self::UINT, 'forced' => true, 'default' => 0],
			'like_users' => ['type' => self::SERIALIZED_ARRAY, 'default' => []],
		];
		$structure->getters = [];
		$structure->relations = [
			'Conversation' => [
				'entity' => 'XF:ConversationMaster',
				'type' => self::TO_ONE,
				'conditions' => 'conversation_id',
				'primary' => true
			],
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true
			],
			'Attachments' => [
				'entity' => 'XF:Attachment',
				'type' => self::TO_MANY,
				'conditions' => [
					['content_type', '=', 'conversation_message'],
					['content_id', '=', '$message_id']
				],
				'with' => 'Data',
				'order' => 'attach_date'
			],
			'Likes' => [
				'entity' => 'XF:LikedContent',
				'type' => self::TO_MANY,
				'conditions' => [
					['content_type', '=', 'conversation_message'],
					['content_id', '=', '$message_id']
				],
				'key' => 'like_user_id',
				'order' => 'like_date'
			]
		];

		return $structure;
	}
}