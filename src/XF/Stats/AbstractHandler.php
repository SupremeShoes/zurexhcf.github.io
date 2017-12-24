<?php

namespace XF\Stats;

use XF\App;

abstract class AbstractHandler
{
	protected $contentType;

	/**
	 * @var App
	 */
	protected $app;

	public function __construct($contentType, App $app)
	{
		$this->contentType = $contentType;
		$this->app = $app;
	}

	/**
	 * @return \XF\Db\AbstractAdapter
	 */
	public function db()
	{
		return $this->app->db();
	}

	abstract public function getStatsTypes();
	abstract public function getData($start, $end);

	/**
	 * Manipulates a statistic type before display. Must still return a number (no formatting).
	 *
	 * @param string $statsType
	 * @param number $counter
	 *
	 * @return number
	 */
	public function adjustStatValue($statsType, $counter)
	{
		return $counter;
	}

	/**
	 * Returns SQL for a basic stats prepared statement.
	 *
	 * @param string	Name of table from which to select data
	 * @param string	Name of date field
	 * @param string	Extra SQL conditions
	 * @param string	SQL calculation function (COUNT(*), SUM(field_name)...)
	 *
	 * @return string
	 */
	protected function getBasicDataQuery($tableName, $dateField, $extraWhere = '', $calcFunction = 'COUNT(*)')
	{
		return '
			SELECT
				' . $dateField . ' - ' . $dateField . ' % 86400 AS unixDate,
				' . $calcFunction . '
			FROM ' . $tableName . '
			WHERE ' . $dateField . ' BETWEEN ? AND ?
			' . ($extraWhere ? 'AND ' . $extraWhere : '') . '
			GROUP BY unixDate
		';
	}
}