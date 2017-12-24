<?php

namespace XF\Import\Data;

class Thread extends AbstractEmulatedData
{
	use HasDeletionLogTrait;

	/**
	 * @var ThreadReplyBan[]
	 */
	protected $replyBans = [];

	protected $watchers = [];

	public function getImportType()
	{
		return 'thread';
	}

	public function getEntityShortName()
	{
		return 'XF:Thread';
	}

	public function setCustomFields(array $customFields)
	{
		foreach ($customFields AS &$fieldValue)
		{
			if (is_string($fieldValue))
			{
				$fieldValue = $this->convertToUtf8($fieldValue);
			}
		}

		$this->custom_fields = $customFields;
	}

	public function addReplyBan($oldId, ThreadReplyBan $ban)
	{
		$this->replyBans[$oldId] = $ban;
	}

	public function addThreadWatcher($userId, $emailSubscribe)
	{
		$this->watchers[$userId] = $emailSubscribe;
	}

	protected function preSave($oldId)
	{
		if (!$this->username)
		{
			$this->username = 'Guest';
		}
	}

	protected function postSave($oldId, $newId)
	{
		$this->insertStateRecord($this->discussion_state, $this->post_date);

		if ($this->custom_fields)
		{
			$this->insertCustomFieldValues('xf_thread_field_value', 'thread_id', $newId, $this->custom_fields);
		}

		if ($this->replyBans)
		{
			foreach ($this->replyBans AS $oldReplyBanId => $replyBan)
			{
				$replyBan->thread_id = $newId;
				$replyBan->log(false);
				$replyBan->checkExisting(false);
				$replyBan->useTransaction(false);

				$replyBan->save($oldReplyBanId);
			}
		}

		if ($this->watchers)
		{
			/** @var \XF\Import\DataHelper\Thread $threadHelper */
			$threadHelper = $this->dataManager->helper('XF:Thread');
			$threadHelper->importThreadWatchBulk($newId, $this->watchers);
		}
	}
}