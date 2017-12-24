<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null data_id
 * @property int user_id
 * @property int upload_date
 * @property string filename
 * @property int file_size
 * @property string file_hash
 * @property string file_path
 * @property int width
 * @property int height
 * @property int thumbnail_width
 * @property int thumbnail_height
 * @property int attach_count
 *
 * GETTERS
 * @property string extension
 * @property bool has_thumbnail
 * @property string|null thumbnail_url
 *
 * RELATIONS
 * @property \XF\Entity\User User
 */
class AttachmentData extends Entity
{
	/**
	 * @return string
	 */
	public function getExtension()
	{
		return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
	}

	public function getAbstractedDataPath()
	{
		return $this->_getAbstractedDataPath(
			$this->data_id,
			$this->file_path,
			$this->file_hash
		);
	}

	public function getExistingAbstractedDataPath()
	{
		return $this->_getAbstractedDataPath(
			$this->getExistingValue('data_id'),
			$this->getExistingValue('file_path'),
			$this->getExistingValue('file_hash')
		);
	}

	protected function _getAbstractedDataPath($dataId, $filePath, $fileHash)
	{
		$group = floor($dataId / 1000);

		if ($filePath)
		{
			$placeholders = [
				'%INTERNAL%' => 'internal-data://', // for legacy
				'%DATA%' => 'data://', // for legacy
				'%DATA_ID%' => $dataId,
				'%FLOOR%' => $group,
				'%HASH%' => $fileHash
			];
			$path = strtr($filePath, $placeholders);
			$path = str_replace(':///', '://', $path); // writing %INTERNAL%/path would cause this

			return $path;
		}
		else
		{
			return sprintf('internal-data://attachments/%d/%d-%s.data',
				$group,
				$dataId,
				$fileHash
			);
		}
	}

	public function getAbstractedThumbnailPath()
	{
		return $this->_getAbstractedThumbnailPath(
			$this->data_id,
			$this->file_hash
		);
	}

	public function getExistingAbstractedThumbnailPath()
	{
		return $this->_getAbstractedThumbnailPath(
			$this->getExistingValue('data_id'),
			$this->getExistingValue('file_hash')
		);
	}

	protected function _getAbstractedThumbnailPath($dataId, $fileHash)
	{
		return sprintf('data://attachments/%d/%d-%s.jpg',
			floor($dataId / 1000),
			$dataId,
			$fileHash
		);
	}

	/**
	 * @return string|null
	 */
	public function getThumbnailUrl()
	{
		if (!$this->thumbnail_width)
		{
			return null;
		}

		$dataId = $this->data_id;

		$path = sprintf('attachments/%d/%d-%s.jpg',
			floor($dataId / 1000),
			$dataId,
			$this->file_hash
		);
		return $this->app()->applyExternalDataUrl($path);
	}

	/**
	 * @return bool
	 */
	public function hasThumbnail()
	{
		return $this->thumbnail_width ? true : false;
	}

	public function isDataAvailable()
	{
		$file = $this->getAbstractedDataPath();
		return $file && \XF::app()->fs()->has($file);
	}

	protected function verifyFilePath(&$path)
	{
		if (!strlen($path))
		{
			return true;
		}

		$placeholders = [
			'%INTERNAL%' => 'internal-data://', // for legacy
			'%DATA%' => 'data://', // for legacy
		];
		$path = strtr($path, $placeholders);

		if (!preg_match('#^[a-z0-9-]+://#i', $path))
		{
			throw new \LogicException("Invalid file path. Must be an abstracted path.");
		}

		return true;
	}

	protected function verifyFileName(&$fileName)
	{
		$maxLength = 100; // must match value in structure

		if (strlen($fileName) > $maxLength && $info = @pathinfo($fileName))
		{
			$extension = '...' . $info['extension'];

			$fileName = substr($info['filename'], 0, $maxLength - strlen($extension)) . $extension;
		}

		return true;
	}

	protected function _postDelete()
	{
		$filePath = $this->getAbstractedDataPath();
		\XF\Util\File::deleteFromAbstractedPath($filePath);

		$thumbPath = $this->getAbstractedThumbnailPath();
		\XF\Util\File::deleteFromAbstractedPath($thumbPath);
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_attachment_data';
		$structure->shortName = 'XF:AttachmentData';
		$structure->primaryKey = 'data_id';
		$structure->columns = [
			'data_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'user_id' => ['type' => self::UINT, 'required' => true],
			'upload_date' => ['type' => self::UINT, 'default' => \XF::$time],
			'filename' => ['type' => self::STR, 'maxLength' => 100, // if this is adjusted, see verifyFileName()
				'required' => true, 'censor' => true
			],
			'file_size' => ['type' => self::UINT, 'required' => true],
			'file_hash' => ['type' => self::STR, 'maxLength' => 32, 'required' => true],
			'file_path' => ['type' => self::STR, 'maxLength' => 250, 'default' => ''],
			'width' => ['type' => self::UINT, 'default' => 0],
			'height' => ['type' => self::UINT, 'default' => 0],
			'thumbnail_width' => ['type' => self::UINT, 'default' => 0],
			'thumbnail_height' => ['type' => self::UINT, 'default' => 0],
			'attach_count' => ['type' => self::UINT, 'default' => 0, 'forced' => true]
		];
		$structure->getters = [
			'extension' => ['getter' => 'getExtension', 'cache' => false],
			'has_thumbnail' => ['getter' => 'hasThumbnail', 'cache' => false],
			'thumbnail_url' => true
		];
		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true
			],
		];

		return $structure;
	}
}