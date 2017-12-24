<?php

namespace XF\Pub;

use XF\Container;
use XF\HTTP\Response;
use XF\Mvc\Renderer\AbstractRenderer;
use XF\Mvc\Reply\AbstractReply;

class App extends \XF\App
{
	protected $preLoadLocal = [
		'bannedIps',
		'bbCodeCustom',
		'discouragedIps',
		'notices',
		'noticesLastReset',
		'routeFilters',
		'routesPublic',
		'styles',
		'userFieldsInfo',
		'threadFieldsInfo',
		'threadPrefixes'
	];

	public function initializeExtra()
	{
		$container = $this->container;

		$container['app.classType'] = 'Pub';
		$container['app.defaultType'] = 'public';

		$container['router'] = function (Container $c)
		{
			return $c['router.public'];
		};
		$container['session'] = function (Container $c)
		{
			return $c['session.public'];
		};
	}

	public function setup(array $options = [])
	{
		parent::setup($options);
		$this->assertConfigExists();

		$this->fire('app_pub_setup', [$this]);
	}

	public function start($allowShortCircuit = false)
	{
		parent::start($allowShortCircuit);

		$this->fire('app_pub_start_begin', [$this]);

		if ($allowShortCircuit)
		{
			$request = $this->request();

			switch ($request->getRequestUri())
			{
				case '/browserconfig.xml':
				case '/crossdomain.xml':
				case '/favicon.ico':
				case '/robots.txt':
					$response = $this->response();
					$response->httpCode(404);
					return $response;
			}

			$extendedUrl = ltrim($request->getExtendedUrl(), '/');
			$sitemapCounter = null;
			if ($extendedUrl == 'sitemap.xml')
			{
				$sitemapCounter = 0;
			}
			else if (preg_match('#^sitemap-(\d+)\.xml$#', $extendedUrl, $match))
			{
				$sitemapCounter = intval($match[1]);
			}

			if ($sitemapCounter !== null)
			{
				/** @var \XF\Sitemap\Renderer $renderer */
				$renderer = $this['sitemap.renderer'];
				return $renderer->outputSitemap($this->response(), $sitemapCounter);
			}
		}

		$session = $this->session();
		if (!$session->exists())
		{
			$this->onSessionCreation($session);
		}

		$user = $this->getVisitorFromSession($session);
		\XF::setVisitor($user);

		$visitor = \XF::visitor();
		if ($visitor->user_id)
		{
			$languageId = $visitor->language_id;
		}
		else
		{
			$styleId = intval($this->request()->getCookie('style_id', 0));
			$languageId = intval($this->request()->getCookie('language_id', 0));
			$username = $this->request()->filter('_xfUsername', 'str', '');

			$visitor->setReadOnly(false);
			$visitor->setAsSaved('username', $username);
			$visitor->setAsSaved('style_id', $styleId);
			$visitor->setAsSaved('language_id', $languageId);
			$visitor->setReadOnly(true);
		}

		$language = $this->language($languageId);
		$language->setTimeZone($visitor->timezone);
		\XF::setLanguage($language);

		$this->updateUserCaches();
		$this->updateModeratorCaches();

		$this->fire('app_pub_start_end', [$this]);

		return null;
	}

	protected function updateUserCaches()
	{
		$visitor = \XF::visitor();
		$session = $this->session();

		if (!$visitor->user_id)
		{
			return;
		}

		if ($this->options()->enableNotices)
		{
			if (!$session->keyExists('dismissedNotices'))
			{
				$updateDismissed = true;
			}
			else
			{
				$sessionLastNoticeUpdate = intval($session->get('lastNoticeUpdate'));
				$dbLastNoticeReset = $this->get('notices.lastReset');
				$updateDismissed = ($dbLastNoticeReset > $sessionLastNoticeUpdate);
			}

			if ($updateDismissed)
			{
				$session->dismissedNotices = $this->repository('XF:Notice')->getDismissedNoticesForUser($visitor);
				$session->lastNoticeUpdate = \XF::$time;
			}
		}

		if (!$session->promotionChecked)
		{
			$session->promotionChecked = true;

			// if we've recently been active, let cron handle it
			if ($visitor->getValue('last_activity') > \XF::$time - 1800)
			{
				/** @var \XF\Repository\UserGroupPromotion $userGroupPromotionRepo */
				$userGroupPromotionRepo = $this->repository('XF:UserGroupPromotion');
				$userGroupPromotionRepo->updatePromotionsForUser($visitor);
			}
		}

		if ($this->options()->enableTrophies && !$session->trophyChecked)
		{
			$session->trophyChecked = true;

			// if we've recently been active, let cron handle it
			if ($visitor->getValue('last_activity') > \XF::$time - 1800)
			{
				/** @var \XF\Repository\Trophy $trophyRepo */
				$trophyRepo = $this->repository('XF:Trophy');
				$trophyRepo->updateTrophiesForUser($visitor);
			}
		}

		if (!$session->keyExists('previousActivity'))
		{
			$session->previousActivity = $visitor->getValue('last_activity'); // skip the getter to get what's in the DB
		}
	}

	protected function updateModeratorCaches()
	{
		$visitor = \XF::visitor();
		$session = $this->session();

		if (!$visitor->is_moderator)
		{
			return;
		}

		$sessionReportCounts = $session->reportCounts;
		$registryReportCounts = $this->container->reportCounts;

		if ($sessionReportCounts === null
			|| ($sessionReportCounts && ($sessionReportCounts['lastBuilt'] < $registryReportCounts['lastModified']))
		)
		{
			/** @var \XF\Repository\Report $reportRepo */
			$reportRepo = $this->repository('XF:Report');

			$reports = $this->finder('XF:Report')->isActive()->fetch();
			$reports = $reportRepo->filterViewableReports($reports);

			$total = 0;
			$assigned = 0;

			foreach ($reports AS $reportId => $report)
			{
				$total++;
				if ($report->assigned_user_id == $visitor->user_id)
				{
					$assigned++;
				}
			}

			$reportCounts = [
				'total' => $total,
				'assigned' => $assigned,
				'lastBuilt' => $registryReportCounts['lastModified']
			];

			$session->reportCounts = $reportCounts;
		}

		$sessionUnapprovedCounts = $session->unapprovedCounts;
		$registryUnapprovedCounts = $this->container->unapprovedCounts;

		if ($sessionUnapprovedCounts === null
			|| ($sessionUnapprovedCounts && ($sessionUnapprovedCounts['lastBuilt'] < $registryUnapprovedCounts['lastModified']))
		)
		{
			/** @var \XF\Repository\ApprovalQueue $approvalQueueRepo */
			$approvalQueueRepo = $this->repository('XF:ApprovalQueue');

			$unapprovedItems = $approvalQueueRepo->findUnapprovedContent()->fetch();
			$approvalQueueRepo->addContentToUnapprovedItems($unapprovedItems);
			$unapprovedItems = $approvalQueueRepo->filterViewableUnapprovedItems($unapprovedItems);

			$unapprovedCounts = [
				'total' => $unapprovedItems->count(),
				'lastBuilt' => $registryUnapprovedCounts['lastModified']
			];

			$session->unapprovedCounts = $unapprovedCounts;
		}
	}

	protected function onSessionCreation(\XF\Session\Session $session)
	{
		if (!$this->loginFromRememberCookie($session))
		{
			$userAgent = $this->request()->getUserAgent();
			$session['robot'] = $this->data('XF:Robot')->userAgentMatchesRobot($userAgent);
		}

		$referrer = $this->request()->getReferrer();
		$session['fromSearch'] = $referrer ? $this->data('XF:Search')->urlMatchesSearchDomain($referrer) : false;
	}

	protected function loginFromRememberCookie(\XF\Session\Session $session)
	{
		$rememberCookie = $this->request()->getCookie('user');
		if (!$rememberCookie)
		{
			return null;
		}

		/** @var \XF\Repository\UserRemember $rememberRepo */
		$rememberRepo = $this->repository('XF:UserRemember');
		if (!$rememberRepo->validateByCookieValue($rememberCookie, $remember))
		{
			$this->response()->setCookie('user', false);
			return null;
		}

		/** @var \XF\Repository\User $userRepo */
		$userRepo = $this->repository('XF:User');
		$user = $userRepo->getVisitor($remember->user_id);
		if (!$user)
		{
			return null;
		}

		$trustKey = $this->request()->getCookie('tfa_trust');

		/** @var \XF\Repository\Tfa $tfaRepo */
		$tfaRepo = $this->repository('XF:Tfa');
		if ($tfaRepo->isUserTfaConfirmationRequired($user, $trustKey))
		{
			$session->tfaLoginUserId = $user->user_id;
			$session->tfaLoginDate = time();
			$session->tfaLoginRedirect = true;

			return null;
		}

		$session->changeUser($user);

		/** @var \XF\Repository\Ip $ipRepo */
		$ipRepo = $this->repository('XF:Ip');
		$ipRepo->logCookieLoginIfNeeded($user->user_id, $this->request()->getIp());

		/** @var \XF\Entity\UserRemember $remember */
		$remember->extendExpiryDate();
		$remember->save();

		return $remember->user_id;
	}

	public function preRender(AbstractReply $reply, $responseType)
	{
		$visitor = \XF::visitor();

		$viewOptions = $reply->getViewOptions();
		if (!empty($viewOptions['style_id']))
		{
			$styleId = $viewOptions['style_id'];
			$forceStyle = true;
		}
		else
		{
			$styleId = $visitor->style_id;
			$forceStyle = false;
		}

		$style = $this->container->create('style', $styleId);
		if ($style['style_id'] == $styleId)
		{
			// true if the style matches the requested one; if it didn't just accept it
			$canUse = ($style['user_selectable'] || $forceStyle || $visitor->is_admin);
			if (!$canUse)
			{
				$style = $this->container->create('style', 0);
			}
		}

		$this->templater()->setStyle($style);

		parent::preRender($reply, $responseType);
	}

	public function complete(Response $response)
	{
		parent::complete($response);

		if ($this->container->isCached('session'))
		{
			$session = $this->session();
			$session->save();
			$session->applyToResponse($response);
		}

		$this->fire('app_pub_complete', [$this, &$response]);
	}

	protected function renderPageHtml($content, array $params, AbstractReply $reply, AbstractRenderer $renderer)
	{
		$templateName = isset($params['template']) ? $params['template'] : 'PAGE_CONTAINER';
		if (!$templateName)
		{
			return $content;
		}

		$templater = $this->templater();

		if (!strpos($templateName, ':'))
		{
			$templateName = 'public:' . $templateName;
		}

		$pageSection = $reply->getSectionContext();
		if (isset($params['section']))
		{
			$pageSection = $params['section'];
			$reply->setSectionContext($pageSection);
		}
		$params['pageSection'] = $pageSection;

		$params['controller'] = $reply->getControllerClass();
		$params['action'] = $reply->getAction();
		$params['actionMethod'] = 'action' . \XF\Util\Php::camelCase($reply->getAction(), '-');

		$params['classType'] = $this->container('app.classType');
		$params['containerKey'] = $reply->getContainerKey();
		$params['contentKey'] = $reply->getContentKey();

		if ($reply instanceof \XF\Mvc\Reply\View)
		{
			$params['view'] = $reply->getViewClass();
			$params['template'] = $reply->getTemplateName();
		}
		else if ($reply instanceof \XF\Mvc\Reply\Error || $reply->getResponseCode() >= 400)
		{
			$params['template'] = 'error';
		}
		else if ($reply instanceof \XF\Mvc\Reply\Message)
		{
			$params['template'] = 'message_page';
		}

		$params['fromSearch'] = $this->session()->fromSearch;
		$params['pageStyleId'] = $templater->getStyleId();

		$navTree = $this->getNavigation($params, $pageSection)['tree'];
		$params['navTree'] = $navTree;

		// note that this intentionally only selects a top level entry
		if (isset($navTree[$pageSection]))
		{
			$selectedNavEntry = $navTree[$pageSection];
		}
		else
		{
			$defaultNavId = $this->get('defaultNavigationId');
			$selectedNavEntry = isset($navTree[$defaultNavId]) ? $navTree[$defaultNavId] : null;
		}

		$params['selectedNavEntry'] = $selectedNavEntry;
		$params['selectedNavChildren'] = !empty($selectedNavEntry['children']) ? $selectedNavEntry['children'] : [];

		$params['content'] = $content;
		$params['notices'] = $this->getNoticeList($params)->getNotices();

		// TODO: These positions should receive some context (could just pass in $params but we want this for non global positions too)
		if ($this->options()->boardActive || \XF::visitor()->is_admin)
		{
			$topWidgets = $templater->widgetPosition('pub_sidebar_top');
			$bottomWidgets = $templater->widgetPosition('pub_sidebar_bottom');
			$templater->modifySidebarHtml('_xfWidgetPositionPubSidebarTop', $topWidgets, 'prepend');
			$templater->modifySidebarHtml('_xfWidgetPositionPubSidebarBottom', $bottomWidgets, 'append');

			$params['sidebar'] = $templater->getSidebarHtml();
			$params['sideNav'] = $templater->getSideNavHtml();
		}

		$this->fire('app_pub_render_page', [$this, &$params, $reply, $renderer]);

		return $templater->renderTemplate($templateName, $params);
	}

	protected function getNavigation(array $params, $selectedNav = '')
	{
		$navigation = null;

		$file = \XF\Util\File::getCodeCachePath() . '/' . $this->container['navigation.file'];
		if (file_exists($file))
		{
			$closure = include($file);
			if ($closure)
			{
				$navigation = $this->templater()->renderNavigationClosure($closure, $selectedNav, $params);
			}
		}

		if (!$navigation || !isset($navigation['tree']))
		{
			$navigation = [
				'tree' => [],
				'flat' => []
			];
		}

		$this->fire('navigation_setup', [$this, &$navigation['flat'], &$navigation['tree']]);

		return $navigation;
	}

	protected function getNoticeList(array $pageParams)
	{
		$class = $this->extendClass('XF\NoticeList');
		/** @var \XF\NoticeList $noticeList */
		$noticeList = new $class($this, \XF::visitor(), $pageParams);

		$dismissedNotices = $this->session()->dismissedNotices;
		if ($dismissedNotices)
		{
			$noticeList->setDismissed($dismissedNotices);
		}
		$this->addDefaultNotices($noticeList, $pageParams);

		if ($this->options()->enableNotices)
		{
			foreach ($this->container('notices') AS $key => $notice)
			{
				$noticeList->addConditionalNotice($key, $notice['notice_type'], $notice['message'], $notice);
			}
		}

		$this->fire('notices_setup', [$this, $noticeList, $pageParams]);

		return $noticeList;
	}

	protected function addDefaultNotices(\XF\NoticeList $noticeList, array $pageParams)
	{
		$options = $this->options();
		$visitor = \XF::visitor();
		$templater = $this->templater();

		if (\XF::$debugMode && \XF::$versionId != $options->currentVersionId)
		{
			$noticeList->addNotice('upgrade_pending', 'block',
				$templater->renderTemplate('public:notice_upgrade_pending', $pageParams),
				['display_style' => 'accent']
			);
		}

		if (!$options->boardActive && $visitor->is_admin)
		{
			$noticeList->addNotice('board_closed', 'block',
				$templater->renderTemplate('public:notice_board_closed', $pageParams),
				['display_style' => 'accent']
			);
		}

		if ($visitor->user_id && in_array($visitor->user_state, ['email_confirm', 'email_confirm_edit']))
		{
			$noticeList->addNotice('confirm_email', 'block',
				$templater->renderTemplate('public:notice_confirm_email', $pageParams)
			);
		}

		if ($visitor->user_id && $visitor->user_state == 'email_bounce')
		{
			$noticeList->addNotice('email_bounce', 'block',
				$templater->renderTemplate('public:notice_email_bounce', $pageParams)
			);
		}

		if (
			$this->options()->showFirstCookieNotice
			&& $this->request()->getCookie('session') === false
			&& $this->request()->getCookie('user') === false
		)
		{
			$noticeList->addNotice('cookies', 'block',
				$templater->renderTemplate('public:notice_cookies', $pageParams)
			);
		}
	}
}