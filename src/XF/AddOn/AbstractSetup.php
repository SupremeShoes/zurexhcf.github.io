<?php

namespace XF\AddOn;

abstract class AbstractSetup
{
	/**
	 * @var \XF\AddOn\AddOn
	 */
	protected $addOn;

	/**
	 * @var \XF\App
	 */
	protected $app;

	/**
	 * @param array $stepParams
	 *
	 * @return null|StepResult
	 */
	abstract public function install(array $stepParams = []);

	/**
	 * @param array $stepParams
	 *
	 * @return null|StepResult
	 */
	abstract public function upgrade(array $stepParams = []);

	/**
	 * @param array $stepParams
	 *
	 * @return null|StepResult
	 */
	abstract public function uninstall(array $stepParams = []);

	public function __construct(\XF\AddOn\AddOn $addOn, \XF\App $app)
	{
		$this->addOn = $addOn;
		$this->app = $app;
	}

	/**
	 * Perform additional requirement checks.
	 *
	 * @param array $errors Errors will block the setup from continuing
	 * @param array $warnings Warnings will be displayed but allow the user to continue setup
	 *
	 * @return void
	 */
	public function checkRequirements(&$errors = [], &$warnings = [])
	{
		return;
	}

	public function postInstall(array &$stateChanges)
	{
	}

	public function postUpgrade($previousVersion, array &$stateChanges)
	{
	}

	public function onActiveChange($newActive, array &$jobList)
	{
	}

	public function createWidget($widgetKey, $definitionId, array $config, $title = '')
	{
		/** @var \XF\Entity\Widget $widget */
		$widget = $this->app->em()->create('XF:Widget');
		$widget->widget_key = $widgetKey;
		$widget->definition_id = $definitionId;
		$widget->bulkSet($config);
		$widget->save(false);

		$masterTitle = $widget->getMasterPhrase();
		$masterTitle->phrase_text = $title;
		$masterTitle->save(false);
	}

	public function deleteWidget($widgetKey)
	{
		$widget = $this->app->finder('XF:Widget')->where('widget_key', $widgetKey)->fetchOne();
		if (!$widget)
		{
			return;
		}
		$widget->delete(false);
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

	/**
	 * @param $sql
	 * @param array $bind
	 * @param bool $suppressAll
	 *
	 * @return bool|\XF\Db\AbstractStatement
	 */
	protected function query($sql, $bind = [], $suppressAll = false)
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
			if (preg_match('/(have an error in your SQL syntax|table \'.*\' doesn\'t exist|Unknown column|doesn\'t have a default value)/i', $message))
			{
				// we don't want to suppress errors in the query that should generally be corrected
				throw $e;
			}

			return false;
		}
	}
}