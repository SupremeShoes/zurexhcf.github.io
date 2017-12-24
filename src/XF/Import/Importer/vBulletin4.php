<?php

namespace XF\Import\Importer;

class vBulletin4 extends vBulletin
{
	public static function getListInfo()
	{
		return [
			'target' => 'XenForo',
			'source' => 'vBulletin 4.x',
			'beta' => true
		];
	}

	protected function getContentTypeId($contentType)
	{
		if (empty($this->session->extra['contentTypeId'][$contentType]))
		{
			$this->session->extra['contentTypeId'][$contentType] = $this->sourceDb->fetchOne("
				SELECT contenttypeid
				FROM {$this->prefix}contenttype
				WHERE class = ?
			", $contentType);
		}

		return $this->session->extra['contentTypeId'][$contentType];
	}

	protected function getThreadTags($threadId)
	{
		return $this->sourceDb->fetchAll("
			SELECT tagcontent.*, tag.tagtext
			FROM {$this->prefix}tagcontent AS
				tagcontent
			INNER JOIN {$this->prefix}tag AS
				tag ON(tag.tagid = tagcontent.tagid)
			WHERE tagcontent.contentid = ?
			AND tagcontent.contenttypeid = ?
		", [$threadId, $this->getContentTypeId('Thread')]);
	}

	protected function getAttachments($startAfter, $end, $limit)
	{
		return $this->sourceDb->fetchAll("
			SELECT atc.attachmentid, atc.userid, atc.dateline, atc.filename, atc.counter,
				atc.contentid AS postid,
				filedata.userid AS filedata_userid
			FROM {$this->prefix}attachment AS
				atc
			INNER JOIN {$this->prefix}filedata AS
				filedata ON(filedata.filedataid = atc.filedataid)
			WHERE atc.attachmentid > ? AND atc.attachmentid <= ?
			AND atc.contenttypeid = ?
			AND atc.state = 'visible'
			ORDER BY atc.attachmentid
			LIMIT {$limit}
		", [$startAfter, $end, $this->getContentTypeId('post')]);
	}

	protected function groupAttachmentsByFile(array $attachments)
	{
		$this->lookup('user', $this->pluck($attachments, ['userid', 'filedata_userid']));
		$this->lookup('post', $this->pluck($attachments, 'postid'));

		foreach ($attachments AS $a)
		{
			$grouped[$a['filedataid']][$a['attachmentid']] = $a;
		}

		return $grouped;
	}

	protected function getAttachmentFilePath($sourcePath, array $attachment)
	{
		return $sourcePath
			. '/' . implode('/', str_split($attachment['filedata_userid']))
			. '/' . $attachment['filedataid'] . '.attach';
	}
}