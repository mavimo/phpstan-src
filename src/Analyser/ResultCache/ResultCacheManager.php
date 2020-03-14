<?php declare(strict_types = 1);

namespace PHPStan\Analyser\ResultCache;

use PHPStan\Analyser\AnalyserResult;
use PHPStan\Analyser\Error;
use PHPStan\File\FileReader;
use function array_fill_keys;
use function array_key_exists;

class ResultCacheManager
{

	private const CACHE_VERSION = 'v2-hashes';

	/** @var string */
	private $cacheFilePath;

	/** @var string[] */
	private $allCustomConfigFiles;

	/** @var string[] */
	private $analysedPaths;

	/** @var string[] */
	private $composerAutoloaderProjectPaths;

	/** @var string[] */
	private $stubFiles;

	/** @var string */
	private $usedLevel;

	/** @var array<string, string> */
	private $fileHashes = [];

	/**
	 * @param string $cacheFilePath
	 * @param string[] $allCustomConfigFiles
	 * @param string[] $analysedPaths
	 * @param string[] $composerAutoloaderProjectPaths
	 * @param string[] $stubFiles
	 * @param string $usedLevel
	 */
	public function __construct(
		string $cacheFilePath,
		array $allCustomConfigFiles,
		array $analysedPaths,
		array $composerAutoloaderProjectPaths,
		array $stubFiles,
		string $usedLevel
	)
	{
		$this->cacheFilePath = $cacheFilePath;
		$this->allCustomConfigFiles = $allCustomConfigFiles;
		$this->analysedPaths = $analysedPaths;
		$this->composerAutoloaderProjectPaths = $composerAutoloaderProjectPaths;
		$this->stubFiles = $stubFiles;
		$this->usedLevel = $usedLevel;
	}

	/**
	 * @param string[] $allAnalysedFiles
	 * @param bool $debug
	 * @return ResultCache
	 */
	public function restore(array $allAnalysedFiles, bool $debug): ResultCache
	{
		if ($debug) {
			return new ResultCache($allAnalysedFiles, true, time(), [], []);
		}

		if (!is_file($this->cacheFilePath)) {
			return new ResultCache($allAnalysedFiles, true, time(), [], []);
		}

		try {
			$data = require $this->cacheFilePath;
		} catch (\Throwable $e) {
			return new ResultCache($allAnalysedFiles, true, time(), [], []);
		}

		if (!is_array($data)) {
			@unlink($this->cacheFilePath);
			return new ResultCache($allAnalysedFiles, true, time(), [], []);
		}

		if ($data['meta'] !== $this->getMeta()) {
			return new ResultCache($allAnalysedFiles, true, time(), [], []);
		}

		if (time() - $data['lastFullAnalysisTime'] >= 60 * 60 * 24) {
			// run full analysis if the result cache is older than 24 hours
			return new ResultCache($allAnalysedFiles, true, time(), [], []);
		}

		$invertedDependencies = $data['dependencies'];
		$deletedFiles = array_fill_keys(array_keys($invertedDependencies), true);
		$filesToAnalyse = [];
		$invertedDependenciesToReturn = [];
		$errors = $data['errors'];
		$filteredErrors = [];
		$newFileAppeared = false;
		foreach ($allAnalysedFiles as $analysedFile) {
			if (array_key_exists($analysedFile, $errors)) {
				$filteredErrors[$analysedFile] = $errors[$analysedFile];
			}
			if (!array_key_exists($analysedFile, $invertedDependencies)) {
				// new file
				$filesToAnalyse[] = $analysedFile;
				$newFileAppeared = true;
				continue;
			}

			unset($deletedFiles[$analysedFile]);

			$analysedFileData = $invertedDependencies[$analysedFile];
			$cachedFileHash = $analysedFileData['fileHash'];
			$dependentFiles = $analysedFileData['dependentFiles'];
			$invertedDependenciesToReturn[$analysedFile] = $dependentFiles;
			$currentFileHash = $this->getFileHash($analysedFile);

			if ($cachedFileHash === $currentFileHash) {
				continue;
			}

			$filesToAnalyse[] = $analysedFile;
			foreach ($dependentFiles as $dependentFile) {
				if (!is_file($dependentFile)) {
					continue;
				}
				$filesToAnalyse[] = $dependentFile;
			}
		}

		foreach (array_keys($deletedFiles) as $deletedFile) {
			if (!array_key_exists($deletedFile, $invertedDependencies)) {
				continue;
			}

			$deletedFileData = $invertedDependencies[$deletedFile];
			$dependentFiles = $deletedFileData['dependentFiles'];
			foreach ($dependentFiles as $dependentFile) {
				if (!is_file($dependentFile)) {
					continue;
				}
				$filesToAnalyse[] = $dependentFile;
			}
		}

		if ($newFileAppeared) {
			foreach (array_keys($filteredErrors) as $fileWithError) {
				$filesToAnalyse[] = $fileWithError;
			}
		}

		return new ResultCache(array_unique($filesToAnalyse), false, $data['lastFullAnalysisTime'], $filteredErrors, $invertedDependenciesToReturn);
	}

	public function process(AnalyserResult $analyserResult, ResultCache $resultCache): AnalyserResult
	{
		$notFileSpecificErrors = [];
		$freshErrorsByFile = [];
		$warnings = [];
		foreach ($analyserResult->getErrors() as $error) {
			if (is_string($error)) {
				$notFileSpecificErrors[] = $error;
			} else {
				if ($error->isWarning()) {
					$warnings[] = $error->getMessage();
					continue;
				}
				$freshErrorsByFile[$error->getFilePath()][] = $error;
			}
		}

		$save = function (array $errorsByFile, ?array $dependencies) use ($notFileSpecificErrors, $warnings, $resultCache): void {
			if ($dependencies === null) {
				return;
			}

			if (count($notFileSpecificErrors) > 0) {
				return;
			}

			if (count($warnings) > 0) {
				return;
			}

			$this->save($resultCache->getLastFullAnalysisTime(), $errorsByFile, $dependencies);
		};

		if ($resultCache->isFullAnalysis()) {
			$save($freshErrorsByFile, $analyserResult->getDependencies());

			return $analyserResult;
		}

		$errorsByFile = $this->mergeErrors($resultCache, $freshErrorsByFile);
		$dependencies = $this->mergeDependencies($resultCache, $analyserResult->getDependencies());

		$save($errorsByFile, $dependencies);

		$flatErrors = [];
		foreach ($errorsByFile as $fileErrors) {
			foreach ($fileErrors as $fileError) {
				$flatErrors[] = $fileError;
			}
		}

		return new AnalyserResult(
			array_merge($flatErrors, $warnings, $notFileSpecificErrors),
			$analyserResult->hasInferrablePropertyTypesFromConstructor(),
			$dependencies
		);
	}

	/**
	 * @param ResultCache $resultCache
	 * @param array<string, array<Error>> $freshErrorsByFile
	 * @return array<string, array<Error>>
	 */
	private function mergeErrors(ResultCache $resultCache, array $freshErrorsByFile): array
	{
		$errorsByFile = $resultCache->getErrors();
		foreach ($resultCache->getFilesToAnalyse() as $file) {
			if (!array_key_exists($file, $freshErrorsByFile)) {
				unset($errorsByFile[$file]);
				continue;
			}
			$errorsByFile[$file] = $freshErrorsByFile[$file];
		}

		return $errorsByFile;
	}

	/**
	 * @param ResultCache $resultCache
	 * @param array<string, array<string>>|null $freshDependencies
	 * @return array<string, array<string>>|null
	 */
	private function mergeDependencies(ResultCache $resultCache, ?array $freshDependencies): ?array
	{
		if ($freshDependencies === null) {
			return null;
		}

		$cachedDependencies = [];
		$resultCacheDependencies = $resultCache->getDependencies();
		$filesNoOneIsDependingOn = array_fill_keys(array_keys($resultCacheDependencies), true);
		foreach ($resultCacheDependencies as $file => $filesDependingOnFile) {
			foreach ($filesDependingOnFile as $fileDependingOnFile) {
				$cachedDependencies[$fileDependingOnFile][] = $file;
				unset($filesNoOneIsDependingOn[$fileDependingOnFile]);
			}
		}

		foreach (array_keys($filesNoOneIsDependingOn) as $file) {
			if (array_key_exists($file, $cachedDependencies)) {
				throw new \PHPStan\ShouldNotHappenException();
			}

			$cachedDependencies[$file] = [];
		}

		$newDependencies = $cachedDependencies;
		foreach ($resultCache->getFilesToAnalyse() as $file) {
			if (!array_key_exists($file, $freshDependencies)) {
				unset($newDependencies[$file]);
				continue;
			}

			$newDependencies[$file] = $freshDependencies[$file];
		}

		return $newDependencies;
	}

	/**
	 * @param int $lastFullAnalysisTime
	 * @param array<string, array<Error>> $errors
	 * @param array<string, array<string>> $dependencies
	 */
	private function save(
		int $lastFullAnalysisTime,
		array $errors,
		array $dependencies
	): void
	{
		$invertedDependencies = [];
		$filesNoOneIsDependingOn = array_fill_keys(array_keys($dependencies), true);
		foreach ($dependencies as $file => $fileDependencies) {
			foreach ($fileDependencies as $fileDep) {
				if (!array_key_exists($fileDep, $invertedDependencies)) {
					$invertedDependencies[$fileDep] = [
						'fileHash' => $this->getFileHash($fileDep),
						'dependentFiles' => [],
					];
					unset($filesNoOneIsDependingOn[$fileDep]);
				}
				$invertedDependencies[$fileDep]['dependentFiles'][] = $file;
			}
		}

		foreach (array_keys($filesNoOneIsDependingOn) as $file) {
			if (array_key_exists($file, $invertedDependencies)) {
				throw new \PHPStan\ShouldNotHappenException();
			}

			if (!is_file($file)) {
				continue;
			}

			$invertedDependencies[$file] = [
				'fileHash' => $this->getFileHash($file),
				'dependentFiles' => [],
			];
		}

		ksort($errors);
		ksort($invertedDependencies);

		foreach ($invertedDependencies as $file => $fileData) {
			$dependentFiles = $fileData['dependentFiles'];
			sort($dependentFiles);
			$invertedDependencies[$file]['dependentFiles'] = $dependentFiles;
		}

		$tmpSuccess = @file_put_contents(
			$this->cacheFilePath,
			sprintf(
				"<?php declare(strict_types = 1);\n\nreturn %s;",
				var_export([
					'lastFullAnalysisTime' => $lastFullAnalysisTime,
					'meta' => $this->getMeta(),
					'errors' => $errors,
					'dependencies' => $invertedDependencies,
				], true)
			)
		);
		if ($tmpSuccess === false) {
			throw new \InvalidArgumentException(sprintf('Could not write data to cache file %s.', $this->cacheFilePath));
		}
	}

	/**
	 * @return mixed[]
	 */
	private function getMeta(): array
	{
		$extensions = get_loaded_extensions();
		sort($extensions);

		return [
			'cacheVersion' => self::CACHE_VERSION,
			'phpstanVersion' => $this->getPhpStanVersion(),
			'phpVersion' => PHP_VERSION_ID,
			'configFiles' => $this->getConfigFiles(),
			'analysedPaths' => $this->analysedPaths,
			'composerLocks' => $this->getComposerLocks(),
			'phpExtensions' => $extensions,
			'stubFiles' => $this->getStubFiles(),
			'level' => $this->usedLevel,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function getConfigFiles(): array
	{
		$configFiles = [];
		foreach ($this->allCustomConfigFiles as $configFile) {
			$configFiles[$configFile] = $this->getFileHash($configFile);
		}

		return $configFiles;
	}

	private function getFileHash(string $path): string
	{
		if (array_key_exists($path, $this->fileHashes)) {
			return $this->fileHashes[$path];
		}

		$contents = FileReader::read($path);
		$contents = str_replace("\r\n", "\n", $contents);

		$hash = sha1($contents);
		$this->fileHashes[$path] = $hash;

		return $hash;
	}

	private function getPhpStanVersion(): string
	{
		try {
			return \Jean85\PrettyVersions::getVersion('phpstan/phpstan')->getPrettyVersion();
		} catch (\OutOfBoundsException $e) {
			return 'Version unknown';
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function getComposerLocks(): array
	{
		$locks = [];
		foreach ($this->composerAutoloaderProjectPaths as $autoloadPath) {
			$lockPath = $autoloadPath . '/composer.lock';
			if (!is_file($lockPath)) {
				continue;
			}

			$locks[$lockPath] = $this->getFileHash($lockPath);
		}

		return $locks;
	}

	/**
	 * @return array<string, string>
	 */
	private function getStubFiles(): array
	{
		$stubFiles = [];
		foreach ($this->stubFiles as $stubFile) {
			$stubFiles[$stubFile] = $this->getFileHash($stubFile);
		}

		return $stubFiles;
	}

	public function clear(): string
	{
		$dir = dirname($this->cacheFilePath);
		if (!is_file($this->cacheFilePath)) {
			return $dir;
		}

		@unlink($this->cacheFilePath);

		return $dir;
	}

}