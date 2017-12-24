<?php

namespace XF\Cli\Command\Rebuild;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\JobRunnerTrait;

abstract class AbstractRebuildCommand extends Command
{
	use JobRunnerTrait;

	/**
	 * Name of the rebuild command suffix (do not include the command namespace)
	 *
	 * @return string
	 */
	abstract protected function getRebuildName();

	abstract protected function getRebuildDescription();

	abstract protected function getRebuildClass();

	protected function getRebuildAliases()
	{
		return [];
	}

	protected function configureOptions()
	{
		return;
	}

	protected function configure()
	{
		$this
			->setName('xf-rebuild:' . $this->getRebuildName())
			->setDescription($this->getRebuildDescription())
			->addOption(
				'batch',
				'b',
				InputOption::VALUE_REQUIRED,
				'Batch size for this job. Default: 500.',
				500
			);

		if ($this->getRebuildAliases())
		{
			$this->setAliases($this->getRebuildAliases());
		}

		$this->configureOptions();
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$globalOptions = array_keys($this->getApplication()->getDefinition()->getOptions());
		$params = $input->getOptions();

		foreach ($globalOptions AS $globalOption)
		{
			unset($params[$globalOption]);
		}

		$this->setupAndRunJob(
			'xfRebuildJob-' . $this->getRebuildName(),
			$this->getRebuildClass(),
			$params, $output
		);

		return 0;
	}
}