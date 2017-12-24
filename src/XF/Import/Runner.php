<?php

namespace XF\Import;

use XF\Import\Importer\AbstractImporter;

class Runner
{
	const STATE_COMPLETE = 'complete';
	const STATE_INCOMPLETE = 'incomplete';

	/**
	 * @var AbstractImporter
	 */
	protected $importer;

	/**
	 * @var Session
	 */
	protected $session;

	/**
	 * @var string|null
	 */
	protected $lastRunStep;

	/**
	 * @var StepState|null
	 */
	protected $lastRunState;

	public function __construct(AbstractImporter $importer, Session $session)
	{
		$this->importer = $importer;
		$this->session = $session;
	}

	public function getImporter()
	{
		return $this->importer;
	}

	public function getSession()
	{
		return $this->session;
	}

	public function run($maxTime = 8)
	{
		$session = $this->session;

		if (!$session->startTime)
		{
			$session->startTime = time();
		}

		$this->setupRunnableStep();

		if (!$session->currentStep || $session->runComplete)
		{
			if (!$session->runCompleteTime)
			{
				$session->runCompleteTime = time();
			}

			$session->runComplete = true;

			return self::STATE_COMPLETE;
		}

		set_time_limit(0);
		ignore_user_abort(true);
		//\XF::db()->logQueries(false); // to limit memory

		$newState = $this->runStep($session->currentStep, $session->currentState, $maxTime);

		$this->lastRunStep = $session->currentStep;
		$this->lastRunState = $newState;

		if ($newState->complete)
		{
			$session->stepTotals[$session->currentStep] = $newState->imported;
			if ($newState->startDate && $newState->completeDate)
			{
				$session->stepTime[$session->currentStep] = ($newState->completeDate - $newState->startDate);
			}
			else
			{
				$session->stepTime[$session->currentStep] = null;
			}

			$session->currentStep = null;
			$session->currentState = null;
		}
		else
		{
			$session->currentState = $newState;
		}

		return self::STATE_INCOMPLETE;
	}

	protected function setupRunnableStep()
	{
		$session = $this->session;

		if ($session->currentStep)
		{
			// in the middle of a step
			return;
		}

		while ($session->remainingSteps)
		{
			$step = array_shift($session->remainingSteps);

			$runMethod = 'step' . $step;
			if (is_callable([$this->importer, $runMethod]))
			{
				$state = new StepState();
				$state->startDate = time();
				$state->title = $this->getStepTitle($step);

				$stepEndMethod = 'getStepEnd' . $step;
				if (is_callable([$this->importer, $stepEndMethod]))
				{
					$state->end = $this->importer->$stepEndMethod();
				}

				$session->currentStep = $step;
				$session->currentState = $state;
				return;
			}
		}

		// if reaching here, there are no remaining steps
	}

	/**
	 * @param string $step
	 * @param StepState $state
	 * @param float $maxTime
	 *
	 * @return StepState
	 */
	protected function runStep($step, StepState $state, $maxTime)
	{
		$runMethod = 'step' . $step;
		if (!is_callable([$this->importer, $runMethod]))
		{
			throw new \LogicException("Step method $runMethod is not callable on " . get_class($this->importer));
		}

		$thisStepConfig = $this->importer->getStepSpecificConfig($step, $this->session->stepConfig);

		if ($maxTime < 1)
		{
			$maxTime = 1;
		}

		$newState = $this->importer->$runMethod($state, $thisStepConfig, $maxTime);
		if (!($newState instanceof StepState))
		{
			throw new \LogicException("Step must return a step state object");
		}

		return $newState;
	}

	protected function getStepTitle($step)
	{
		$steps = $this->importer->getSteps();

		return isset($steps[$step]) ? strval($steps[$step]['title']) : '';
	}

	public function getLastRun()
	{
		return [
			'step' => $this->lastRunStep,
			'state' => $this->lastRunState
		];
	}

	public function getImportCompletionDetails()
	{
		return $this->session->getImportCompletionDetails();
	}
}