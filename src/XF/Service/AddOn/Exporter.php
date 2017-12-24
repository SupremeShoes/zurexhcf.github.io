<?php

namespace XF\Service\AddOn;

use XF\AddOn\AddOn;
use XF\Util\File;

class Exporter extends \XF\Service\AbstractService
{
	/**
	 * @var AddOn
	 */
	protected $addOn;

	protected $filesToCopy = [];

	/**
	 * @var \DOMDocument
	 */
	protected $xml;

	protected $containers = [];
	protected $emptyContainers = [];

	public function __construct(\XF\App $app, AddOn $addOn, $additionalFiles = [])
	{
		parent::__construct($app);

		$this->addOn = $addOn;

		$this->buildXml();
	}

	protected function buildXml()
	{
		$dataManager = $this->app->addOnDataManager();
		$this->xml = $dataManager->exportAddOn($this->addOn, $this->containers, $this->emptyContainers);

		if (!$this->containers)
		{
			$this->deleteDataDirectory();
		}
		else if ($this->emptyContainers)
		{
			$this->deleteEmpty();
		}
	}

	public function getXml()
	{
		return $this->xml;
	}

	public function getContainers()
	{
		return $this->containers;
	}

	public function export($containerName = null)
	{
		$dataDir = $this->addOn->getDataDirectory();

		File::createDirectory($dataDir, false);
		if (!is_writable($dataDir))
		{
			throw new \InvalidArgumentException(\XF::phrase('add_on_directory_x_is_not_writable', ['dir' => $dataDir]));
		}

		$xml = $this->xml;

		if ($containerName === null)
		{
			foreach ($this->containers AS $containerName)
			{
				$this->writeFile($containerName, $xml->getElementsByTagName($containerName)->item(0));
			}
		}
		else
		{
			$this->writeFile($containerName, $xml->getElementsByTagName($containerName)->item(0));
		}
	}

	protected function writeFile($containerName, \DOMNode $container)
	{
		$dataDir = $this->addOn->getDataDirectory();

		$newDoc = new \DOMDocument('1.0', 'utf-8');
		$newDoc->formatOutput = true;
		$newDoc->appendChild($newDoc->importNode($container, true));
		$xml = $newDoc->saveXML();

		file_put_contents($dataDir . DIRECTORY_SEPARATOR . "$containerName.xml", $xml);
	}

	public function deleteEmpty()
	{
		if (!$this->emptyContainers)
		{
			return;
		}

		$addOn = $this->addOn;

		foreach ($this->emptyContainers AS $containerName)
		{
			$file = $addOn->getDataDirectory() . DIRECTORY_SEPARATOR . "$containerName.xml";

			if (file_exists($file) && is_writable($file))
			{
				@unlink($file);
			}
		}
	}

	public function deleteDataDirectory()
	{
		$dataDir = $this->addOn->getDataDirectory();
		if (file_exists($dataDir))
		{
			File::deleteDirectory($dataDir);
		}
	}
}