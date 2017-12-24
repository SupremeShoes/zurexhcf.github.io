<?php

namespace XF\Stats;

class ProfilePost extends AbstractHandler
{
	public function getStatsTypes()
	{
		return [
			'profile_post' => \XF::phrase('profile_posts'),
			'profile_post_like' => \XF::phrase('profile_post_likes')
		];
	}

	public function getData($start, $end)
	{
		$db = $this->db();

		$profilePosts = $db->fetchPairs(
			$this->getBasicDataQuery('xf_profile_post', 'post_date', 'message_state = ?'),
			[$start, $end, 'visible']
		);

		$profilePostLikes = $db->fetchPairs(
			$this->getBasicDataQuery('xf_liked_content', 'like_date', 'content_type = ?'),
			[$start, $end, 'profile_post']
		);

		return [
			'profile_post' => $profilePosts,
			'profile_post_like' => $profilePostLikes
		];
	}
}