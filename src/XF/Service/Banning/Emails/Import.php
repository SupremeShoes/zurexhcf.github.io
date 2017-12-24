<?php

namespace XF\Service\Banning\Emails;

use XF\Service\AbstractXmlImport;

class Import extends AbstractXmlImport
{
	public function import(\SimpleXMLElement $xml)
	{
		$bannedEmailsCache = $this->app->container('bannedEmails');

		$entries = $xml->entry;
		foreach ($entries AS $entry)
		{
			if (in_array((string)$entry['banned_email'], $bannedEmailsCache))
			{
				// already exists
				continue;
			}

			$this->repository('XF:Banning')->banEmail(
				(string)$entry['banned_email'],
				\XF\Util\Xml::processSimpleXmlCdata($entry->reason)
			);
		}
	}
}