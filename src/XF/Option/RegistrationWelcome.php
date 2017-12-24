<?php

namespace XF\Option;

class RegistrationWelcome extends AbstractOption
{
	/**
	 * @param array $values
	 * @param \XF\Entity\Option $option
	 *
	 * @return bool
	 */
	public static function verifyOption(array &$values, \XF\Entity\Option $option)
	{
		if ($option->isInsert())
		{
			// insert - just trust the default value
			return true;
		}

		if (!empty($values['messageEnabled']))
		{
			$participants = preg_split('#\s*,\s*#', $values['messageParticipants'], -1, PREG_SPLIT_NO_EMPTY);

			$userRepo = \XF::repository('XF:User');
			$users = $userRepo->getUsersByNames($participants, $notFound, [], false);

			$starter = $users->shift();
			if (!$starter)
			{
				$option->error(\XF::phrase('please_enter_at_least_one_valid_recipient'), $option->option_id);
				return false;
			}

			if ($notFound)
			{
				$option->error(\XF::phrase('the_following_recipients_could_not_be_found_x', ['names' => implode(', ', $notFound)]), $option->option_id);
				return false;
			}

			$values['messageParticipants'] = $users->keys();

			if (!$values['messageTitle'] && !$values['messageBody'])
			{
				$option->error(\XF::phrase('please_enter_valid_welcome_conversation_contents'), $option->option_id);
				return false;
			}
		}

		if (!empty($values['emailEnabled']) && !strlen(trim($values['emailBody'])))
		{
			$option->error(\XF::phraseDeferred('you_must_enter_email_message_to_enable_welcome_email'), $option->option_id);
			return false;
		}

		return true;
	}
}