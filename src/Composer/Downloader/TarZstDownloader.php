<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Package\PackageInterface;
use Composer\Util\Platform;
use Composer\Zstd\ExtractionException;
use Composer\Zstd\Extractor;
use React\Promise\PromiseInterface;
use Symfony\Component\Process\Process;

/**
 * Downloader for .tar.zst archives, which hold the package files at their root
 */
class TarZstDownloader extends ArchiveDownloader
{
    /** @var Extractor|null */
    private $extractor;

    /**
     * @internal
     */
    public function getExtractor(): Extractor
    {
        if ($this->extractor === null) {
            $this->extractor = new Extractor($this->config->get('cache-dir'));
        }

        return $this->extractor;
    }

    /**
     * The archive holds the package files at its root, so it is extracted straight into the empty install path.
     * Only a path which is not empty (e.g. packages installed into each other's paths) goes through a temporary
     * directory and gets merged.
     */
    public function install(PackageInterface $package, string $path, bool $output = true): PromiseInterface
    {
        if (!is_dir($path) || !$this->filesystem->isDirEmpty($path)) {
            return parent::install($package, $path, $output);
        }

        if ($output) {
            $this->io->writeError("  - " . InstallOperation::format($package) . $this->getInstallOperationAppendix($package, $path));
        }

        $isCwd = realpath($path) === Platform::getCwd();
        if (!$isCwd) {
            $this->addCleanupPath($package, $path);
        }
        $fileName = $this->getFileName($package, $path);
        $cleanup = function () use ($package, $path, $isCwd): void {
            // remove cache if the file was corrupted
            $this->clearLastCacheWrite($package);
            if (!$isCwd && is_dir($path)) {
                $this->filesystem->removeDirectory($path);
            }
            $this->removeCleanupPath($package, $path);
        };

        try {
            $promise = $this->extract($package, $fileName, $path);
        } catch (\Exception $e) {
            $cleanup();
            throw $e;
        }

        return $promise->then(function () use ($package, $path, $fileName): void {
            if (file_exists($fileName)) {
                $this->filesystem->unlink($fileName);
            }
            $this->removeCleanupPath($package, $path);
        }, static function ($e) use ($cleanup) {
            $cleanup();

            throw $e;
        });
    }

    protected function unwrapsSingleDirectory(): bool
    {
        return false;
    }

    protected function validateDownloadedFile(PackageInterface $package, string $fileName): void
    {
        try {
            Extractor::validateFile($fileName);
        } catch (ExtractionException $e) {
            throw new TransportException('The downloaded archive for '.$package->getName().' is truncated or corrupt: '.$e->getMessage(), 0, $e);
        }
    }

    protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        try {
            $extraction = $this->getExtractor()->prepare($file, $path);
            $command = $extraction->getCommand();
            if ($command === null) {
                $extraction->run();

                return \React\Promise\resolve(null);
            }
        } catch (ExtractionException $e) {
            throw $this->extractionFailed($package, $e);
        }

        return $this->process->executeAsync($command)->then(function (Process $process) use ($extraction, $package): void {
            if (!$process->isSuccessful() && isset($this->cleanupExecuted[$package->getName()])) {
                throw new \RuntimeException('Failed to extract '.$package->getName().' as the installation was aborted by another package operation.');
            }
            try {
                $extraction->complete($process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
            } catch (ExtractionException $e) {
                throw $this->extractionFailed($package, $e);
            }
        });
    }

    private function extractionFailed(PackageInterface $package, ExtractionException $e): \RuntimeException
    {
        return new \RuntimeException('Failed to extract '.$package->getName().': '.$e->getMessage(), 0, $e);
    }
}
