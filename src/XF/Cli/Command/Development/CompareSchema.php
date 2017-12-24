<?php

namespace XF\Cli\Command\Development;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CompareSchema extends Command
{
	use RequiresDevModeTrait;

	protected function configure()
	{
		$this
			->setName('xf-dev:compare-schema')
			->setDescription('Compares database schemas for consequential differences')
			->addArgument(
				'db',
				InputArgument::REQUIRED,
				'Database to compare against'
			)
			->addArgument(
				'db2',
				InputArgument::OPTIONAL,
				'Second database to compare against (defaults to database in config.php if not specified)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$config = \XF::config();

		if ($input->getArgument('db2'))
		{
			$db1 = $input->getArgument('db');
			$db2 = $input->getArgument('db2');
		}
		else
		{
			$db1 = $config['db']['dbname'];
			$db2 = $input->getArgument('db');
		}

		if ($db1 == $db2)
		{
			$output->writeln("Attempting to compare $db1 with itself.");
			return 1;
		}

		$errors = $this->getComparison($db1, $db2);
		if ($errors)
		{
			$output->writeln("<error>The following differences were found:</error>");
			$this->printComparisonErrors($output, $errors);
			$output->writeln("References are how to change $db1 to match $db2.");
		}
		else
		{
			$output->writeln("<info>There are no differences between $db1 and $db2.</info>");
		}

		return 0;
	}

	protected function getComparison($db1, $db2)
	{
		$db = \XF::db();

		$tables = $db->fetchAll('
			SELECT *
			FROM information_schema.tables
			WHERE TABLE_SCHEMA IN (' . $db->quote($db1) . ', ' . $db->quote($db2) . ')
		');
		$db1Tables = [];
		$db2Tables = [];
		foreach ($tables AS $table)
		{
			if ($table['TABLE_SCHEMA'] == $db1)
			{
				$db1Tables[$table['TABLE_NAME']] = $table;
			}
			else
			{
				$db2Tables[$table['TABLE_NAME']] = $table;
			}
		}

		$columns = $db->fetchAll('
			SELECT *
			FROM information_schema.columns
			WHERE TABLE_SCHEMA IN (' . $db->quote($db1) . ', ' . $db->quote($db2) . ')
		');
		$db1Columns = [];
		$db2Columns = [];
		foreach ($columns AS $column)
		{
			if ($column['TABLE_SCHEMA'] == $db1)
			{
				$db1Columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column;
			}
			else
			{
				$db2Columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column;
			}
		}

		$columnCompares = [
			'COLUMN_DEFAULT', 'IS_NULLABLE', 'COLLATION_NAME', 'COLUMN_TYPE', 'COLUMN_KEY'
		];

		$errors = [];

		foreach ($db1Tables AS $tableName => $table)
		{
			if (!isset($db2Tables[$tableName]))
			{
				$errors[$tableName] = "REMOVE $tableName";
				continue;
			}

			foreach ($db1Columns[$tableName] AS $columnName => $column)
			{
				if (!isset($db2Columns[$tableName][$columnName]))
				{
					$errors[$tableName][$columnName] = "REMOVE $tableName.$columnName";
					continue;
				}

				$column2 = $db2Columns[$tableName][$columnName];

				foreach ($columnCompares AS $compare)
				{
					if ($column[$compare] !== $column2[$compare])
					{
						$column1Print = ($column[$compare] === NULL ? 'NULL' : $column[$compare]);
						$column2Print = ($column2[$compare] === NULL ? 'NULL' : $column2[$compare]);

						$errors[$tableName][$columnName][$compare] =
							"CHANGE $tableName.$columnName $compare: $column1Print --> $column2Print";
					}
				}
			}

			foreach ($db2Columns[$tableName] AS $columnName => $column)
			{
				if (!isset($db1Columns[$tableName][$columnName]))
				{
					$errors[$tableName][$columnName] = "ADD $tableName.$columnName";
					continue;
				}
			}
		}

		foreach ($db2Tables AS $tableName => $table)
		{
			if (!isset($db1Tables[$tableName]))
			{
				$errors[$tableName] = "ADD $tableName";
				continue;
			}
		}

		// TODO: this doesn't compare indexes

		return $errors;
	}

	protected function printComparisonErrors(OutputInterface $output, array $errors)
	{
		ksort($errors);
		foreach ($errors AS $error)
		{
			if (is_array($error))
			{
				$this->printComparisonErrors($output, $error);
			}
			else
			{
				$output->writeln(" * $error");
			}
		}
	}
}