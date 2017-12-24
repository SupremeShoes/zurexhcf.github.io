<?php

namespace XF\ApprovalQueue;

use XF\Entity\ApprovalQueue;
use XF\Mvc\Entity\Entity;

abstract class AbstractHandler
{
	protected $contentType;

	protected $filterCache = [];

	public function __construct($contentType)
	{
		$this->contentType = $contentType;
	}

	public function canView(Entity $content, &$error = null)
	{
		if (!$this->canViewContent($content, $error))
		{
			return false;
		}

		if (!$this->canActionContent($content, $error))
		{
			return false;
		}

		return true;
	}

	protected function canViewContent(Entity $content, &$error = null)
	{
		if (method_exists($content, 'canView'))
		{
			return $content->canView($error);
		}

		throw new \LogicException("Could not determine content viewability; please override");
	}

	protected function canActionContent(Entity $content, &$error = null)
	{
		return true;
	}

	public function getTemplateName()
	{
		return 'public:approval_item_' . $this->contentType;
	}

	public function getTemplateData(ApprovalQueue $unapprovedItem)
	{
		return [
			'unapprovedItem' => $unapprovedItem,
			'content' => $unapprovedItem->Content,
			'spamDetails' => $unapprovedItem->SpamDetails,
			'handler' => $this
		];
	}

	public function render(ApprovalQueue $unapprovedItem)
	{
		$template = $this->getTemplateName();
		if (!$template)
		{
			return '';
		}

		return \XF::app()->templater()->renderTemplate($template, $this->getTemplateData($unapprovedItem));
	}

	public function getEntityWith()
	{
		return [];
	}

	public function getContent($id)
	{
		return \XF::app()->findByContentType($this->contentType, $id, $this->getEntityWith());
	}

	public function getSpamDetails($id)
	{
		/** @var \XF\Repository\Spam $spamRepo */
		$spamRepo = \XF::app()->repository('XF:Spam');

		$spamTriggerLogsFinder = $spamRepo->findSpamTriggerLogs()->forContent($this->contentType, $id);

		return $spamTriggerLogsFinder->fetch()->pluckNamed('details', 'content_id');
	}

	public function getDefaultActions()
	{
		return [
			'' => \XF::phrase('do_nothing'),
			'approve' => \XF::phrase('approve'),
			'delete' => \XF::phrase('delete')
		];
	}

	public function performAction($action, Entity $entity)
	{
		$args = func_get_args();
		unset ($args[0]);

		$method = 'action' . ucfirst($action);
		if (!\XF\Util\Php::validateCallback($this, $method, $error))
		{
			throw new \LogicException($error);
		}

		return call_user_func_array([$this, $method], $args);
	}

	protected function getInput($key, $id)
	{
		if (!isset($this->filterCache[$key]))
		{
			$this->filterCache[$key] = \XF::app()->request()->filter($key, 'array');
		}

		return !empty($this->filterCache[$key][$this->contentType][$id]) ? $this->filterCache[$key][$this->contentType][$id] : '';
	}

	protected function quickUpdate(Entity $entity, $field, $value = null)
	{
		$values = $field;

		if (!is_array($field))
		{
			$values = [$field => $value];
		}

		$entity->bulkSet($values);
		$entity->save();
	}
}