<?php

namespace XF\Finder;

use XF\Mvc\Entity\Finder;

class ProfilePost extends Finder
{
	public function forFullView($withProfile = false)
	{
		$this->with('User');

		if ($withProfile)
		{
			$this->with(['ProfileUser', 'ProfileUser.Privacy']);
		}

		$userId = \XF::visitor()->user_id;
		if ($userId)
		{
			$this->with('Likes|' . $userId);
		}

		return $this;
	}

	public function onProfile(\XF\Entity\User $user, array $limits = [])
	{
		$limits = array_replace([
			'visibility' => true,
			'allowOwnPending' => true
		], $limits);

		$this->where('profile_user_id', $user->user_id);

		if ($limits['visibility'])
		{
			$this->applyVisibilityChecksForProfile($user, $limits['allowOwnPending']);
		}

		$this->forFullView();

		return $this;
	}

	public function applyVisibilityChecksForProfile(\XF\Entity\User $user, $allowOwnPending = true)
	{
		$conditions = [];
		$viewableStates = ['visible'];

		if ($user->canViewDeletedPostsOnProfile())
		{
			$viewableStates[] = 'deleted';
			$this->with('DeletionLog');
		}

		$visitor = \XF::visitor();
		if ($user->canViewModeratedPostsOnProfile())
		{
			$viewableStates[] = 'moderated';
		}
		else if ($visitor->user_id && $allowOwnPending)
		{
			$conditions[] = [
				'message_state' => 'moderated',
				'user_id' => $visitor->user_id
			];
		}

		$conditions[] = ['message_state', $viewableStates];

		$this->whereOr($conditions);

		return $this;
	}

	public function newerThan($date)
	{
		$this->where('post_date', '>', $date);

		return $this;
	}
}