<?php

namespace XF\Admin\Controller;

use XF\Mvc\ParameterBag;

class Tools extends AbstractController
{
	public function actionIndex()
	{
		return $this->view('XF:Tools', 'tools');
	}

	public function actionRebuild()
	{
		$this->setSectionContext('rebuildCaches');
		$this->assertAdminPermission('rebuildCache');

		if ($this->isPost())
		{
			$job = $this->filter('job', 'str');
			$options = $this->filter('options', 'array');

			$runner = $this->app->job($job, null, $options);
			if ($runner && $runner->canTriggerByChoice())
			{
				$uniqueId = 'Rebuild' . $job;
				$id = $this->app->jobManager()->enqueueUnique(
					$uniqueId, $job, $options
				);

				$reply = $this->redirect(
					$this->buildLink('tools/run-job', null, [
						'only_id' => $id,
						'_xfRedirect' => $this->buildLink('tools/rebuild', null, ['success' => 1])
					])
				);
				$reply->setPageParam('skipManualJobRun', true);
				return $reply;
			}
			else
			{
				return $this->error(\XF::phrase('this_cache_could_not_be_rebuilt'), 500);
			}
		}
		else
		{
			$viewParams = [
				'success' => $this->filter('success', 'bool'),
				'hasStoppedManualJobs' => $this->app->jobManager()->hasStoppedManualJobs()
			];
			return $this->view('XF:Tools\Rebuild', 'tools_rebuild', $viewParams);
		}
	}

	public function actionRunJob()
	{
		$redirect = $this->getDynamicRedirect(null, false);

		$jobManager = $this->app->jobManager();

		$onlyIdsComma = $this->filter('only_ids', 'str');
		if ($onlyIdsComma)
		{
			$onlyIds = array_map('intval', explode(',', $onlyIdsComma));
		}
		else
		{
			$onlyIds = [];
		}

		$onlyId = $this->filter('only_id', 'uint');
		if ($onlyId)
		{
			array_unshift($onlyIds, $onlyId);
		}

		$only = $this->filter('only', 'str');
		if ($only)
		{
			$onlyByName = $jobManager->getUniqueJob($only);
			if ($onlyByName)
			{
				$onlyIds[] = $onlyByName['job_id'];
			}
		}

		$canCancel = false;
		$skipManualJobRun = false;
		$status = '';
		$jobId = null;

		if ($this->isPost())
		{
			// we may be doing an add-on action, so lets make sure that any errors get logged
			\XF::app()->error()->setIgnorePendingUpgrade(true);

			// force errors onto this page -- otherwise errors display the standard wrapper which can
			// cause people to leave which creates other problems
			$this->setViewOption('force_page_template', 'PAGE_RUN_JOB');

			$cancel = $this->filter('cancel', 'uint');
			if ($cancel)
			{
				$job = $jobManager->getJob($cancel);
				if ($job)
				{
					$runner = $jobManager->getJobRunner($job);
					if ($runner && $runner->canCancel())
					{
						$jobManager->cancelJob($job);
					}
				}
			}

			$maxRunTime = $this->app->config('jobMaxRunTime');

			if ($onlyIds)
			{
				$runResult = $jobManager->runByIds($onlyIds, $maxRunTime);
				$result = $runResult['result'];
				$onlyIds = $runResult['remaining'];

				if ($jobManager->hasManualEnqueued())
				{
					$extraIds = $jobManager->getManualEnqueued();
					foreach ($extraIds AS $extraId)
					{
						$onlyIds[] = $extraId;
					}

					$skipManualJobRun = true;
				}

				$continue = !empty($onlyIds);
			}
			else
			{
				$result = $jobManager->runQueue(true, $this->app->config('jobMaxRunTime'));
				$continue = $jobManager->queuePending(true);
			}

			if (!$continue)
			{
				// if we had manual jobs added, this will never be hit so we don't need to skip
				return $this->redirect($redirect);
			}

			if ($result)
			{
				$canCancel = $result->canCancel && !$result->completed;
				$status = $result->statusMessage;
				$jobId = $result->jobId;
			}
		}

		if (!$jobId)
		{
			if ($onlyIds)
			{
				$firstId = reset($onlyIds);
				$job = $jobManager->getJob($firstId);
			}
			else
			{
				$job = $jobManager->getFirstRunnable(true);
			}
			if ($job)
			{
				$runner = $jobManager->getJobRunner($job);
				if ($runner)
				{
					$canCancel = $runner->canCancel();
					$status = $runner->getStatusMessage();
					$jobId = $runner->getJobId();
				}
			}
		}

		$viewParams = [
			'canCancel' => $canCancel,
			'status' => $status,
			'jobId' => $jobId,
			'redirect' => $redirect,
			'onlyIds' => $onlyIds
		];
		$reply = $this->view('XF:Tools\RunJob', 'tools_run_job', $viewParams);
		$reply->setPageParam('skipManualJobRun', $skipManualJobRun);

		return $reply;
	}
	
	public function actionCleanUpPermissions()
	{
		$this->setSectionContext('rebuildCaches');
		$this->assertPostOnly();
		$this->assertAdminPermission('rebuildCache');

		$permComboRepo = $this->repository('XF:PermissionCombination');
		$permComboRepo->deleteUnusedPermissionCombinations();

		$missing = $permComboRepo->insertGuestCombinationIfMissing();
		if ($missing)
		{
			$this->app->jobManager()->enqueueUnique('permissionRebuild', 'XF:PermissionRebuild');
		}

		return $this->redirect($this->buildLink('tools/rebuild', null, ['success' => 1]));
	}

	public function actionTestEmail()
	{
		$this->setSectionContext('testOutboundEmail');

		$emailValidator = $this->app->validator('Email');
		$options = $this->options();

		$email = $this->filter('email', 'str', $options->contactEmailAddress ?: $options->defaultEmailAddress);
		$email = $emailValidator->coerceValue($email);

		$mailer = $this->app->mailer();
		$transport = $mailer->getDefaultTransport();

		if ($this->isPost() && $emailValidator->isValid($email))
		{
			$mail = $mailer->newMail();
			$mail->setTo($email);
			$mail->setContent(
				\XF::phrase('outbound_email_test_subject', ['board' => $this->options()->boardTitle]),
				\XF::phrase('outbound_email_test_body', ['username' => \XF::visitor()->username, 'board' => $this->options()->boardTitle])
			);

			$logger = new \Swift_Plugins_Loggers_ArrayLogger();
			$transport->registerPlugin(new \Swift_Plugins_LoggerPlugin($logger));

			$code = $mail->send($transport);
			$log = $logger->dump();

			$result = [
				'code' => $code,
				'log' => $log
			];
		}
		else
		{
			$result = false;
		}

		$viewParams = [
			'email' => $email,
			'transportClass' => get_class($transport),
			'result' => $result
		];
		return $this->view('XF:Tools\TestMail', 'tools_test_mail', $viewParams);
	}

	public function actionTestImageProxy()
	{
		$this->setSectionContext('testImageProxy');

		$urlValidator = \XF::app()->validator('Url');

		$url = $this->filter('url', 'str');
		$url = $urlValidator->coerceValue($url);

		if ($this->isPost() && $url && $urlValidator->isValid($url))
		{
			/** @var \XF\Service\ImageProxy $proxyService */
			$proxyService = $this->service('XF:ImageProxy');
			$results = $proxyService->testImageFetch($url);
		}
		else
		{
			$results = false;
		}

		$viewParams = [
			'url' => $url,
			'results' => $results
		];
		return $this->view('XF:Tools\TestImageProxy', 'tools_test_image_proxy', $viewParams);
	}

	public function actionFileCheck()
	{
		if ($this->isPost())
		{
			$fileCheck = $this->em()->create('XF:FileCheck');
			$fileCheck->save();

			$this->app->jobManager()->enqueueUnique(
				'fileCheck', 'XF:FileCheck', [
					'check_id' => $fileCheck->check_id
				]
			);

			$reply = $this->redirect(
				$this->buildLink('tools/run-job', null, [
					'only' => 'fileCheck',
					'_xfRedirect' => $this->buildLink('tools/file-check/results', $fileCheck)
				])
			);
			$reply->setPageParam('skipManualJobRun', true);
			return $reply;
		}
		else
		{
			$page = $this->filterPage();
			$perPage = 20;

			/** @var \XF\Repository\FileCheck $fileCheckRepo */
			$fileCheckRepo = $this->repository('XF:FileCheck');
			$fileCheckFinder = $fileCheckRepo->findFileChecksForList()
				->limitByPage($page, $perPage);

			$viewParams = [
				'fileChecks' => $fileCheckFinder->fetch(),
				'page' => $page,
				'perPage' => $perPage,
				'total' => $fileCheckFinder->total()
			];
			return $this->view('XF:Tools\FileCheck', 'tools_file_check', $viewParams);
		}
	}

	public function actionFileCheckResults(ParameterBag $params)
	{
		/** @var \XF\Entity\FileCheck $fileCheck */
		$fileCheck = $this->assertRecordExists('XF:FileCheck', $params->check_id);

		$addOns = $this->repository('XF:AddOn')->findAddOnsForList();

		$viewParams = [
			'addOns' => $addOns->fetch(),
			'fileCheck' => $fileCheck,
			'results' => $fileCheck->results
		];
		return $this->view('XF:Tools\FileCheckResult', 'tools_file_check_result', $viewParams);
	}

	public function actionTransmogrifier()
	{
		return $this->view('XF:Tools\Transmogrifer', 'tools_transmogrifer');
	}

	public function actionTransmogrifierReset()
	{
		$this->assertPostOnly();

		$simpleCache = $this->app->simpleCache();
		$transmogrifierCount = $simpleCache['XF']['transmogrifierCount'] += 1;

		$viewParams = [
			'count' => $transmogrifierCount
		];
		return $this->view('XF:Tools\Transmogrifer\Reset', 'tools_transmogrifer_reset', $viewParams);
	}

	public function actionPhpinfo()
	{
		phpinfo();
		die();
	}
}