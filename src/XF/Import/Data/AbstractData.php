<?php

namespace XF\Import\Data;

use XF\Import\DataManager;

abstract class AbstractData
{
	/**
	 * @var DataManager
	 */
	protected $dataManager;

	/**
	 * @var bool
	 */
	protected $log = true;

	protected $checkExisting = true;

	protected $useTransaction = true;

	abstract public function getImportType();
	abstract public function set($field, $value, array $options = []);
	abstract public function get($field);
	abstract protected function write($oldId);
	abstract protected function importedIdFound($oldId, $newId);

	public function __construct(DataManager $dataManager, $log = true)
	{
		$this->dataManager = $dataManager;
		$this->log = $log;

		$this->init();
	}

	protected function init()
	{
	}

	public function retainIds()
	{
		return $this->dataManager->getRetainIds();
	}

	public function log($log)
	{
		$this->log = $log;
	}

	public function checkExisting($check)
	{
		$this->checkExisting = $check;
	}

	public function useTransaction($use)
	{
		$this->useTransaction = $use;
	}

	public function isLogged()
	{
		return $this->log;
	}

	public function bulkSet(array $values, array $options = [])
	{
		foreach ($values AS $key => $value)
		{
			$this->set($key, $value, $options);
		}
	}

	public function save($oldId)
	{
		if ($oldId !== false && $this->log && $this->checkExisting)
		{
			$mappedId = $this->dataManager->lookup($this->getImportType(), $oldId);
			if ($mappedId !== false)
			{
				return $mappedId;
			}
		}

		$preSave = $this->preSave($oldId);
		if ($preSave === false)
		{
			return false;
		}

		$db = $this->dataManager->db();
		if ($this->useTransaction)
		{
			$db->beginTransaction();
		}

		try
		{
			$newId = $this->write($oldId);

			if ($newId !== false)
			{
				if ($oldId !== false && $this->log)
				{
					$this->dataManager->log($this->getImportType(), $oldId, $newId);
				}

				$this->postSave($oldId, $newId);
			}
		}
		catch (\Exception $e)
		{
			if ($this->useTransaction)
			{
				$db->rollback();
			}

			throw $e;
		}

		if ($this->useTransaction)
		{
			$db->commit();
		}

		return $newId;
	}

	protected function preSave($oldId)
	{
		return null; // return false to prevent save
	}

	protected function postSave($oldId, $newId)
	{
	}

	public function convertToUtf8($string, $fromCharset = null, $convertHtml = null)
	{
		return $this->dataManager->convertToUtf8($string, $fromCharset, $convertHtml);
	}

	protected function insertMasterPhrase($title, $value, array $extra = [], $silent = false)
	{
		$phrase = $this->dataManager->em()->create('XF:Phrase');
		$phrase->title = $title;
		$phrase->phrase_text = $value;
		$phrase->language_id = 0;
		$phrase->addon_id = '';
		$phrase->bulkSet($extra);

		$phrase->save($silent ? false : true, false);

		return $phrase;
	}

	protected function importRawIp($userId, $contentType, $contentId, $action, $ip, $date)
	{
		$ip = \XF\Util\Ip::convertIpStringToBinary($ip);

		$this->db()->insert('xf_ip', [
			'user_id' => $userId,
			'content_type' => $contentType,
			'content_id' => $contentId,
			'action' => $action,
			'ip' => $ip,
			'log_date' => $date
		]);

		return $this->db()->lastInsertId();
	}

	protected function insertCustomFieldValues($tableName, $contentColumn, $newId, array $customFields)
	{
		$insert = [];
		foreach ($customFields AS $key => $value)
		{
			$insert[] = [
				$contentColumn => $newId,
				'field_id' => $key,
				'field_value' => is_array($value) ? serialize($value) : $value
			];
		}

		if ($insert)
		{
			$this->db()->insertBulk($tableName, $insert, false, 'field_value = VALUES(field_value)');
		}
	}

	public function __set($key, $value)
	{
		$this->set($key, $value);
	}

	public function __get($key)
	{
		return $this->get($key);
	}

	public function db()
	{
		return $this->dataManager->db();
	}

	public function repository($repo)
	{
		return $this->em()->getRepository($repo);
	}

	public function em()
	{
		return $this->dataManager->em();
	}

	public function app()
	{
		return $this->dataManager->app();
	}
}