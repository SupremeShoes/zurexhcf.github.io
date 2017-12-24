<?php

namespace XF\Authentication;

class Core12 extends AbstractAuth
{
	protected function getHandler()
	{
		return new PasswordHash(\XF::config('passwordIterations'), false);
	}

	public function generate($password)
	{
		if (function_exists('password_hash'))
		{
			// TODO: Consider switching to PASSWORD_ARGON2I at a later date.
			$algo = PASSWORD_BCRYPT;

			$hash = password_hash($password, $algo, [
				'cost' => \XF::config('passwordIterations')
			]);
		}
		else
		{
			$hash = $this->getHandler()->HashPassword($password);
		}

		return [
			'hash' => $hash
		];
	}

	public function authenticate($userId, $password)
	{
		if (!is_string($password) || $password === '' || empty($this->data))
		{
			return false;
		}

		if (!preg_match('/^(?:\$(P|H)\$|[^\$])/i',  $this->data['hash'], $match)
			&& function_exists('password_verify')
		)
		{
			return password_verify($password, $this->data['hash']);
		}
		else
		{
			return $this->getHandler()->CheckPassword($password, $this->data['hash']);
		}
	}

	public function isUpgradable()
	{
		if (!empty($this->data['hash']))
		{
			$passwordHash = $this->getHandler();
			$expectedIterations = min(intval(\XF::config('passwordIterations')), 30);
			$iterations = null;

			if (preg_match('/^\$(P|H)\$(.)/i',  $this->data['hash'], $match))
			{
				$iterations = $passwordHash->reverseItoA64($match[2]) - 5; // 5 iterations removed in PHP 5
			}
			else if (preg_match('/^\$2a\$(\d+)\$.*$/i', $this->data['hash'], $match))
			{
				$iterations = intval($match[1]);
			}

			return $expectedIterations !== $iterations;
		}

		return true;
	}

	public function getAuthenticationName()
	{
		return 'XF:Core12';
	}
}