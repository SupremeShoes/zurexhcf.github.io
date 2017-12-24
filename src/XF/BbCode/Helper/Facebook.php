<?php

namespace XF\BbCode\Helper;

class Facebook
{
	public static function htmlCallback($mediaKey, array $site, $siteId)
	{
		if (preg_match('#^[^/]+/posts/\d+$#', $mediaKey))
		{
			$id = $mediaKey;
			$type = 'post';
		}
		else if (preg_match('#^\d+$#', $mediaKey))
		{
			$id = $mediaKey;
			$type = 'video';
		}
		else
		{
			return '';
		}

		return \XF::app()->templater()->renderTemplate('public:_media_site_embed_facebook', [
			'type' => $type,
			'id' => rawurlencode($id),
			'idPlain' => $id
		]);
	}
}