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
use Composer\Downloader\TarZstDownloader;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;

class TarZstDownloaderTest extends TestCase
{
    /** @var string */
    private $testDir;
    /** @var string */
    private $vendorDir;
    /** @var ProcessExecutor */
    private $process;

    protected function setUp(): void
    {
        $this->testDir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->removeDirectory($this->testDir);
    }

    /**
     * @dataProvider provideTargets
     */
    public function testInstallKeepsTheArchiveLayout(bool $nonEmptyTarget): void
    {
        $path = $this->testDir.'/target';
        // a vendor dir inside the target keeps it from being empty, like create-project into the current directory
        $this->vendorDir = $nonEmptyTarget ? $path.'/vendor' : $this->testDir.'/vendor';
        mkdir($this->vendorDir, 0777, true);

        // a single top-level directory is part of the package, it does not get unwrapped
        $this->install(self::tarZst(['src/Foo.php' => '<?php class Foo {}']), $path);

        self::assertSame('<?php class Foo {}', file_get_contents($path.'/src/Foo.php'));
        self::assertSame([], glob($this->vendorDir.'/composer/*'), 'no temporary directory or archive is left');
    }

    public static function provideTargets(): array
    {
        return [
            'empty target, extracted in place' => [false],
            'non-empty target, merged' => [true],
        ];
    }

    public function testFailedExtractionRemovesTheTarget(): void
    {
        $path = $this->testDir.'/target';
        $this->vendorDir = $this->testDir.'/vendor';
        mkdir($this->vendorDir, 0777, true);

        try {
            $this->install(self::tarZst(['../evil' => 'x']), $path);
            self::fail('Expected an exception');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Failed to extract foo/bar: ', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($path);
        self::assertFileDoesNotExist($this->testDir.'/evil');
    }

    public function testTruncatedDownloadIsRejected(): void
    {
        $this->vendorDir = $this->testDir.'/vendor';
        $file = $this->testDir.'/archive.tar.zst';
        file_put_contents($file, substr(self::tarZst(['a' => 'b']), 0, 50));

        $method = new \ReflectionMethod(TarZstDownloader::class, 'validateDownloadedFile');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::expectException(TransportException::class);
        self::expectExceptionMessage('truncated or corrupt');
        $method->invoke($this->createDownloader(), new Package('foo/bar', '1.0.0.0', '1.0.0'), $file);
    }

    private function install(string $archive, string $path): void
    {
        $downloader = $this->createDownloader();
        $package = new Package('foo/bar', '1.0.0.0', '1.0.0');
        $package->setDistUrl('https://example.org/foo.tar.zst');
        $package->setDistType('tar-zst');

        $method = new \ReflectionMethod($downloader, 'getFileName');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $file = $method->invoke($downloader, $package, $path);
        @mkdir(dirname($file), 0777, true);
        @mkdir($path, 0777, true);
        file_put_contents($file, $archive);

        (new Loop(new HttpDownloader($this->getMockBuilder(IOInterface::class)->getMock(), new Config()), $this->process))
            ->wait([$downloader->install($package, $path, false)]);
    }

    private function createDownloader(): TarZstDownloader
    {
        $config = $this->getMockBuilder(Config::class)->getMock();
        $config->method('get')->willReturnCallback(function (string $key) {
            return $key === 'cache-dir' ? $this->testDir.'/cache' : $this->vendorDir;
        });
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $this->process = new ProcessExecutor($io);

        return new TarZstDownloader($io, $config, new HttpDownloader($io, $config), null, null, new Filesystem($this->process), $this->process);
    }

    /**
     * A tar archive of regular files, in a zstd frame of raw blocks
     *
     * @param array<string, string> $files
     */
    private static function tarZst(array $files): string
    {
        $tar = '';
        foreach ($files as $name => $content) {
            $header = str_pad($name, 100, "\0").str_pad('0000644', 8, "\0").str_repeat("\0", 16)
                .str_pad(decoct(strlen($content)), 11, '0', STR_PAD_LEFT)."\0".str_repeat('0', 11)."\0".'        0';
            $header = str_pad($header.str_repeat("\0", 100).'ustar'."\0".'00', 512, "\0");
            $sum = array_sum(array_map('ord', str_split($header)));
            $header = substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
            $tar .= $header.str_pad($content, (int) ceil(strlen($content) / 512) * 512, "\0");
        }
        $tar .= str_repeat("\0", 1024);

        // single segment, 8-byte content size, one raw block
        $out = pack('V', 0xFD2FB528)."\xE0".pack('P', strlen($tar));
        $bh = 1 | (strlen($tar) << 3);

        return $out.chr($bh & 0xFF).chr(($bh >> 8) & 0xFF).chr($bh >> 16).$tar;
    }
}
