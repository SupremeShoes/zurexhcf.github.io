<?php

namespace XF\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Import\Runner;

class Import extends Command
{
	use JobRunnerTrait;

	protected function configure()
	{
		$this
			->setName('xf:import')
			->setDescription('Executes an import configured via the control panel');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$app = \XF::app();
		$em = $app->em();


		$manager = $app->import()->manager();
		$runner = $manager->getRunner();

		if (!$runner)
		{
			$output->writeln("<error>No valid import session could be found. Configure this via the control panel.</error>");
			return 1;
		}

		$session = $runner->getSession();
		if ($session->runComplete)
		{
			$output->writeln("<error>The import session has already been completed. Continue via the control panel.</error>");
			return 1;
		}

		if (!$session->canRunVia('cli'))
		{
			$output->writeln("<error>This import has been started through another method.</error>");
			return 1;
		}

		$db = $app->db();
		$db->logQueries(false); // need to limit memory usage

		$importerTitle = $runner->getImporter()->getSourceTitle();
		$output->writeln("Starting import from $importerTitle...");

		$complete = false;

		$progressIndicator = new ProgressIndicator($output);
		$progressIndicator->start('Importing...');
		$progressStarted = true;

		do
		{
			$runResult = $runner->run();

			$session->runType = 'cli';
			$manager->updateCurrentSession($session);

			$lastRun = $runner->getLastRun();

			/** @var \XF\Import\StepState $state */
			$state = $lastRun['state'];

			$stepTitle = $state->title;
			$importCompletion = $runner->getImportCompletionDetails();

			if ($runResult == Runner::STATE_COMPLETE)
			{
				$complete = true;
			}

			if ($stepTitle && (!$complete || $progressStarted))
			{
				if (!$progressStarted)
				{
					$progressIndicator->start('Importing...');
					$progressStarted = true;
				}

				$stepInfo = "Step $importCompletion[current] of $importCompletion[total]: $stepTitle - ";

				if ($state->complete)
				{
					$progressIndicator->finish($stepInfo . '100.00%');
					$progressStarted = false;
				}
				else
				{
					$progressIndicator->setMessage($stepInfo . $state->getCompletionOutput());
				}
			}

			// keep the memory limit down on long running jobs
			\XF::triggerRunOnce();
			$em->clearEntityCache();
			$runner->getImporter()->getDataManager()->getLog()->clearCache();
		}
		while (!$complete);

		if ($progressStarted)
		{
			$progressIndicator->finish('Done!');
		}

		$output->writeln("The data has been imported successfully. To finalize this import, you must continue via the control panel.");

		// TODO: we can trigger finalize jobs so only the complete step has to happen via the ACP
		// TODO: we can probably display any notes about the import here (changed users, etc)

		return 0;
	}
}