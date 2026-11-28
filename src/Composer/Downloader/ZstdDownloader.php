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

use Composer\Package\PackageInterface;
use Composer\Zstd\ExtractionException;
use Composer\Zstd\Extractor;
use React\Promise\PromiseInterface;

/**
 * Downloader for plain zstd compressed files (e.g. foo.phar.zst), the counterpart of GzipDownloader
 */
class ZstdDownloader extends ArchiveDownloader
{
    protected function validateDownloadedFile(PackageInterface $package, string $fileName): void
    {
        try {
            Extractor::validateFile($fileName);
        } catch (ExtractionException $e) {
            throw new TransportException('The downloaded file for '.$package->getName().' is truncated or corrupt: '.$e->getMessage(), 0, $e);
        }
    }

    protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        $filename = pathinfo((string) parse_url(strtr((string) $package->getDistUrl(), '\\', '/'), PHP_URL_PATH), PATHINFO_FILENAME);

        try {
            (new Extractor($this->config->get('cache-dir')))->decompress($file, $path.DIRECTORY_SEPARATOR.$filename);
        } catch (ExtractionException $e) {
            throw new \RuntimeException('Failed to extract '.$package->getName().': '.$e->getMessage(), 0, $e);
        }

        return \React\Promise\resolve(null);
    }
}
