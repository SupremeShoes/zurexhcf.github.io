<?php

namespace XF\Spam\Checker;

class SpamPhrases extends AbstractProvider implements ContentCheckerInterface
{
	protected function getType()
	{
		return 'SpamPhrases';
	}

	public function check(\XF\Entity\User $user, $message, array $extraParams = [])
	{
		$option = $this->app()->options()->spamPhrases;
		$phrases = preg_split('/\r?\n/', trim($option['phrases']), -1, PREG_SPLIT_NO_EMPTY);

		$decision = 'allowed';

		foreach ($phrases AS $phrase)
		{
			$phrase = trim($phrase);
			if (!strlen($phrase))
			{
				continue;
			}

			$origPhrase = $phrase;

			if ($phrase[0] != '/')
			{
				$phrase = preg_quote($phrase, '#');
				$phrase = str_replace('\\*', '[\w"\'/ \t]*', $phrase);
				$phrase = '#(?<=\W|^)(' . $phrase . ')(?=\W|$)#iu';
			}
			else
			{
				if (preg_match('/\W[\s\w]*e[\s\w]*$/', $phrase))
				{
					// can't run a /e regex
					continue;
				}
			}

			try
			{
				if (preg_match($phrase, $message))
				{
					$decision = $option['action'] == 'moderate' ? 'moderated' : 'denied';

					$this->logDetail('spam_phrase_matched_x', [
						'phrase' => $origPhrase
					]);

					break;
				}
			}
			catch (\ErrorException $e) {}
		}

		$this->logDecision($decision);
	}

	public function submitSpam($contentType, $contentIds)
	{
		return;
	}

	public function submitHam($contentType, $contentIds)
	{
		return;
	}
}