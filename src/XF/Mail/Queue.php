<?php

namespace XF\Mail;

class Queue
{
	/**
	 * @var \XF\Db\AbstractAdapter
	 */
	protected $db;

	public function __construct(\XF\Db\AbstractAdapter $db)
	{
		$this->db = $db;
	}

	public function queue(\Swift_Mime_Message $message)
	{
		$this->db->insert('xf_mail_queue', [
			'mail_data' => serialize($message),
			'queue_date' => time()
		]);

		$jobManager = \XF::app()->jobManager();
		if (!$jobManager->getUniqueJob('MailQueue'))
		{
			try
			{
				$jobManager->enqueueUnique('MailQueue', 'XF\Job\MailQueue', [], false);
			}
			catch (\Exception $e)
			{
				// need to just ignore this and let it get picked up later;
				// not doing this could lose email on a deadlock
			}
		}

		return true;
	}

	public function run($maxRunTime)
	{
		$s = microtime(true);
		$db = $this->db;
		$mailer = \XF::mailer();
		
		do
		{
			$queue = $this->getQueue();

			foreach ($queue AS $id => $record)
			{
				if (!$db->delete('xf_mail_queue', 'mail_queue_id = ?', $id))
				{
					// already been deleted - run elsewhere
					continue;
				}

				$message = @unserialize($record['mail_data']);
				if (!($message instanceof \Swift_Mime_Message))
				{
					continue;
				}

				$mailer->send($message);

				if ($maxRunTime && microtime(true) - $s > $maxRunTime)
				{
					break 2;
				}
			}
		}
		while ($queue);
	}
	
	public function getQueue($limit = 20)
	{
		$db = $this->db;

		return $db->fetchAllKeyed($db->limit('
			SELECT *
			FROM xf_mail_queue
			ORDER BY queue_date
		', $limit), 'mail_queue_id');
	}

	public function hasMore()
	{
		return (bool)$this->db->fetchOne('
			SELECT MIN(mail_queue_id)
			FROM xf_mail_queue
		');
	}
}