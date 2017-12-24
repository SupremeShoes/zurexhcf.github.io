<?php

namespace XF\BbCode\ProcessorAction;

class MentionUsers implements FiltererInterface
{
	/**
	 * @var \XF\Str\Formatter $formatter
	 */
	protected $formatter;

	protected $mentionedUsers = [];

	public function __construct(\XF\Str\Formatter $formatter)
	{
		$this->formatter = $formatter;
	}

	public function addFiltererHooks(FiltererHooks $hooks)
	{
		$hooks->addFinalHook('filterFinal');
	}

	public function filterFinal($string)
	{
		$mentions = $this->formatter->getMentionFormatter();

		$string = $mentions->getMentionsBbCode($string);
		$this->mentionedUsers = $mentions->getMentionedUsers();

		return $string;
	}

	public function getMentionedUsers()
	{
		return $this->mentionedUsers;
	}

	public static function factory(\XF\App $app)
	{
		return new static($app->stringFormatter());
	}
}