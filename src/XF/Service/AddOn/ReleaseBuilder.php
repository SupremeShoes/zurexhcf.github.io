<?php

namespace XF\Service\AddOn;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter AS Zip;

use XF\AddOn\AddOn;
use XF\Util\File;

class ReleaseBuilder extends \XF\Service\AbstractService
{
	/**
	 * @var AddOn
	 */
	protected $addOn;

	protected $addOnRoot;
	protected $buildRoot;
	protected $uploadRoot;
	protected $addOnBase;
	protected $tempFile;

	/**
	 * @var Filesystem
	 */
	protected $localFs;

	/**
	 * @var \ZipArchive
	 */
	protected $zipArchive;

	protected $generateHashes = true;
	protected $hashesGenerated;

	protected $skipBuildTasks = false;
	protected $buildTasksComplete;

	public function __construct(\XF\App $app, AddOn $addOn)
	{
		parent::__construct($app);

		$this->addOn = $addOn;

		$this->prepareDirectories();
		$this->prepareFilesToCopy();
		$this->prepareFsAdapters();
	}

	public function setGenerateHashes($generate)
	{
		$this->generateHashes = $generate;
	}

	public function setSkipBuildTasks($skip)
	{
		$this->skipBuildTasks = $skip;
	}

	protected function prepareDirectories()
	{
		$ds = DIRECTORY_SEPARATOR;

		$addOn = $this->addOn;
		$addOnDir = $addOn->getAddOnDirectory();
		$buildDir = $addOn->getBuildDirectory();

		$uploadDir = $buildDir . $ds . 'upload';
		$addOnBase = $uploadDir . $ds . 'src' . $ds . 'addons' . $ds . $addOn->prepareAddOnIdForPath();

		if (file_exists($buildDir))
		{
			File::deleteDirectory($buildDir);
		}

		File::createDirectory($buildDir, false);
		File::createDirectory($uploadDir, false);
		File::createDirectory($addOnBase, false);

		$this->addOnRoot = $addOnDir;
		$this->buildRoot = $buildDir;
		$this->uploadRoot = $uploadDir;
		$this->addOnBase = $addOnBase;
	}

	protected function prepareFilesToCopy()
	{
		$addOn = $this->addOn;
		$addOnRoot = $this->addOnRoot;
		$uploadRoot = $this->uploadRoot;
		$addOnBase = $this->addOnBase;

		$ds = DIRECTORY_SEPARATOR;

		$exclude = [];

		$filesIterator = $this->getFileIterator($addOnRoot);
		foreach ($filesIterator AS $file)
		{
			$copyRoot = $addOnBase;

			$path = $this->standardizePath($addOnRoot, $file->getPathname());
			if ($this->isPartOfExcludedDirectory($path))
			{
				if (strpos($path, '_no_upload') !== 0)
				{
					continue;
				}

				$path = $this->standardizePath('_no_upload', $path);
				if ($file->isDir() && $path == '_no_upload')
				{
					continue;
				}

				$copyRoot = $uploadRoot . $ds . '..'; // These need copying, but to a different path (outside upload)
			}

			if (strpos($file->getFilename(), '.') === 0)
			{
				$exclude[$file->getPathname()] = true;
				continue;
			}

			if (array_key_exists($file->getPath(), $exclude))
			{
				$exclude[$file->getPathname()] = true;
				continue;
			}

			if (!$file->isDir())
			{
				if ($path === 'build.json')
				{
					continue;
				}

				File::copyFile($file->getPathname(), $copyRoot . $ds . $path, false);
			}
		}

		$rootPath = \XF::getRootDirectory();
		$filesRoot = $addOn->getFilesDirectory();

		$additionalFiles = $addOn->additional_files;
		foreach ((array)$additionalFiles AS $additionalFile)
		{
			$filePath = $filesRoot . $ds . $additionalFile;
			if (file_exists($filePath))
			{
				$root = $filesRoot;
			}
			else
			{
				$filePath = $rootPath . $ds . $additionalFile;
				if (!file_exists($filePath))
				{
					continue;
				}
				$root = $rootPath;
			}

			if (is_dir($filePath))
			{
				$filesIterator = $this->getFileIterator($filePath);
				foreach ($filesIterator AS $file)
				{
					if (strpos($file->getFilename(), '.') === 0)
					{
						$exclude[$file->getPathname()] = true;
						continue;
					}

					if (array_key_exists($file->getPath(), $exclude))
					{
						$exclude[$file->getPathname()] = true;
						continue;
					}

					$stdPath = $this->standardizePath($root, $file->getPathname());
					if (!$file->isDir())
					{
						File::copyFile($file->getPathname(), $uploadRoot . $ds . $stdPath, false);
					}
				}
			}
			else
			{
				$stdPath = $this->standardizePath($root, $filePath);
				File::copyFile($filePath, $uploadRoot . $ds . $stdPath, false);
			}
		}
	}

	protected function prepareFsAdapters()
	{
		$local = new Local($this->buildRoot);
		$this->localFs = new Filesystem($local);

		$this->tempFile = File::getTempFile();

		$zipArchive = new \ZipArchive();
		$zipArchive->open($this->tempFile, \ZipArchive::CREATE);
		$this->zipArchive = $zipArchive;
	}

	public function generateHashes()
	{
		if ($this->hashesGenerated || !$this->generateHashes)
		{
			return null;
		}

		$ds = DIRECTORY_SEPARATOR;

		/** @var HashGenerator $hashGenerator */
		$hashGenerator = $this->service(
			'XF:AddOn\HashGenerator', $this->addOn, $this->uploadRoot, $this->addOnBase . $ds . 'hashes.json'
		);

		$output = $hashGenerator->generate();

		$this->hashesGenerated = true;

		return $output;
	}

	public function performBuildTasks()
	{
		$addOn = $this->addOn;
		$buildJsonPath = $addOn->getBuildJsonPath();

		if ($this->buildTasksComplete || $this->skipBuildTasks || !file_exists($buildJsonPath))
		{
			return;
		}

		if (!$this->testBuildJson($error))
		{
			File::deleteDirectory($this->buildRoot);
			throw new \XF\PrintableException('Cannot build add-on due to build.json error' . ($error ? ': ' . $error : ''). '.');
		}

		$buildJson = $addOn->getBuildJson();

		$this->minifyJs($buildJson['minify']);
		$this->rollupJs($buildJson['rollup']);
		$this->execCmds($buildJson['exec']);

		return;
	}

	protected function testBuildJson(&$error = null)
	{
		$addOn = $this->addOn;

		$baseBuildJson = @json_decode(file_get_contents($addOn->getBuildJsonPath()), true);
		if (!$baseBuildJson)
		{
			$error = \XF\Util\Json::jsonLastErrorMsg();
			return false;
		}

		return true;
	}

	protected function minifyJs($minify)
	{
		if (!$minify)
		{
			return;
		}

		$uploadRoot = $this->uploadRoot;
		$ds = DIRECTORY_SEPARATOR;

		if (!is_array($minify) && $minify === '*')
		{
			$minify = [];

			$iterator = $this->getFileIterator($uploadRoot . $ds . 'js');
			foreach ($iterator AS $file)
			{
				if ($file->isDir())
				{
					continue;
				}

				$fileName = $file->getFilename();

				if (strpos($fileName, '.js') === false || strpos($fileName, '.min.js') !== false)
				{
					continue;
				}
				$minify[] = str_replace($uploadRoot . $ds, '', $file->getPathname());
			}
		}

		foreach ($minify AS $file)
		{
			/** @var JsMinifier $minifier */
			$minifier = $this->service('XF:AddOn\JsMinifier', $uploadRoot . $ds . $file);
			$minifier->minify();
		}
	}

	protected function rollupJs(array $rollup)
	{
		if (!$rollup)
		{
			return;
		}

		foreach ($rollup AS $rollupPath => $files)
		{
			$output = '';
			foreach ($files AS $file)
			{
				$output .= file_get_contents($this->uploadRoot . DIRECTORY_SEPARATOR . $file);
				$output .= "\n\n";
			}
			File::writeFile($this->uploadRoot . DIRECTORY_SEPARATOR . $rollupPath, trim($output), false);
		}
	}

	protected function execCmds(array $exec)
	{
		if (!$exec)
		{
			return;
		}

		$addOn = $this->addOn;

		foreach ($exec AS $cmd)
		{
			$cmd = preg_replace_callback('/({([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)})/', function($match) use ($addOn)
			{
				$placeholder = $match[1];
				$property = $match[2];

				$value = $addOn->{$property};

				if (!$value || !is_scalar($value))
				{
					return $placeholder;
				}

				return escapeshellarg($value);
			}, $cmd);

			chdir($this->addOnRoot);
			passthru($cmd);
		}
	}

	public function build()
	{
		$this->performBuildTasks();
		$this->generateHashes();

		$localFs = $this->localFs;
		$zipArchive = $this->zipArchive;

		foreach ($localFs->listContents('', true) AS $info)
		{
			// skip hidden dot files, e.g. .DS_Store, .gitignore etc.
			if (strpos($info['filename'], '.') === 0 || $info['filename'] === '' || $info['filename'] === false || $info['filename'] === null)
			{
				continue;
			}

			if ($info['type'] == 'dir')
			{
				$info['path'] .= '/';
				$zipArchive->addEmptyDir($info['path']);
				$perm = 040755 << 16; // dir: 0755
			}
			else
			{
				$zipArchive->addFile($this->buildRoot . '/' . $info['path'], $info['path']);
				$perm = 0100644 << 16; // file: 0644
			}

			if (method_exists($zipArchive, 'setExternalAttributesName'))
			{
				$zipArchive->setExternalAttributesName($info['path'], \ZipArchive::OPSYS_UNIX, $perm);
			}
		}

		if (!$zipArchive->close())
		{
			throw new \ErrorException($zipArchive->getStatusString());
		}

		return true;
	}

	public function finalizeRelease()
	{
		$releasePath = $this->addOn->getReleasePath();

		File::createDirectory(dirname($releasePath), false);
		File::renameFile($this->tempFile, $releasePath, false);

		File::deleteDirectory($this->buildRoot);
	}

	/**
	 * @param $path
	 *
	 * @return \SplFileInfo[]|\RecursiveIteratorIterator
	 */
	protected function getFileIterator($path)
	{
		return new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$path, \RecursiveDirectoryIterator::SKIP_DOTS
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);
	}

	protected function standardizePath($rootPath, $path)
	{
		$ds = DIRECTORY_SEPARATOR;
		return preg_replace('#^' . preg_quote(rtrim($rootPath, $ds) . $ds) . '#', '', $path, 1);
	}

	protected function isPartOfExcludedDirectory($path)
	{
		foreach ($this->getExcludedDirectories() AS $dir)
		{
			if (strpos($path, $dir) === 0)
			{
				return true;
			}
		}
		return false;
	}

	protected function getExcludedDirectories()
	{
		return [
			'_build',
			'_files',
			'_no_upload',
			'_output',
			'_releases'
		];
	}
}