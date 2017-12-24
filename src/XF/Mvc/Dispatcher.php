<?php

namespace XF\Mvc;

use XF\Http;
use XF\Mvc\Renderer\AbstractRenderer;
use XF\Mvc\Reply\AbstractReply;
use XF\PrintableException;

class Dispatcher
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	/**
	 * @var \XF\Http\Request
	 */
	protected $request;

	/**
	 * @var \XF\Mvc\Router
	 */
	protected $router;

	public function __construct(\XF\App $app, Http\Request $request = null)
	{
		$this->app = $app;
		$this->request = $request ? $request : $app->request();
	}

	public function run($routePath = null)
	{
		if ($routePath === null)
		{
			$routePath = $this->request->getRoutePath();
		}

		$match = $this->route($routePath);
		$reply = $this->dispatchLoop($match);

		$responseType = $reply->getResponseType() ? $reply->getResponseType() : $match->getResponseType();
		$response = $this->render($reply, $responseType);

		return $response;
	}

	public function route($routePath)
	{
		$match = $this->getRouter()->routeToController($routePath, $this->request);
		if (!($match instanceof RouteMatch) || !$match->getController())
		{
			$match = $this->app->getErrorRoute('DispatchError', [
				'code' => 'invalid_route',
				'match' => $match
			]);
		}

		return $match;
	}

	public function dispatchLoop(RouteMatch $match)
	{
		$this->app->fire('dispatcher_pre_dispatch', [$this, $match]);

		$this->app->preDispatch($match);

		$i = 1;
		$attemptErrorReroute = true;
		$originalMatch = $match;
		$reply = null;

		$this->app->fire('dispatcher_match', [$this, &$match]);

		do
		{
			$controllerClass = $match->getController();
			$action = $match->getAction();
			$responseType = $match->getResponseType();
			$sectionContext = $match->getSectionContext();
			$params = $match->getParameterBag();
			$controller = null;

			try
			{
				$reply = $this->dispatchClass(
					$controllerClass, $action, $responseType, $params, $sectionContext, $controller, $reply
				);
			}
			catch (\Exception $e)
			{
				if ($attemptErrorReroute)
				{
					\XF::logException($e, true); // rollback as don't know the state
					$attemptErrorReroute = false;

					$reply = new Reply\Reroute(
						$this->app->getErrorRoute('Exception', ['exception' => $e], $responseType)
					);
				}
				else
				{
					$reply = new Reply\Error(
						'An error occurred while the page was being generated. Please try again later.'
					);
				}

				$reply->setResponseType($responseType);
				$reply->setSectionContext($sectionContext);

				if ($controller instanceof \XF\Mvc\Controller)
				{
					$controller->applyReplyChanges($action, $params ?: new ParameterBag(), $reply);
				}
			}

			if (!$reply instanceof Reply\AbstractReply)
			{
				$reply = new Reply\Reroute(
					$this->app->getErrorRoute('DispatchError', [
						'code' => 'no_reply',
						'controller' => $controllerClass,
						'action' => $action
					], $responseType)
				);
				$reply->setSectionContext($sectionContext);
			}

			if (!($reply instanceof Reply\Reroute) && $attemptErrorReroute)
			{
				// if we might be debugging, move this up so that we can display an error instead of the page results.
				// not doing this can hide errors

				try
				{
					\XF::triggerRunOnce(true);
				}
				catch (\Exception $e)
				{
					$attemptErrorReroute = false;

					$reply = new Reply\Reroute(
						$this->app->getErrorRoute('Exception', ['exception' => $e], $responseType)
					);
					$reply->setResponseType($responseType);
					$reply->setSectionContext($sectionContext);
				}
			}

			if ($reply instanceof Reply\Reroute)
			{
				$match = $reply->getMatch();
				if (!$match->getResponseType())
				{
					$match->setResponseType($responseType);
				}
				if (!$match->getSectionContext())
				{
					$match->setSectionContext($sectionContext);
				}
			}
			else
			{
				break;
			}
		}
		while ($i++ < 10);

		if ($reply instanceof Reply\Reroute)
		{
			// rerouted too many times
			$reply = new Reply\Error(
				'An error occurred while the page was being generated. Please try again later.'
			);
			$reply->setResponseType($responseType);
			$reply->setSectionContext($sectionContext);
		}

		$reply->setControllerClass($controllerClass);
		$reply->setAction($action);

		$this->app->postDispatch($reply, $match, $originalMatch);

		$this->app->fire('dispatcher_post_dispatch', [$this, &$reply, $match, $originalMatch]);

		return $reply;
	}

	public function dispatchClass(
		$controllerClass, $action, $responseType, ParameterBag $params = null, $sectionContext = null,
		&$controller = null, AbstractReply $previousReply = null
	)
	{
		if (!$params)
		{
			$params = new ParameterBag();
		}

		if (!$controllerClass)
		{
			return new Reply\Reroute(
				$this->app->getErrorRoute('DispatchError', [
					'code' => 'no_controller',
					'controller' => $controllerClass,
					'action' => $action
				], $responseType)
			);
		}

		$controller = $this->app->controller($controllerClass, $this->request);
		if (!$controller)
		{
			return new Reply\Reroute(
				$this->app->getErrorRoute('DispatchError', [
					'code' => 'invalid_controller',
					'controller' => $controllerClass,
					'action' => $action
				], $responseType)
			);
		}

		$controller->setResponseType($responseType);
		$controller->setDefaultSectionContext($sectionContext);

		if ($previousReply)
		{
			$controller->setupFromReply($previousReply);
		}

		$action = preg_replace('#[^a-z0-9]#i', ' ', $action);
		$action = str_replace(' ', '', ucwords($action));

		$method = 'action' . $action;
		if (!is_callable([$controller, $method]))
		{
			return new Reply\Reroute(
				$this->app->getErrorRoute('DispatchError', [
					'code' => 'invalid_action',
					'controller' => $controllerClass,
					'action' => $action
				], $responseType)
			);
		}

		try
		{
			$controller->preDispatch($action, $params);
			$reply = $controller->$method($params);
		}
		catch (PrintableException $e)
		{
			$reply = new Reply\Error($e->getMessages());
		}
		catch (Reply\Exception $e)
		{
			$reply = $e->getReply();
		}

		if (!$reply)
		{
			$reply = new Reply\Reroute(
				$this->app->getErrorRoute('DispatchError', [
					'code' => 'no_reply',
					'controller' => $controllerClass,
					'action' => $action
				], $responseType)
			);
		}

		$controller->postDispatch($action, $params, $reply);

		return $reply;
	}

	public function render(AbstractReply $reply, $responseType)
	{
		$this->app->fire('dispatcher_pre_render', [$this, $reply, $responseType]);

		$this->app->preRender($reply, $responseType);

		$renderer = $this->app->renderer($responseType);
		$renderer->getResponse()->header('Last-Modified', gmdate('D, d M Y H:i:s', \XF::$time) . ' GMT', true);
		$renderer->setResponseCode($reply->getResponseCode());

		$renderer->getTemplater()->setPageParams($reply->getPageParams());

		if ($reply instanceof Reply\Error)
		{
			$content = $renderer->renderErrors($reply->getErrors());
		}
		else if ($reply instanceof Reply\Message)
		{
			$content = $renderer->renderMessage($reply->getMessage());
		}
		else if ($reply instanceof Reply\Redirect)
		{
			$url = $this->request->convertToAbsoluteUri($reply->getUrl());
			$content = $renderer->renderRedirect($url, $reply->getType(), $reply->getMessage());
		}
		else if ($reply instanceof Reply\View)
		{
			$content = $this->renderView($renderer, $reply);
		}
		else
		{
			throw new \InvalidArgumentException("Unknown reply type: " . get_class($reply));
		}

		$content = $this->app->renderPage($content, $reply, $renderer);
		$content = $renderer->postFilter($content, $reply);

		$response = $renderer->getResponse();

		$this->app->fire('dispatcher_post_render', [$this, &$content, $reply, $renderer, $response]);

		$response->body($content);

		return $response;
	}

	public function renderView(AbstractRenderer $renderer, Reply\View $reply)
	{
		$params = $reply->getParams();

		$template = $reply->getTemplateName();
		if ($template && !strpos($template, ':'))
		{
			$template = $this->app['app.defaultType'] . ':' . $template;
		}

		return $renderer->renderView($reply->getViewClass(), $template, $params);
	}

	/**
	 * @return Http\Request
	 */
	public function getRequest()
	{
		return $this->request;
	}

	/**
	 * @return Router
	 */
	public function getRouter()
	{
		if (!$this->router)
		{
			$this->router = $this->app->router();
		}

		return $this->router;
	}

	/**
	 * @param Router $router
	 */
	public function setRouter(Router $router)
	{
		$this->router = $router;
	}
}