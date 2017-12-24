<?php

namespace XF\Stats;

class Post extends AbstractHandler
{
	public function getStatsTypes()
	{
		return [
			'post' => \XF::phrase('posts'),
			'post_like' => \XF::phrase('post_likes')
		];
	}

	public function getData($start, $end)
	{
		$db = $this->db();

		$posts = $db->fetchPairs(
			$this->getBasicDataQuery('xf_post', 'post_date', 'message_state = ?'),
			[$start, $end, 'visible']
		);

		$postLikes = $db->fetchPairs(
			$this->getBasicDataQuery('xf_liked_content', 'like_date', 'content_type = ?'),
			[$start, $end, 'post']
		);

		return [
			'post' => $posts,
			'post_like' => $postLikes
		];
	}
}