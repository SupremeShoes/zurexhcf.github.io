<?php

namespace XF\Pub\View\Error;

use XF\Util\File;
use XF\Util\Xml;

class Server extends \XF\Mvc\View
{
	/**
	 * Checks for the presence of an exception in the view parameters, and if one exists,
	 * prepares the trace as HTML in the form of <li> tags.
	 *
	 * @return array Returns 'message', 'trace', and 'traceHtml' keys
	 */
	protected function getExceptionTraceHtml()
	{
		$traceHtml = '';

		if (isset($this->params['exception']) && $this->params['exception'] instanceof \Exception)
		{
			/** @var \Exception $e */
			$e = $this->params['exception'];
			$error = '<b>' . get_class($e) . '</b>: ' . htmlspecialchars($e->getMessage()) . ' in <b>'
				. File::stripRootPathPrefix($e->getFile()) . '</b> at line <b>' . $e->getLine() . '</b>';

			foreach ($e->getTrace() AS $traceEntry)
			{
				$function = (isset($traceEntry['class']) ? $traceEntry['class'] . $traceEntry['type'] : '') . $traceEntry['function'];
				if (isset($traceEntry['file']) && isset($traceEntry['line']))
				{
					$fileLine = ' <span class="shade">in</span> <b class="file">'
						. File::stripRootPathPrefix($traceEntry['file'])
						. "</b> <span class=\"shade\">at line</span> <b class=\"line\">$traceEntry[line]</b>";
				}
				else
				{
					$fileLine = '';
				}
				$traceHtml .= "\t<li><b class=\"function\">" . htmlspecialchars($function) . "()</b>" . $fileLine . "</li>\n";
			}
		}
		else
		{
			$error = 'Unknown';
		}

		return [
			'exception' => $error,
			'errorHtml' => "<div class=\"blockMessage blockMessage--error\"><div class=\"exception\"><div class=\"exception-message\">$error</div> <ol class=\"exception-trace\">\n$traceHtml</ol></div></div>"
		];
	}

	public function renderHtml()
	{
		$exception = $this->getExceptionTraceHtml();

		return $exception['errorHtml'];
	}

	public function renderJson()
	{
		return $this->getExceptionTraceHtml();
	}

	public function renderXml()
	{
		$document = new \DOMDocument('1.0', 'utf-8');
		$document->formatOutput = true;

		$rootNode = $document->createElement('errors');
		$document->appendChild($rootNode);

		if (isset($this->params['exception']) && $this->params['exception'] instanceof \Exception)
		{
			/** @var \Exception $e */
			$e = $this->params['exception'];
			$exceptionMessage = $e->getMessage();

			$rootNode->appendChild(
				Xml::createDomElement($document, 'error', $exceptionMessage)
			);
			$traceNode = $document->createElement('trace');

			foreach ($e->getTrace() AS $trace)
			{
				$function = (isset($trace['class']) ? $trace['class'] . $trace['type'] : '') . $trace['function'];

				if (!isset($trace['file']))
				{
					$trace['file'] = '';
				}
				if (!isset($trace['line']))
				{
					$trace['line'] = '';
				}

				$entryNode = $document->createElement('entry');
				$entryNode->setAttribute('function', $function);
				$entryNode->setAttribute('file', $trace['file']);
				$entryNode->setAttribute('line', $trace['line']);

				$traceNode->appendChild($entryNode);
			}

			$rootNode->appendChild($traceNode);
		}
		else
		{
			$rootNode->appendChild($document->createElement('error', 'Unknown error, trace unavailable'));
		}

		return $document->saveXML();
	}
}