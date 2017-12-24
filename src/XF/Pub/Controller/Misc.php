<?php

namespace XF\Pub\Controller;

class Misc extends AbstractController
{
	protected function setupContactService()
	{
		/** @var \XF\Service\Contact $contactService */
		$contactService = $this->service('XF:Contact');

		$visitor = \XF::visitor();

		$input = $this->filter([
			'username' => 'str',
			'email' => 'str',
			'subject' => 'str',
			'message' => 'str'
		]);

		if ($visitor->user_id)
		{
			$contactService->setFromUser($visitor);
			if (!$visitor->email)
			{
				if (!$contactService->setEmail($input['email'], $error))
				{
					throw $this->exception($this->error($error));
				}
			}
		}
		else
		{
			$contactService->setFromGuest($input['username'], $input['email']);
		}

		$contactService
			->setMessageDetails($input['subject'], $input['message'])
			->setFromIp($this->request->getIp());

		return $contactService;
	}

	public function actionContact()
	{
		$options = $this->options();
		if ($options->contactUrl['type'] == 'custom')
		{
			return $this->redirect($options->contactUrl['custom']);
		}
		else if (!$options->contactUrl['type'])
		{
			return $this->redirect($this->buildLink('index'));
		}

		if (!\XF::visitor()->canUseContactForm())
		{
			return $this->noPermission();
		}

		$redirect = $this->getDynamicRedirect(null, false);

		if ($this->isPost())
		{
			$contactService = $this->setupContactService();
			if (!$contactService->validate($errors))
			{
				return $this->error($errors);
			}

			if (!$this->captchaIsValid())
			{
				return $this->error(\XF::phrase('did_not_complete_the_captcha_verification_properly'));
			}

			$this->assertNotFlooding('contact');

			$contactService->send();

			return $this->redirect($redirect, \XF::phrase('your_message_has_been_sent'));
		}
		else
		{
			$viewParams = [
				'redirect' => $redirect
			];
			return $this->view('XF:Misc\Contact', 'contact_form', $viewParams);
		}
	}

	public function actionLanguage()
	{
		$visitor = \XF::visitor();
		if (!$visitor->canChangeLanguage($error))
		{
			return $this->noPermission($error);
		}

		$redirect = $this->getDynamicRedirect(null, true);

		if ($this->request->exists('language_id'))
		{
			$this->assertValidCsrfToken($this->filter('t', 'str'));

			$language = $this->app->language($this->filter('language_id', 'uint'));

			if ($visitor->user_id)
			{
				$visitor->language_id = $language->getId();
				$visitor->save();

				$this->app->response()->setCookie('language_id', false);
			}
			else
			{
				$this->app->response()->setCookie('language_id', $language->getId());
			}
			return $this->redirect($redirect);
		}
		else
		{
			$viewParams = [
				'redirect' => $redirect,
				'languageTree' => $this->repository('XF:Language')->getLanguageTree(false)
			];
			return $this->view('XF:Misc\Language', 'language_chooser', $viewParams);
		}
	}

	public function actionStyle()
	{
		$visitor = \XF::visitor();
		if (!$visitor->canChangeStyle($error))
		{
			return $this->noPermission($error);
		}

		$redirect = $this->getDynamicRedirect(null, true);

		$csrfValid = true;
		if ($visitor->user_id)
		{
			$csrfValid = $this->validateCsrfToken($this->filter('t', 'str'));
		}

		if ($this->request->exists('style_id') && $csrfValid)
		{
			$style = $this->app->style($this->filter('style_id', 'uint'));

			if ($visitor->user_id)
			{
				$visitor->style_id = $style->getId();
				$visitor->save();

				$this->app->response()->setCookie('style_id', false);
			}
			else
			{
				$this->app->response()->setCookie('style_id', $style->getId());
			}
			return $this->redirect($redirect);
		}
		else
		{
			$styles = $this->repository('XF:Style')->getSelectableStyles();

			$styleId = $this->filter('style_id', 'uint');
			if ($styleId && !empty($styles[$styleId]['user_selectable']))
			{
				$style = $styles[$styleId];
			}
			else
			{
				$style = false;
			}

			$viewParams = [
				'redirect' => $redirect,
				'style' => $style,
				'styles' => $styles
			];
			return $this->view('XF:Misc\Style', 'style_chooser', $viewParams);
		}
	}

	public function actionCaptcha()
	{
		$withRow = $this->filter('with_row', 'bool');
		$rowType = preg_replace('#[^a-z0-9_ -]#i', '', $this->filter('row_type', 'str'));

		return $this->view('XF:Misc\Captcha', 'captcha', [
			'withRow' => $withRow,
			'rowType' => $rowType
		]);
	}

	public function actionIpInfo()
	{
		if (!\XF::visitor()->canViewIps())
		{
			return $this->noPermission();
		}

		$ip = $this->filter('ip', 'str');
		$url = $this->options()->ipInfoUrl;

		if (strpos($url, '{ip}') === false)
		{
			$url = 'http://whatismyipaddress.com/ip/{ip}/';
		}

		return $this->redirectPermanently(str_replace('{ip}', urlencode($ip), $url));
	}

	public function actionLocationInfo()
	{
		$location = $this->filter('location', 'str');

		$url = $this->options()->geoLocationUrl;
		if (strpos($url, '{location}') === false)
		{
			$url = 'https://maps.google.com/maps?q={location}/';
		}

		return $this->redirectPermanently(str_replace('{location}', urlencode($location), $url));
	}

	public function actionTagAutoComplete()
	{
		if (!$this->options()->enableTagging)
		{
			return $this->noPermission();
		}

		$tagRepo = $this->repository('XF:Tag');

		$q = $this->filter('q', 'str');
		$q = $tagRepo->normalizeTag($q);

		if (strlen($q) >= 2)
		{
			$tags = $this->repository('XF:Tag')->getTagAutoCompleteResults($q);
			
			$results = [];
			foreach ($tags AS $tag)
			{
				$results[] = [
					'id' => $tag->tag,
					'icon' => null,
					'text' => $tag->tag,
					'q' => $q
				];
			}
		}
		else
		{
			$results = [];
		}
		$view = $this->view();
		$view->setJsonParam('results', $results);
		return $view;
	}

	public function actionCodeEditorModeLoader()
	{
		$language = $this->filter('language', 'str');

		/** @var \XF\ControllerPlugin\CodeEditor $plugin */
		$plugin = $this->plugin('XF:CodeEditor');

		return $plugin->actionModeLoader($language);
	}

	public static function getActivityDetails(array $activities)
	{
		$output = [];

		foreach ($activities AS $key => $activity)
		{
			if (strtolower($activity->controller_action) == 'contact')
			{
				$output[$key] = \XF::phrase('contacting_staff');
			}
			else
			{
				$output[$key] = false;
			}
		}

		return $output;
	}

	public function assertNotRejected($action)
	{
		if (strtolower($action) == 'contact')
		{
			// bypass rejection for the default contact form
		}
		else
		{
			parent::assertNotRejected($action);
		}
	}

	public function assertNotDisabled($action)
	{
		if (strtolower($action) == 'contact')
		{
			// bypass disabled notice for the default contact form
		}
		else
		{
			parent::assertNotRejected($action);
		}
	}

	public function assertViewingPermissions($action)
	{
		if (strtolower($action) == 'contact')
		{
			// bypass viewing permissions for the default contact form
		}
		else
		{
			parent::assertViewingPermissions($action);
		}
	}

	public function actionException()
	{
		throw $this->exception($this->error('ERROR'));
	}
}