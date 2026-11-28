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

namespace Composer\Test\Downloader;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\Downloader\ZstdDownloader;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;

class ZstdDownloaderTest extends TestCase
{
    /** @var string */
    private $testDir;

    protected function setUp(): void
    {
        $this->testDir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->removeDirectory($this->testDir);
    }

    public function testInstallDecompressesTheFile(): void
    {
        $path = $this->testDir.'/target';
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $process = new ProcessExecutor($io);
        $downloader = $this->createDownloader($io, $process);
        $package = new Package('foo/bar', '1.0.0.0', '1.0.0');
        $package->setDistUrl('https://example.org/foo.phar.zst?token=x');
        $package->setDistType('zstd');

        $method = new \ReflectionMethod($downloader, 'getFileName');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $file = $method->invoke($downloader, $package, $path);
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, self::zst('<?php echo "hi";'));

        (new Loop(new HttpDownloader($io, new Config()), $process))->wait([$downloader->install($package, $path, false)]);

        self::assertSame(['foo.phar'], array_values(array_diff((array) scandir($path), ['.', '..'])));
        self::assertSame('<?php echo "hi";', file_get_contents($path.'/foo.phar'));
    }

    public function testTruncatedDownloadIsRejected(): void
    {
        $file = $this->testDir.'/foo.phar.zst';
        file_put_contents($file, substr(self::zst('abc'), 0, 10));

        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $method = new \ReflectionMethod(ZstdDownloader::class, 'validateDownloadedFile');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::expectException(TransportException::class);
        self::expectExceptionMessage('truncated or corrupt');
        $method->invoke($this->createDownloader($io, new ProcessExecutor($io)), new Package('foo/bar', '1.0.0.0', '1.0.0'), $file);
    }

    private function createDownloader(IOInterface $io, ProcessExecutor $process): ZstdDownloader
    {
        $config = $this->getMockBuilder(Config::class)->getMock();
        $config->method('get')->willReturnCallback(function (string $key) {
            return $key === 'cache-dir' ? $this->testDir.'/cache' : $this->testDir.'/vendor';
        });

        return new ZstdDownloader($io, $config, new HttpDownloader($io, $config), null, null, new Filesystem($process), $process);
    }

    /**
     * A zstd frame of one raw block
     */
    private static function zst(string $data): string
    {
        $bh = 1 | (strlen($data) << 3);

        return pack('V', 0xFD2FB528)."\xE0".pack('P', strlen($data)).chr($bh & 0xFF).chr(($bh >> 8) & 0xFF).chr($bh >> 16).$data;
    }
}
