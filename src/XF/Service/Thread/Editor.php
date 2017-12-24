<?php

namespace XF\Service\Thread;

use XF\Entity\Thread;

class Editor extends \XF\Service\AbstractService
{
	use \XF\Service\ValidateAndSavableTrait;

	/**
	 * @var Thread
	 */
	protected $thread;

	protected $performValidations = true;

	public function __construct(\XF\App $app, Thread $thread)
	{
		parent::__construct($app);
		$this->thread = $thread;
	}

	public function getThread()
	{
		return $this->thread;
	}

	public function setPerformValidations($perform)
	{
		$this->performValidations = (bool)$perform;
	}

	public function getPerformValidations()
	{
		return $this->performValidations;
	}

	public function setTitle($title)
	{
		$this->thread->title = $title;
	}

	public function setPrefix($prefixId)
	{
		$this->thread->prefix_id = $prefixId;
	}

	public function setDiscussionOpen($discussionOpen)
	{
		$this->thread->discussion_open = $discussionOpen;
	}

	public function setDiscussionState($discussionState)
	{
		$this->thread->discussion_state = $discussionState;
	}

	public function setSticky($sticky)
	{
		$this->thread->sticky = $sticky;
	}

	public function setCustomFields(array $customFields)
	{
		$thread = $this->thread;

		$editMode = $thread->getFieldEditMode();

		/** @var \XF\CustomField\Set $fieldSet */
		$fieldSet = $thread->custom_fields;
		$fieldDefinition = $fieldSet->getDefinitionSet()
			->filterEditable($fieldSet, $editMode)
			->filterOnly($thread->Forum->field_cache);

		$customFieldsShown = array_keys($fieldDefinition->getFieldDefinitions());

		if ($customFieldsShown)
		{
			$fieldSet->bulkSet($customFields, $customFieldsShown, $editMode);
		}
	}
	
	protected function finalSetup()
	{
	}

	protected function _validate()
	{
		$this->finalSetup();

		$thread = $this->thread;

		$thread->preSave();
		$errors = $thread->getErrors();

		if ($this->performValidations)
		{
			if (!$thread->prefix_id
				&& $thread->Forum->require_prefix
				&& $thread->Forum->getUsablePrefixes()
				&& !$thread->canMove()
			)
			{
				$errors[] = \XF::phraseDeferred('please_select_a_prefix');
			}

			// the canMove check allows moderators to bypass this requirement when editing; they're likely editing
			// another user's thread so don't force them to add a prefix
		}

		return $errors;
	}

	protected function _save()
	{
		$thread = $this->thread;

		$thread->save(true, false);

		return $thread;
	}
}