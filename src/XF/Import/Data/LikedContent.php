<?php

namespace XF\Import\Data;

class LikedContent extends AbstractEmulatedData
{
	public function getImportType()
	{
		return 'liked_content';
	}

	public function getEntityShortName()
	{
		return 'XF:LikedContent';
	}

	protected function postSave($oldId, $newId)
	{
		if ($this->is_counted && $this->content_user_id)
		{
			$this->db()->query("
				UPDATE xf_user
				SET like_count = like_count + 1
				WHERE user_id = ?
			", $this->content_user_id);
		}

		$this->app()->repository('XF:LikedContent')->rebuildContentLikeCache(
			$this->content_type, $this->content_id, false
		);
	}
}