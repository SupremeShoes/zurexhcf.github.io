<?php

namespace XF\Import\Data;

class Post extends AbstractEmulatedData
{
	use HasDeletionLogTrait;

	protected $loggedIp;

	public function getImportType()
	{
		return 'post';
	}

	public function getEntityShortName()
	{
		return 'XF:Post';
	}

	public function setLoggedIp($loggedIp)
	{
		$this->loggedIp = $loggedIp;
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
		$this->logIp($this->loggedIp, $this->post_date);
		$this->insertStateRecord($this->message_state, $this->post_date);

		if ($this->message_state == 'visible' && $this->user_id)
		{
			$this->db()->insert('xf_thread_user_post', [
				'thread_id' => $newId,
				'user_id' => $this->user_id,
				'post_count' => 1
			], false, 'post_count = post_count + VALUES(post_count)');
		}
	}
}