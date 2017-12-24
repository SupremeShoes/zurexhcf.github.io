<?php

namespace XF\Import;

use XF\Import\Importer\AbstractImporter;

class Helper
{
	/**
	 * @var AbstractImporter
	 */
	protected $importer;

	public function __construct(AbstractImporter $importer)
	{
		$this->importer = $importer;
	}

	public function importUser($oldId, \XF\Import\Data\User $user, array $stepConfig)
	{
		$db = $this->importer->getDataManager()->db();

		$originalEmail = $user->email;

		if ($user->email && !strpos($user->email, '@'))
		{
			// if the email doesn't have a @, it isn't valid anyway, but this will break our conflict resolution
			$user->email = '';
		}

		if ($user->email)
		{
			$matchByEmail = $db->fetchOne("SELECT user_id FROM xf_user WHERE email = ?", $user->email);
			if ($matchByEmail)
			{
				if (!empty($stepConfig['merge_email']))
				{
					return $this->mergeUser($oldId, $user, $matchByEmail);
				}
				else
				{
					// not merging, so change the email and log it
					$i = 0;

					do
					{
						$i++;
						$replace = $i > 1 ? "+xf{$i}@" : '+xf@';
						$testEmail = str_replace('@', $replace, $originalEmail);

						$hasConflict = $db->fetchOne("SELECT user_id FROM xf_user WHERE email = ?", $testEmail);
					}
					while ($hasConflict);

					$user->email = $testEmail;
				}
			}
		}
		else
		{
			$matchByEmail = null;
		}

		$originalUsername = $user->username;

		if (strpos($user->username, ',') !== false)
		{
			$user->username = preg_replace('#,\s+#', ' ', $user->username);
			$user->username = str_replace(',', '', $user->username);
			if (!strlen($user->username))
			{
				$user->username = 'comma';
			}

			$hasComma = true;
		}
		else
		{
			$hasComma = false;
		}

		$matchByName = $db->fetchOne("SELECT user_id FROM xf_user WHERE username = ?", $user->username);
		if ($matchByName)
		{
			if (!empty($stepConfig['merge_name']))
			{
				return $this->mergeUser($oldId, $user, $matchByName);
			}
			else
			{
				// not merging, so change the name and log it
				$i = 0;

				do
				{
					$i++;
					$testUsername = $originalUsername . $i;

					$hasConflict = $db->fetchOne("SELECT user_id FROM xf_user WHERE username = ?", $testUsername);
				}
				while ($hasConflict);

				$user->username = $testUsername;
			}
		}

		$newId = $user->save($oldId);

		$session = $this->importer->getSession();
		if ($matchByEmail)
		{
			$session->notes['userEmailConflict'][$newId] = $originalEmail;
		}
		if ($matchByName)
		{
			$session->notes['userNameConflict'][$newId] = $originalUsername;
		}
		else if ($hasComma)
		{
			$session->notes['userNameConflict'][$newId] = $originalUsername;
		}

		return $newId;
	}

	protected function mergeUser($oldId, \XF\Import\Data\User $user, $targetUserId)
	{
		$mergedInto = $user->mergeFromInto($oldId, $targetUserId);

		return $mergedInto;
	}

	public function mapUserGroupList($userGroups)
	{
		if (is_string($userGroups))
		{
			if (!strlen($userGroups))
			{
				return [];
			}

			$userGroups = explode(',', $userGroups);
		}
		if (is_numeric($userGroups))
		{
			$userGroups = [$userGroups];
		}
		if (!is_array($userGroups))
		{
			throw new \InvalidArgumentException("Could not get user group array list");
		}

		$ids = $this->importer->lookup('user_group', $userGroups);
		foreach ($ids AS $k => $v)
		{
			if ($v === false)
			{
				unset($ids[$k]);
			}
		}
		return array_values($ids);
	}

	public function setupXfCustomFieldImport($fieldType, array $sourceData)
	{
		$data = $this->importer->mapKeys($sourceData, [
			'field_id',
			'display_group',
			'display_order',
			'field_type',
			'match_type',
			'max_length',
			'required',
			'user_editable',
			'moderator_editable',
			'display_template'
		], true);

		/** @var \XF\Import\Data\AbstractField $import */
		$import = $this->importer->newHandler($fieldType);
		$import->bulkSet($data);

		if (isset($sourceData['editable_user_group_ids']))
		{
			if ($import->editable_user_group_ids == '-1')
			{
				$import->editable_user_group_ids = [-1];
			}
			else
			{
				$import->editable_user_group_ids = $this->mapUserGroupList($sourceData['editable_user_group_ids']);
			}
		}

		$import->match_params = $this->importer->decodeValue($sourceData['match_params'], 'json-array');
		$import->field_choices = $this->importer->decodeValue($sourceData['field_choices'], 'serialized-array');

		$description = isset($sourceData['description']) ? $sourceData['description'] : null;
		if (isset($sourceData['title']))
		{
			$import->setTitle($sourceData['title'], $description);
		}

		return $import;
	}
}