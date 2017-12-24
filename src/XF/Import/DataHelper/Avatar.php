<?php

namespace XF\Import\DataHelper;

class Avatar extends AbstractHelper
{
	public function copyFinalAvatarFile($sourceFile, $size, \XF\Entity\User $target)
	{
		$targetPath = $target->getAbstractedCustomAvatarPath($size);
		return \XF\Util\File::copyFileToAbstractedPath($sourceFile, $targetPath);
	}

	public function copyFinalAvatarFiles(array $sourceFileMap, \XF\Entity\User $target)
	{
		$success = true;
		foreach ($sourceFileMap AS $size => $sourceFile)
		{
			if (!$this->copyFinalAvatarFile($sourceFile, $size, $target))
			{
				$success = false;
				break;
			}
		}

		return $success;
	}

	public function setAvatarFromFile($sourceFile, \XF\Entity\User $user)
	{
		/** @var \XF\Service\User\Avatar $avatarService */
		$avatarService = $this->dataManager->app()->service('XF:User\Avatar', $user);

		if ($avatarService->setImage($sourceFile))
		{
			$avatarService->updateAvatar();
		}
	}
}