<?php

declare(strict_types=1);

namespace BvdB\Installer;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use PharData;
use RuntimeException;

final class Setup
{
    private const AUTH_USER_ENV = 'BVDB_SETUP_USER';
    private const AUTH_PASS_ENV = 'BVDB_SETUP_PASSWORD';
    private const AUTH_BASIC_ENV = 'BVDB_SETUP_BASIC_AUTH';

    public static function run(Event $event): void
    {
        $io = $event->getIO();
        $extra = $event->getComposer()->getPackage()->getExtra();
        $config = $extra['bvdb'] ?? [];

        try {
            $root = getcwd();
            if ($root === false) {
                throw new RuntimeException('Cannot determine working directory.');
            }

            $tmpDir = $root . DIRECTORY_SEPARATOR . '.bvdb';
            self::ensureDir($tmpDir);

            $archivePath = $tmpDir . DIRECTORY_SEPARATOR . 'setup.tar.gz';
            $io->write('<info>Downloading setup archive…</info>');
            self::download($config['setup_url'] ?? '', $archivePath, $io);

            $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'extracted';
            $io->write('<info>Extracting setup archive…</info>');
            $tarPath = self::extractTarGz($archivePath, $extractDir);

            $setupRoot = self::locateSetupRoot($extractDir);
            self::fixConfigWebDir($setupRoot, $io);
            $map = (array) ($config['setup_map'] ?? []);
            self::applySetupMap($setupRoot, $root, $map, $io);

            $envPath = self::resolveEnvPath($root, $map);
            self::configureEnv($envPath, $config, $io);

            $cleanupTargets = [
                $archivePath,
                $tarPath,
                $extractDir,
                $root . DIRECTORY_SEPARATOR . '.bvdb',
                $root . DIRECTORY_SEPARATOR . '.security',
                $root . DIRECTORY_SEPARATOR . '.env.bak',
            ];

            self::cleanup($cleanupTargets, $io);
            $io->write('<info>✅ Installation complete via Composer.</info>');
        } catch (RuntimeException $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            throw $e;
        }
    }

    private static function download(string $url, string $destination, IOInterface $io): void
    {
        if ($url === '') {
            throw new RuntimeException('No setup_url configured in composer.json extra.bvdb.setup_url.');
        }

        $headers = self::buildAuthHeaders();
        $context = stream_context_create([
            'http' => ['header' => $headers],
            'https' => ['header' => $headers],
        ]);

        $contents = @file_get_contents($url, false, $context);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Failed to download setup archive from %s', $url));
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write archive to %s', $destination));
        }
    }

    private static function buildAuthHeaders(): array
    {
        $headers = [];

        $basic = getenv(self::AUTH_BASIC_ENV);
        if ($basic !== false && $basic !== '') {
            $headers[] = 'Authorization: Basic ' . $basic;
            return $headers;
        }

        $user = getenv(self::AUTH_USER_ENV);
        $pass = getenv(self::AUTH_PASS_ENV);
        if ($user !== false && $pass !== false && $user !== '' && $pass !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
        }

        return $headers;
    }

    private static function extractTarGz(string $archivePath, string $destination): string
    {
        if (!file_exists($archivePath)) {
            throw new RuntimeException('Setup archive not found: ' . $archivePath);
        }

        if (is_dir($destination)) {
            self::rrmdir($destination);
        }
        self::ensureDir($destination);

        $tarPath = preg_replace('/\\.gz$/', '', $archivePath);

        try {
            if (file_exists($tarPath)) {
                unlink($tarPath);
            }

            $phar = new PharData($archivePath);
            $phar->decompress(); // produces .tar
            $tar = new PharData($tarPath);
            $tar->extractTo($destination, null, true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to extract setup archive: ' . $e->getMessage(), 0, $e);
        }

        return $tarPath;
    }

    private static function locateSetupRoot(string $extractDir): string
    {
        $candidate = $extractDir . DIRECTORY_SEPARATOR . 'setup';
        if (is_dir($candidate)) {
            return $candidate;
        }

        return $extractDir;
    }

    private static function fixConfigWebDir(string $setupRoot, IOInterface $io): void
    {
        $configPath = $setupRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
        if (!file_exists($configPath)) {
            $io->write('<comment>No config/config.php found in setup package; skipping web_dir update.</comment>');
            return;
        }

        $contents = file_get_contents($configPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $configPath);
        }

        $pattern = '/^\\s*\\$web_dir\\s*=\\s*\\$root_dir\\s*\\.\\s*[\'"]\\/public_html[\'"]\\s*;\\s*$/m';
        $replacement = '$web_dir    = $root_dir . \'/public\';';
        $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

        if ($updated === null) {
            throw new RuntimeException('Failed to update ' . $configPath);
        }

        if ($count === 0) {
            $io->write('<comment>No web_dir assignment to update in config/config.php.</comment>');
            return;
        }

        if (file_put_contents($configPath, $updated) === false) {
            throw new RuntimeException('Unable to write ' . $configPath);
        }

        $io->write('<info>Updated web_dir in config/config.php to /public.</info>');
    }

    private static function applySetupMap(string $sourceRoot, string $projectRoot, array $map, IOInterface $io): void
    {
        if ($map === []) {
            $io->write('<comment>No setup_map configured; nothing to copy.</comment>');
            return;
        }

        foreach ($map as $relativeSource => $relativeDestination) {
            $source = $sourceRoot . DIRECTORY_SEPARATOR . ltrim($relativeSource, DIRECTORY_SEPARATOR);
            $destination = $projectRoot . DIRECTORY_SEPARATOR . ltrim($relativeDestination, DIRECTORY_SEPARATOR);

            if (!file_exists($source)) {
                $io->write(sprintf('<comment>Skipping %s (not found in setup package)</comment>', $relativeSource));
                continue;
            }

            $io->write(sprintf('<info>Installing %s → %s</info>', $relativeSource, $relativeDestination));
            self::copyPath($source, $destination);
        }
    }

    private static function resolveEnvPath(string $projectRoot, array $map): string
    {
        $envDestination = $map['.env'] ?? '.env';
        return $projectRoot . DIRECTORY_SEPARATOR . ltrim($envDestination, DIRECTORY_SEPARATOR);
    }

    private static function configureEnv(string $envPath, array $config, IOInterface $io): void
    {
        if (!file_exists($envPath)) {
            $io->write('<comment>No .env file found to configure.</comment>');
            return;
        }

        $io->write('<info>Configuring environment variables…</info>');
        self::injectRemoteMarker($envPath, 'WPSALTS', $config['salts_url'] ?? '', $io);
        self::injectRemoteMarker($envPath, 'WPLICENSES', $config['licenses_url'] ?? '', $io);

				$dbUser = self::ask($io, 'Database username', 'root');
        $dbPass = self::ask($io, 'Database password', '');
				$dbName = self::ask($io, 'Database username', 'wordpress');
        $dbPrefix = self::ask($io, 'Database prefix', 'vmst_');
				$dbHost = self::ask($io, 'Database host', '127.0.0.1');

        $domainDefault = basename(dirname($envPath));
        $domain = self::ask($io, 'Domain (for WP_HOME)', $domainDefault);
        $wpHome = self::ask($io, 'WP_HOME url', 'https://' . $domain);
        $wpEnv = self::ask($io, 'WP_ENV (development/acceptance/production)', 'development');

        self::setEnvValue($envPath, 'DB_NAME', $dbName);
        self::setEnvValue($envPath, 'DB_USER', $dbUser);
        self::setEnvValue($envPath, 'DB_PASSWORD', self::quote($dbPass));
        self::setEnvValue($envPath, 'DB_HOST', $dbHost);
        self::setEnvValue($envPath, 'DB_PREFIX', $dbPrefix);
        self::setEnvValue($envPath, 'WP_HOME', $wpHome);
        self::setEnvValue($envPath, 'WP_ENV', $wpEnv);
    }

    private static function cleanup(array $paths, IOInterface $io): void
    {
        foreach ($paths as $path) {
            if (is_dir($path)) {
                self::rrmdir($path);
                continue;
            }

            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $io->write('<info>Cleaned up temporary files.</info>');
    }

    private static function injectRemoteMarker(string $file, string $marker, string $url, IOInterface $io): void
    {
        if ($url === '') {
            $io->write(sprintf('<comment>No URL configured for %s; leaving placeholder.</comment>', $marker));
            return;
        }

        $headers = self::buildAuthHeaders();
        $context = stream_context_create([
            'http' => ['header' => $headers],
            'https' => ['header' => $headers],
        ]);

        $contents = @file_get_contents($url, false, $context);
        if ($contents === false) {
            $io->write(sprintf('<comment>Could not download %s; placeholder left unchanged.</comment>', $marker));
            return;
        }

        self::replaceMarkerWithContent($file, $marker, $contents);
        $io->write(sprintf('<info>Injected %s into .env</info>', $marker));
    }

    private static function replaceMarkerWithContent(string $file, string $marker, string $content): void
    {
        $existing = file_get_contents($file);
        if ($existing === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        $replacement = rtrim($content) . PHP_EOL;
        $pattern = '/^.*' . preg_quote($marker, '/') . '.*$/m';
        $updated = preg_replace($pattern, $replacement, $existing, 1, $count);

        if ($count === 0) {
            $updated = rtrim($existing) . PHP_EOL . $replacement;
        }

        if (file_put_contents($file, $updated) === false) {
            throw new RuntimeException('Unable to write ' . $file);
        }
    }

    private static function setEnvValue(string $envPath, string $key, string $value): void
    {
        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $envPath);
        }

        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        $line = $key . '=' . $value;
        $updated = preg_replace($pattern, $line, $contents, 1, $count);

        if ($count === 0) {
            $updated = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
        }

        if (file_put_contents($envPath, $updated) === false) {
            throw new RuntimeException('Unable to write ' . $envPath);
        }
    }

    private static function stripSecurityBlock(string $file): void
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        $pattern = '/^# BEGIN SECURITY.*?# END SECURITY:?$/ms';
        $updated = preg_replace($pattern, '', $contents);
        if ($updated === null) {
            throw new RuntimeException('Failed to update ' . $file);
        }

        file_put_contents($file, trim($updated) . PHP_EOL);
    }

    private static function replaceStringInFile(string $file, string $search, string $replace): void
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        $updated = str_replace($search, $replace, $contents);
        if (file_put_contents($file, $updated) === false) {
            throw new RuntimeException('Unable to write ' . $file);
        }
    }

    private static function copyPath(string $source, string $destination): void
    {
        if (is_dir($source)) {
            self::copyDirectory($source, $destination);
            return;
        }

        self::ensureDir(dirname($destination));

        if (file_exists($destination) && !is_dir($destination)) {
            $backup = $destination . '.bak';
            rename($destination, $backup);
        }

        if (!@copy($source, $destination)) {
            throw new RuntimeException(sprintf('Failed to copy %s to %s', $source, $destination));
        }
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                self::ensureDir($targetPath);
                continue;
            }

            self::ensureDir(dirname($targetPath));
            if (!@copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException(sprintf('Failed to copy %s to %s', $item->getPathname(), $targetPath));
            }
        }
    }

    private static function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory ' . $path);
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() ? rmdir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private static function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if ($value[0] === '"' && substr($value, -1) === '"') {
            return $value;
        }

        return '"' . $value . '"';
    }

    private static function ask(IOInterface $io, string $question, string $default = ''): string
    {
        $prompt = $question;
        if ($default !== '') {
            $prompt .= sprintf(' [%s]', $default);
        }
        $prompt .= ': ';

        $answer = $io->ask($prompt, $default);
        return is_string($answer) ? trim($answer) : $default;
    }
}
