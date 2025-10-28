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

namespace Composer\Command;

use Composer\Advisory\Auditor;
use Composer\Composer;
use Composer\Factory;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * NPX-style package executor command
 *
 * Installs a package in a temporary directory and executes a binary from it
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageExecutorCommand extends BaseCommand
{
    /**
     * @var string
     */
    private $packageName;

    /**
     * Configure the command for dynamic execution
     */
    public function __construct(string $packageName)
    {
        $this->packageName = $packageName;
        parent::__construct($packageName);
    }

    protected function configure(): void
    {
        $this
            ->setName($this->packageName)
            ->setDescription(sprintf('Execute a binary from package %s', $this->packageName))
            ->setDefinition([
                new InputArgument('binary', InputArgument::REQUIRED, 'Binary name to execute from the package'),
                new InputArgument('binary-args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Arguments to pass to the binary'),
                new InputOption('package-version', null, InputOption::VALUE_REQUIRED, 'Package version constraint to install'),
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist when possible'),
            ])
            ->setHelp(
                <<<EOT
The package executor installs a package temporarily and executes one of its binaries.

This is similar to npx from npm - it allows running package binaries without
permanently installing them in your project.

Example usage:
  <info>php composer.phar phpstan/phpstan phpstan -- analyze src/</info>
  <info>php composer.phar friendsofphp/php-cs-fixer php-cs-fixer -- fix</info>

Read more at https://getcomposer.org/doc/03-cli.md#package-execution
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $binaryName = $input->getArgument('binary');
        $binaryArgs = $input->getArgument('binary-args');
        $version = $input->getOption('package-version') ?: '*';

        if (!is_string($binaryName)) {
            $io->writeError('<error>Binary name must be a string</error>');
            return 1;
        }

        if (!is_array($binaryArgs)) {
            $binaryArgs = [];
        }

        $io->write(sprintf('<info>Executing %s from package %s</info>', $binaryName, $this->packageName));

        // Create temporary directory
        $tempDir = $this->createTempDirectory();
        $filesystem = new Filesystem();

        $originalCwd = getcwd();
        if ($originalCwd === false) {
            $originalCwd = $this->getApplication()->getInitialWorkingDirectory();
        }

        try {
            // Create minimal composer.json in temp directory
            $composerJsonPath = $tempDir . '/composer.json';
            $composerJson = [
                'name' => 'composer/package-executor-temp',
                'description' => 'Temporary project for package execution',
                'require' => [
                    $this->packageName => $version,
                ],
                'config' => [
                    'bin-dir' => 'bin',
                    'vendor-dir' => 'vendor',
                ],
            ];

            $jsonFile = new JsonFile($composerJsonPath);
            $jsonFile->write($composerJson);

            // Change to temporary directory for installation
            chdir($tempDir);

            // Create a Composer instance for the temporary directory
            $composer = Factory::create($io, $composerJsonPath, false, false);

            // Configure and run installation
            $install = Installer::create($io, $composer);

            $preferSource = $input->getOption('prefer-source');
            $preferDist = $input->getOption('prefer-dist');

            $install
                ->setVerbose($input->getOption('verbose'))
                ->setPreferSource($preferSource)
                ->setPreferDist($preferDist)
                ->setDevMode(false)
                ->setDumpAutoloader(false)
                ->setRunScripts(false)
                ->setAudit(false)
                ->setAuditFormat(Auditor::FORMAT_PLAIN);

            $status = $install->run();

            if ($status !== 0) {
                $io->writeError('<error>Failed to install package</error>');
                return $status;
            }

            // Find the binary
            $binaryPath = $this->findBinary($tempDir, $composer, $binaryName);

            if ($binaryPath === null) {
                $io->writeError(sprintf(
                    '<error>Binary "%s" not found in package "%s"</error>',
                    $binaryName,
                    $this->packageName
                ));
                return 1;
            }

            // Execute the binary (stay in temp directory so dependencies are accessible)
            $io->write(sprintf('<info>Running: %s %s</info>', $binaryPath, implode(' ', $binaryArgs)), true, IOInterface::VERBOSE);

            $exitCode = $this->executeBinary($binaryPath, $binaryArgs, $tempDir, $originalCwd);

            // Change back to original directory after execution
            if ($originalCwd !== false) {
                chdir($originalCwd);
            }

            return $exitCode;

        } finally {
            // Ensure we're back in original directory
            if ($originalCwd !== false && getcwd() !== $originalCwd) {
                chdir($originalCwd);
            }

            // Cleanup temporary directory
            if (is_dir($tempDir)) {
                $filesystem->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Create a unique temporary directory
     */
    private function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/composer_pkg_exec_' . bin2hex(random_bytes(8));

        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new \RuntimeException(sprintf('Unable to create temporary directory: %s', $tempDir));
        }

        return $tempDir;
    }

    /**
     * Find the binary in the installed package
     */
    private function findBinary(string $tempDir, Composer $composer, string $binaryName): ?string
    {
        // Check in bin directory first (proxy binaries created by BinaryInstaller)
        $binDir = $tempDir . '/bin/' . $binaryName;
        if (file_exists($binDir) && is_file($binDir)) {
            return $binDir;
        }

        // Also check for Windows batch files
        if (Platform::isWindows() && file_exists($binDir . '.bat')) {
            return $binDir . '.bat';
        }

        // Try to find the binary in the vendor directory directly
        $repositoryManager = $composer->getRepositoryManager();
        $localRepo = $repositoryManager->getLocalRepository();

        foreach ($localRepo->getPackages() as $package) {
            if ($package->getName() === $this->packageName) {
                $binaries = $package->getBinaries();
                foreach ($binaries as $binary) {
                    $binaryBasename = basename($binary);
                    // Remove extension for comparison
                    $binaryBasenameNoExt = preg_replace('/\.(php|sh|bat)$/', '', $binaryBasename);

                    if ($binaryBasename === $binaryName || $binaryBasenameNoExt === $binaryName) {
                        $vendorDir = $composer->getConfig()->get('vendor-dir');
                        $installPath = $composer->getInstallationManager()->getInstallPath($package);

                        if ($installPath !== null) {
                            $binaryPath = $installPath . '/' . $binary;
                            if (file_exists($binaryPath)) {
                                return $binaryPath;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Execute the binary with given arguments
     */
    private function executeBinary(string $binaryPath, array $args, string $tempDir, $originalCwd): int
    {
        // Make binary executable on Unix systems
        if (!Platform::isWindows()) {
            @chmod($binaryPath, 0777 & ~umask());
        }

        // Build command with proper escaping
        // We need to cd to the original working directory so the binary executes in the right context
        // but with access to its dependencies in the temp directory
        $cmd = '';

        // If we have an original working directory different from temp, cd there first
        if ($originalCwd !== false && $originalCwd !== $tempDir) {
            if (Platform::isWindows()) {
                $cmd .= 'cd /d ' . escapeshellarg($originalCwd) . ' && ';
            } else {
                $cmd .= 'cd ' . escapeshellarg($originalCwd) . ' && ';
            }
        }

        $cmd .= escapeshellarg($binaryPath);

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        // Set environment variable for the autoloader path
        $autoloadPath = $tempDir . '/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            putenv('COMPOSER_RUNTIME_AUTOLOAD=' . $autoloadPath);
        }

        // Execute with passthru to stream output in real-time
        passthru($cmd, $exitCode);

        return $exitCode ?? 1;
    }
}
