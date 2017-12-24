<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null attachment_id
 * @property int data_id
 * @property string content_type
 * @property int content_id
 * @property int attach_date
 * @property string temp_hash
 * @property bool unassociated
 * @property int view_count
 *
 * GETTERS
 * @property Entity|null Container
 * @property string filename
 * @property mixed|string extension
 * @property int|string file_size
 * @property bool has_thumbnail
 * @property string thumbnail_url
 *
 * RELATIONS
 * @property \XF\Entity\AttachmentData Data
 */
class Attachment extends Entity
{
	public function canView(&$error = null)
	{
		if ($this->temp_hash || !$this->content_type)
		{
			return false;
		}

		/** @var \XF\Repository\Attachment $attachmentRepo */
		$attachmentRepo = $this->repository('XF:Attachment');
		$handler = $attachmentRepo->getAttachmentHandler($this->content_type);
		if (!$handler)
		{
			return false;
		}

		$container = $handler->getContainerEntity($this->content_id);
		if (!$container)
		{
			return false;
		}

		return $handler->canView($this, $container, $error);
	}

	/**
	 * @return string
	 */
	public function getFilename()
	{
		return $this->Data ? $this->Data->filename : '';
	}

	/**
	 * @return mixed|string
	 */
	public function getExtension()
	{
		return $this->Data ? $this->Data->extension : '';
	}

	/**
	 * @return int|string
	 */
	public function getFileSize()
	{
		return $this->Data ? $this->Data->file_size : '';
	}

	/**
	 * @return bool
	 */
	public function hasThumbnail()
	{
		return $this->Data ? $this->Data->hasThumbnail() : false;
	}

	/**
	 * @return string
	 */
	public function getThumbnailUrl()
	{
		return $this->Data ? $this->Data->getThumbnailUrl() : '';
	}

	public function getContainerLink()
	{
		$container = $this->getContainer();
		if ($container)
		{
			$handler = $this->getHandler();
			return $handler ? $handler->getContainerLink($this->getContainer()) : null;
		}

		return null;
	}

	public function getContentTypePhrase()
	{
		$handler = $this->getHandler();
		return $handler ? $handler->getContentTypePhrase() : null;
	}

	public function getHandler()
	{
		return $this->getAttachmentRepo()->getAttachmentHandler($this->content_type);
	}

	/**
	 * @return Entity|null
	 */
	public function getContainer()
	{
		$handler = $this->getHandler();
		return $handler ? $handler->getContainerEntity($this->content_id) : null;
	}

	public function setContainer(Entity $content = null)
	{
		$this->_getterCache['Container'] = $content;
	}

	protected function _preSave()
	{
		if (!$this->content_id)
		{
			if (!$this->temp_hash)
			{
				throw new \LogicException('Temp hash must be specified if no content is specified.');
			}

			$this->unassociated = true;
		}
		else
		{
			$this->temp_hash = '';
			$this->unassociated = false;
		}
	}

	protected function _postSave()
	{
		if ($this->isInsert())
		{
			/** @var AttachmentData $data */
			$data = $this->Data;
			if ($data)
			{
				$data->fastUpdate('attach_count', $data->attach_count + 1);
			}
		}
	}

	protected function _postDelete()
	{
		/** @var AttachmentData $data */
		$data = $this->Data;
		if ($data && $data->attach_count)
		{
			$data->fastUpdate('attach_count', $data->attach_count - 1);
		}

		if ($this->content_id)
		{
			/** @var \XF\Repository\Attachment $attachmentRepo */
			$attachmentRepo = $this->repository('XF:Attachment');
			$handler = $attachmentRepo->getAttachmentHandler($this->content_type);
			if ($handler)
			{
				$container = $handler->getContainerEntity($this->content_id);
				$handler->onAttachmentDelete($this, $container);
			}
		}
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_attachment';
		$structure->shortName = 'XF:Attachment';
		$structure->primaryKey = 'attachment_id';
		$structure->columns = [
			'attachment_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'data_id' => ['type' => self::UINT, 'required' => true],
			'content_type' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
			'content_id' => ['type' => self::UINT, 'default' => 0],
			'attach_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'temp_hash' => ['type' => self::STR, 'maxLength' => 32, 'default' => ''],
			'unassociated' => ['type' => self::BOOL, 'default' => true],
			'view_count' => ['type' => self::UINT, 'forced' => true, 'default' => 0]
		];
		$structure->getters = [
			'Container' => true,

			'filename' => ['getter' => 'getFilename', 'cache' => false],
			'extension' => ['getter' => 'getExtension', 'cache' => false],
			'file_size' => ['getter' => 'getFileSize', 'cache' => false],
			'has_thumbnail' => ['getter' => 'hasThumbnail', 'cache' => false],
			'thumbnail_url' => ['getter' => 'getThumbnailUrl', 'cache' => false]
		];
		$structure->relations = [
			'Data' => [
				'entity' => 'XF:AttachmentData',
				'type' => self::TO_ONE,
				'conditions' => 'data_id',
				'primary' => true
			],
		];
		$structure->defaultWith = ['Data'];

		return $structure;
	}

	/**
	 * @return \XF\Repository\Attachment
	 */
	protected function getAttachmentRepo()
	{
		return $this->repository('XF:Attachment');
	}
}