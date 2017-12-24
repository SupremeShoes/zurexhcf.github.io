<?php

namespace XF\Stats;

class ProfilePostComment extends AbstractHandler
{
	public function getStatsTypes()
	{
		return [
			'profile_post_comment' => \XF::phrase('profile_post_comments'),
			'profile_post_comment_like' => \XF::phrase('profile_post_comment_likes')
		];
	}

	public function getData($start, $end)
	{
		$db = $this->db();

		$profilePostComments = $db->fetchPairs(
			$this->getBasicDataQuery('xf_profile_post_comment', 'comment_date', 'message_state = ?'),
			[$start, $end, 'visible']
		);

		$profilePostCommentLikes = $db->fetchPairs(
			$this->getBasicDataQuery('xf_liked_content', 'like_date', 'content_type = ?'),
			[$start, $end, 'profile_post_comment']
		);

		return [
			'profile_post_comment' => $profilePostComments,
			'profile_post_comment_like' => $profilePostCommentLikes
		];
	}
}