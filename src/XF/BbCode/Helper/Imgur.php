<?php

namespace XF\BbCode\Helper;

class Imgur
{
	public static function matchCallback($url, $matchedId, \XF\Entity\BbCodeMediaSite $site, $siteId)
	{
		if (strlen($matchedId) == 5)
		{
			$matchedId = 'a/' . $matchedId;
		}

		return $matchedId;
	}

	// oEmbed endpoint: http://api.imgur.com/oembed.json?url=http://imgur.com/gallery/{$id}
}