<?php

ignore_user_abort(true);

$dir = __DIR__;
require($dir . '/src/XF.php');

XF::start($dir);
$app = XF::setupApp('XF\Pub\App');
$app->setup();

if (\XF::$versionId == $app->options()->currentVersionId)
{
	$jobManager = $app->jobManager();
	$jobResult = $jobManager->runQueue(false, $app->config('jobMaxRunTime'));
	if (!$jobResult)
	{
		// nothing was runnable
		$more = false;
	}
	else
	{
		$more = $jobManager->queuePending(false);
	}

	$output = ['more' => $more];
}
else
{
	$output = ['more' => false, 'skipped' => true];
}

header('Content-Type: application/json; charset=UTF-8');
header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
echo json_encode($output);