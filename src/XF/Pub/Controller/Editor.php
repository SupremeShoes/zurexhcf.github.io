<?php

namespace XF\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class Editor extends AbstractController
{
	public function actionDialog()
	{
		$dialog = preg_replace('/[^a-zA-Z0-9]/', '', $this->filter('dialog', 'str'));

		$data = $this->loadDialog($dialog);
		if (!$data['template'])
		{
			// prevents errors from being logged -- must explicitly define valid dialogs
			return $this->notFound();
		}

		return $this->view($data['view'], $data['template'], $data['params']);
	}

	protected function loadDialog($dialog)
	{
		$view = 'XF:Editor\Dialog';
		$template = null;
		$params = [];

		if ($dialog == 'code')
		{
			/** @var \XF\Data\CodeLanguage $codeLanguageData */
			$codeLanguageData = $this->data('XF:CodeLanguage');
			$params['languages'] = $codeLanguageData->getSupportedLanguages(true);
			$template = "editor_dialog_code";
		}
		else if ($dialog == 'media')
		{
			/** @var \XF\Repository\BbCodeMediaSite $mediaRepo */
			$mediaRepo = $this->repository('XF:BbCodeMediaSite');
			$params['sites'] = $mediaRepo->findActiveMediaSites()->fetch();
			$template = "editor_dialog_media";
		}
		else if ($dialog == 'spoiler')
		{
			$template = "editor_dialog_spoiler";
		}

		$data = [
			'dialog' => $dialog,
			'view' => $view,
			'template' => $template,
			'params' => $params
		];

		$this->app->fire('editor_dialog', [&$data, $this], $dialog);

		return $data;
	}

	public function actionMedia()
	{
		$this->assertPostOnly();

		/** @var \XF\Repository\BbCodeMediaSite $mediaRepo */
		$mediaRepo = $this->repository('XF:BbCodeMediaSite');

		$url = $this->filter('url', 'str');
		$sites = $mediaRepo->findActiveMediaSites()->fetch();
		$match = $mediaRepo->urlMatchesMediaSiteList($url, $sites);

		$jsonParams = [];
		if ($match)
		{
			$jsonParams['matchBbCode'] = '[MEDIA=' . $match['media_site_id'] . ']' . $match['media_id'] . '[/MEDIA]';
		}
		else
		{
			$jsonParams['noMatch'] = \XF::phrase('specified_url_cannot_be_embedded_as_media');
		}

		$view = $this->view('XF:Editor\Media', '', []);
		$view->setJsonParams($jsonParams);
		return $view;
	}

	public function actionSmilies()
	{
		$smiliesInfo = $this->repository('XF:Smilie')->getSmilieListData(true);

		$viewParams = [
			'smiliesInfo' => $smiliesInfo
		];
		return $this->view('XF:Editor\Smilies', 'editor_smilies', $viewParams);
	}

	public function actionToBbCode()
	{
		$this->assertPostOnly();

		$html = $this->filter('html', 'str,no-clean');
		$bbCode = $this->plugin('XF:Editor')->convertToBbCode($html);

		$view = $this->view('XF:Editor\ToBbCode', '', []);
		$view->setJsonParams([
			'bbCode' => $bbCode
		]);
		return $view;
	}

	public function actionToHtml()
	{
		$this->assertPostOnly();

		$bbCode = $this->filter('bb_code', 'str');

		$editorHtml = $this->app->bbCode()->render($bbCode, 'editorHtml', 'editor', null, [
			'attachments' => $this->getAvailableAttachments()
		]);

		$view = $this->view('XF:Editor\ToHtml', '', []);
		$view->setJsonParams([
			'editorHtml' => $editorHtml
		]);
		return $view;
	}

	protected function getAvailableAttachments()
	{
		$rawAttachmentData = $this->filter('attachment_hash_combined', 'json-array');
		$attachmentData = $this->filterArray($rawAttachmentData, [
			'type' => 'str',
			'context' => 'array-str',
			'hash' => 'str'
		]);

		$attachRepo = $this->repository('XF:Attachment');

		$handler = $attachRepo->getAttachmentHandler($attachmentData['type']);
		if (!$handler)
		{
			return [];
		}

		if (!$handler->canManageAttachments($attachmentData['context'], $error))
		{
			return [];
		}

		$manipulator = new \XF\Attachment\Manipulator(
			$handler, $attachRepo, $attachmentData['context'], $attachmentData['hash']
		);
		$existing = $manipulator->getExistingAttachments();
		$new = $manipulator->getNewAttachments();

		return $existing + $new;
	}

	public function updateSessionActivity($action, ParameterBag $params, AbstractReply &$reply) {}
}