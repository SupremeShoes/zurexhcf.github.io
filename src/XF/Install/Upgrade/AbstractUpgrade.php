<?php

namespace XF\Install\Upgrade;

use XF\App;

abstract class AbstractUpgrade
{
	protected $app;

	abstract public function getVersionName();

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	public function executeUpgradeQuery($sql, $bind = [], $suppressAll = false)
	{
		try
		{
			return $this->db()->query($sql, $bind);
		}
		catch (\XF\Db\Exception $e)
		{
			if ($suppressAll)
			{
				return false;
			}

			$message = $e->getMessage();
			if (preg_match('/(have an error in your SQL syntax|table \'.*\' doesn\'t exist|Unknown column|doesn\'t have a default value|Data truncated)/i', $message))
			{
				// we don't want to suppress errors in the query that should generally be corrected
				throw $e;
			}

			return false;
		}
	}

	public function insertUpgradeJob($uniqueKey, $jobClass, array $params = [], $immediate = true)
	{
		if (strlen($uniqueKey) > 50)
		{
			$uniqueKey = md5($uniqueKey);
		}

		$this->db()->insert('xf_upgrade_job', [
			'unique_key' => $uniqueKey,
			'execute_class' => $jobClass,
			'execute_data' => serialize($params),
			'immediate' => $immediate ? 1 : 0
		], false, '
			execute_class = VALUES(execute_class),
			execute_data = VALUES(execute_data),
			immediate = VALUES(immediate)
		');

		return $uniqueKey;
	}

	public function insertPostUpgradeJob($uniqueKey, $jobClass, array $params = [])
	{
		return $this->insertUpgradeJob($uniqueKey, $jobClass, $params, false);
	}

	public function applyGlobalPermission($applyGroupId, $applyPermissionId, $dependGroupId = null, $dependPermissionId = null)
	{
		$db = $this->db();

		if ($dependGroupId && $dependPermissionId)
		{
			$db->query("
				REPLACE INTO xf_permission_entry
					(user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int)
				SELECT user_group_id, user_id, ?, ?, 'allow', 0
				FROM xf_permission_entry
				WHERE permission_group_id = ?
					AND permission_id = ?
					AND permission_value = 'allow'
			", [$applyGroupId, $applyPermissionId, $dependGroupId, $dependPermissionId]);
		}
		else
		{
			$db->query("
				REPLACE INTO xf_permission_entry
					(user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int)
				SELECT DISTINCT user_group_id, user_id, ?, ?, 'allow', 0
				FROM xf_permission_entry
			", [$applyGroupId, $applyPermissionId]);
		}
	}

	public function applyGlobalPermissionInt($applyGroupId, $applyPermissionId, $applyValue, $dependGroupId = null, $dependPermissionId = null)
	{
		$db = $this->db();

		if ($dependGroupId && $dependPermissionId)
		{
			$db->query("
				REPLACE INTO xf_permission_entry
					(user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int)
				SELECT user_group_id, user_id, ?, ?, 'use_int', ?
				FROM xf_permission_entry
				WHERE permission_group_id = ?
					AND permission_id = ?
					AND permission_value = 'allow'
			", [$applyGroupId, $applyPermissionId, $applyValue, $dependGroupId, $dependPermissionId]);
		}
		else
		{
			$db->query("
				REPLACE INTO xf_permission_entry
					(user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int)
				SELECT DISTINCT user_group_id, user_id, ?, ?, 'use_int', ?
				FROM xf_permission_entry
			", [$applyGroupId, $applyPermissionId, $applyValue]);
		}
	}

	public function applyContentPermission($applyGroupId, $applyPermissionId, $dependGroupId, $dependPermissionId)
	{
		$db = $this->db();

		$db->query("
			REPLACE INTO xf_permission_entry_content
				(content_type, content_id, user_group_id, user_id,
				permission_group_id, permission_id, permission_value, permission_value_int)
			SELECT content_type, content_id, user_group_id, user_id, ?, ?, 'content_allow', 0
			FROM xf_permission_entry_content
			WHERE permission_group_id = ?
				AND permission_id = ?
				AND permission_value = 'content_allow'
		", [$applyGroupId, $applyPermissionId, $dependGroupId, $dependPermissionId]);
	}

	/**
	 * @return \XF\Db\AbstractAdapter
	 */
	protected function db()
	{
		return $this->app->db();
	}

	/**
	 * @return \XF\Db\SchemaManager
	 */
	protected function schemaManager()
	{
		return $this->db()->getSchemaManager();
	}
}