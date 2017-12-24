<?php

namespace XF\BbCode;

class Parser
{
	protected $ast = [];
	protected $astReference;
	protected $tagStack = [];
	protected $pendingText = '';
	protected $plainTag = null;
	protected $depth = 0;
	protected $maxDepth = 20;

	/**
	 * @var RuleSet
	 */
	protected $ruleSet;

	public function parse($text, RuleSet $ruleSet)
	{
		$this->ruleSet = $ruleSet;

		$this->ast = [];
		$this->astReference =& $this->ast;
		$this->tagStack = [];
		$this->pendingText = '';
		$this->plainTag = null;
		$this->depth = 0;

		$position = 0;
		$length = strlen($text);

		while (preg_match(
			'#(?:\[([a-z0-9_]+)(=|\])|\[/([a-z0-9_]+)])#i',
			$text, $match, PREG_OFFSET_CAPTURE, $position
		))
		{
			if ($match[0][1] > $position)
			{
				// push text
				$plainText = substr($text, $position, $match[0][1] - $position);
				$this->pushText($plainText);
			}

			$fullMatch = $match[0][0];
			$position = $match[0][1] + strlen($fullMatch);

			if (isset($match[3]))
			{
				$this->closeTag($fullMatch, $match[3][0]);
			}
			else if ($match[2][0] == ']')
			{
				// simple, optionless tag
				$this->openTag($fullMatch, $match[1][0]);
			}
			else
			{
				// complex tag
				if ($position >= $length)
				{
					$this->pushText($fullMatch);
				}
				else
				{
					$delim = substr($text, $position, 1);
					if ($delim == '"' || $delim == "'")
					{
						$startPos = $position + 1;
						$endPos = strpos($text, "$delim]", $startPos);
						$startMatch = $delim;
						$endMatch = "$delim]";
					}
					else
					{
						$startPos = $position;
						$endPos = strpos($text, ']', $startPos);
						$startMatch = '';
						$endMatch = ']';
					}
					if ($endPos)
					{
						$option = substr($text, $startPos, $endPos - $startPos);
						$this->openTag(
							$fullMatch . $startMatch . $option . $endMatch,
							$match[1][0], $option
						);

						$position = $endPos + strlen($endMatch);
					}
					else
					{
						$this->pushText($fullMatch);
					}
				}
			}
		}

		if ($position < $length)
		{
			$this->pushText(substr($text, $position));
		}

		$this->finalizeText();

		$ast = $this->ast;
		$this->ast = [];

		$null = null;
		$this->astReference = &$null;

		return $ast;
	}

	protected function pushText($text)
	{
		$this->pendingText .= $text;
	}

	protected function finalizeText()
	{
		if (strlen($this->pendingText))
		{
			$this->astReference[] = $this->pendingText;
			$this->pendingText = '';
		}
	}

	protected function closeTag($originalText, $tag)
	{
		if (!$this->tagStack)
		{
			$this->pushText($originalText);
			return;
		}

		$tagLower = strtolower($tag);

		if ($this->plainTag && $this->plainTag != $tagLower)
		{
			$this->pushText($originalText);
			return;
		}

		$stackEntry = null;
		$stackEntryPos = null;

		foreach ($this->tagStack AS $i => $stack)
		{
			if ($stack['tag'] == $tagLower)
			{
				$stackEntry = $stack;
				$stackEntryPos = $i;
				break;
			}
		}

		if (!$stackEntry)
		{
			$this->pushText($originalText);
			return;
		}

		$this->finalizeText();

		$stackEntry['entry']['original'][1] = $originalText;

		$reopens = [];
		if ($stackEntryPos)
		{
			for ($i = 0; $i < $stackEntryPos; $i++)
			{
				$reopens[] = array_shift($this->tagStack);
			}
		}

		array_shift($this->tagStack); // close the current tag

		$this->astReference =& $stackEntry['parent'];
		$this->depth = max(0, $this->depth - $stackEntryPos - 1);
		$this->plainTag = null;

		if ($reopens)
		{
			foreach (array_reverse($reopens) AS $reopen)
			{
				$reopenEntry = $reopen['entry'];
				$this->openTag($reopenEntry['original'][0], $reopenEntry['tag'], $reopenEntry['option']);
			}
		}
	}

	protected function openTag($originalText, $tag, $option = null)
	{
		if ($this->plainTag)
		{
			$this->pushText($originalText);
			return;
		}

		$tagLower = strtolower($tag);

		if (!$this->ruleSet->validateTag($tagLower, $option, $modifiers))
		{
			$this->pushText($originalText);
			return;
		}

		$this->finalizeText();

		$i = count($this->astReference);
		$this->astReference[$i] = [
			'tag' => $tagLower,
			'option' => $option,
			'original' => [$originalText, "[/$tag]"],
			'children' => []
		];

		array_unshift($this->tagStack, [
			'tag' => $tagLower,
			'parent' => &$this->astReference,
			'entry' => &$this->astReference[$i],
			'modifiers' => $modifiers
		]);

		$this->astReference =& $this->astReference[$i]['children'];

		if (!empty($modifiers['plain']))
		{
			$this->plainTag = $tagLower;
		}

		$this->depth++;
	}
}