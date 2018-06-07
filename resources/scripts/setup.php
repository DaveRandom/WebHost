#!/usr/bin/env php
<?php declare(strict_types=1);

namespace DaveRandom\WebHost;

abstract class SetupException extends \Exception
{
    public function __construct(string $format, string ...$args)
    {
        parent::__construct(\vsprintf($format, $args));
    }
}
abstract class FileSystemOperationFailedException extends SetupException {}
final class InitFailedException extends SetupException {}
final class ChModFailedException extends FileSystemOperationFailedException {}
final class ChOwnFailedException extends FileSystemOperationFailedException {}
final class ChGrpFailedException extends FileSystemOperationFailedException {}
final class MkDirFailedException extends FileSystemOperationFailedException {}
final class SymLinkFailedException extends FileSystemOperationFailedException {}
final class RealPathFailedException extends FileSystemOperationFailedException {}
final class FileReadFailedException extends FileSystemOperationFailedException {}
final class FileWriteFailedException extends FileSystemOperationFailedException {}
final class ExecFailedException extends SetupException {}
final class TemplateRenderFailedException extends SetupException {}

final class FileSystem
{
    private static function formatMode(int $mode): string
    {
        $result = '';

        for ($i = 9; $i > 0; $i--) {
            $result .= ($mode & (1 << ($i - 1)))
                ? 'rxw'[$i % 3]
                : '-';
        }

        return $result;
    }

    /**
     * @throws ChGrpFailedException
     * @throws ChModFailedException
     * @throws ChOwnFailedException
     * @throws MkDirFailedException
     */
    public static function mkdir(string $path, ?int $mode = 0755, string $owner = null, string $group = null): void
    {
        $mode = $mode ?? 0755;

        if (!\file_exists($path) && !\mkdir($path, $mode, true)) {
            throw new MkDirFailedException('Failed to create directory %s with mode %s', $path, self::formatMode($mode));
        }

        if (!\is_dir($path)) {
            throw new MkDirFailedException('Path %s exists and is not a directory', $path);
        }

        self::chmod($path, $mode);

        if ($owner !== null) {
            self::chown($path, $owner);
        }

        if ($group !== null) {
            self::chgrp($path, $group);
        }
    }

    /** @throws RealPathFailedException */
    public static function realpath(string $path): string
    {
        $result = \realpath($path);

        if ($result === false) {
            throw new RealPathFailedException("Path '%s' is invalid", $path);
        }

        return $result;
    }

    /** @throws ChModFailedException */
    public static function chmod(string $path, int $mode): void
    {
        if (!\chmod($path, $mode)) {
            throw new ChModFailedException('Failed to set %s to mode %s', $path, self::formatMode($mode));
        }
    }

    /**
     * @param string|int $owner
     * @throws ChOwnFailedException
     */
    public static function chown(string $path, $owner): void
    {
        if (!\chown($path, $owner)) {
            throw new ChOwnFailedException('Failed to change ownership of %s to %s', $path, $owner);
        }
    }

    /**
     * @param string|int $group
     * @throws ChGrpFailedException
     */
    public static function chgrp(string $path, $group): void
    {
        if (!\chgrp($path, $group)) {
            throw new ChGrpFailedException('Failed to change ownership of %s to %s', $path, $group);
        }
    }

    /** @throws SymLinkFailedException */
    public static function symlink(string $target, string $path): void
    {
        if (!\symlink($target, $path)) {
            throw new SymLinkFailedException("Creating link to %s at %s failed", $target, $path);
        }
    }
}

final class Shell
{
    /** @throws ExecFailedException */
    public static function passthru(string ...$args): void
    {
        $command = \implode(' ', \array_map('escapeshellarg', $args));
        \passthru($command, $exitCode);

        if ($exitCode !== 0) {
            throw new ExecFailedException('Command "%s" exited with code %d', $command, (string)$exitCode);
        }
    }

    /** @throws ExecFailedException */
    public static function exec(string ...$args): void
    {
        $command = \implode(' ', \array_map('escapeshellarg', $args));
        \exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new ExecFailedException('Command "%s" exited with code %d', $command, (string)$exitCode);
        }
    }
}

/**
 * @property-read string $appName
 * @property-read string $domainName
 * @property-read string $nginxUser
 * @property-read string $nginxConfDir
 * @property-read string $fpmConfDir
 * @property-read string $vhostsRootDir
 * @property-read string $certRootDir
 * @property-read string $projectRootDir
 * @property-read string $confDir
 * @property-read string $logsDir
 * @property-read string $logsArchiveDir
 * @property-read string $tmpDir
 * @property-read string $appDir
 */
final class SetupParams
{
    private const DNS_LABEL_EXPR = '(?:[a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])';
    private const DNS_NAME_EXPR = '(?:' . self::DNS_LABEL_EXPR . '\.)*' . self::DNS_LABEL_EXPR;

    private const APP_NAME_ARG = 'appname';
    private const VHOSTS_ROOT_ARG = 'vhosts';
    private const CERT_ROOT_ARG = 'cert';
    private const NGINX_USER_ARG = 'nginx-user';
    private const NGINX_CONF_ARG = 'nginx-conf';
    private const FPM_CONF_ARG = 'fpm-conf';

    private const OPTIONS = [
        self::VHOSTS_ROOT_ARG . ':',
        self::CERT_ROOT_ARG . ':',
        self::NGINX_USER_ARG . ':',
    ];

    /** @throws InitFailedException */
    private function assertRunningAsRoot(): void
    {
        if (\posix_getuid() !== 0) {
            throw new InitFailedException('This script must be run as root');
        }
    }

    /** @throws InitFailedException */
    private function parseOptions(int &$last = null): array
    {
        if (false === $args = \getopt('', self::OPTIONS, $last)) {
            throw new InitFailedException('Parsing command line options failed');
        }

        return $args;
    }

    /** @throws InitFailedException */
    private function validateDomainName(string $name): string
    {
        if (!\preg_match('(^' . self::DNS_NAME_EXPR . '$)i', $name)) {
            throw new InitFailedException("'%s' is not a valid DNS name", $name);
        }

        return $name;
    }

    /** @throws InitFailedException */
    public function __construct()
    {
        $this->assertRunningAsRoot();

        $args = $this->parseOptions($last);

        $this->appName = $args[self::APP_NAME_ARG] ?? 'webhost';
        $this->domainName = $this->validateDomainName($GLOBALS['argv'][$last] ?? $this->appName);

        try {
            $this->vhostsRootDir = FileSystem::realpath($args[self::VHOSTS_ROOT_ARG] ?? '/srv/www');
            $this->certRootDir = FileSystem::realpath($args[self::CERT_ROOT_ARG] ?? "{$this->vhostsRootDir}/default/public");
            $this->nginxConfDir = FileSystem::realpath($args[self::NGINX_CONF_ARG] ?? '/etc/nginx/conf.d');
            $this->fpmConfDir = FileSystem::realpath($args[self::FPM_CONF_ARG] ?? '/etc/php-fpm.d');
        } catch (RealPathFailedException $e) {
            throw new InitFailedException($e->getMessage());
        }

        $this->nginxUser = $args[self::NGINX_USER_ARG] ?? 'nginx';

        $this->projectRootDir = "{$this->vhostsRootDir}/{$this->appName}";
        $this->appDir = "{$this->projectRootDir}/app";
        $this->confDir = "{$this->projectRootDir}/conf";
        $this->logsDir = "{$this->projectRootDir}/logs";
        $this->logsArchiveDir = "{$this->logsDir}/archive";
        $this->tmpDir = "{$this->projectRootDir}/tmp";
    }

    public function getTemplateVars(): array
    {
        return [
            'APP_NAME' => $this->appName,
            'APP_DIR'  => $this->appDir,
            'CONF_DIR' => $this->confDir,
            'LOGS_DIR' => $this->logsDir,
            'TMP_DIR'  => $this->tmpDir,
            'FPM_SOCK' => "/var/run/php-fpm/{$this->appName}.sock",
            'PRIMARY_DOMAIN' => $this->domainName,
        ];
    }
}

final class Template
{
    private $data;

    /**
     * @throws RealPathFailedException
     * @throws FileReadFailedException
     */
    public function __construct(string $path)
    {
        $path = FileSystem::realpath($path);

        if (false === $this->data = \file_get_contents($path)) {
            throw new FileReadFailedException('Failed to read contents of %s', $path);
        }
    }

    public function render(array $vars): string
    {
        return \preg_replace_callback('(%([a-z_][a-z0-9_]*)%)i', function($match) use($vars) {
            if (!\array_key_exists($match[1], $vars)) {
                throw new TemplateRenderFailedException("Template variable '%s' not defined", $match[1]);
            }

            return $vars[$match[1]];
        }, $this->data);
    }

    /**
     * @throws FileWriteFailedException
     */
    public function renderToFile(string $path, array $vars): void
    {
        $data = $this->render($vars);

        if (!\file_put_contents($path, $data)) {
            throw new FileWriteFailedException('Failed to write %d bytes to %s', \strlen($data), $path);
        }
    }
}

const GIT_URL = 'git@github.com:DaveRandom/WebHost.git';

function output_error(string $format, string ...$args): int
{
    \fprintf(\STDERR, \rtrim($format) . "\n", ...$args);
    return 1;
}

/** @throws ExecFailedException */
function try_passthru(string ...$args): void
{
    $command = \implode(' ', \array_map('escapeshellarg', $args));
    Shell::passthru($command, $exitCode);

    if ($exitCode !== 0) {
        throw new ExecFailedException('Command "%s" exited with code %d', $command, (string)$exitCode);
    }
}

/** @throws InitFailedException */
function get_certbot_cmd(): string
{
    foreach (['certbot', 'certbot-auto'] as $cmd) {
        try {
            Shell::exec($cmd, '--version');
            return $cmd;
        } catch (ExecFailedException $e) { }
    }

    throw new InitFailedException('Cannot locate a usable certbot command');
}

try {
    $params = new SetupParams();

    // try to get the cert first
    Shell::passthru(
        get_certbot_cmd(), 'certonly', '--webroot',
        '--cert-name', $params->appName, // The name of cert under /etc/letsencrypt
        '-w', $params->certRootDir,      // The web root of http:// access
        '-d', $params->domainName        // The primary domain name for the cert
    );

    FileSystem::mkdir($params->confDir, 0755);

    FileSystem::mkdir($params->logsDir, 0775, null, $params->nginxUser);
    FileSystem::mkdir($params->logsArchiveDir, 0755);

    FileSystem::mkdir($params->tmpDir, 0755);
    FileSystem::mkdir("{$params->tmpDir}/sessions", 0755);
    FileSystem::mkdir("{$params->tmpDir}/wsdlcache", 0755);
    FileSystem::mkdir("{$params->tmpDir}/opcache", 0755);

    Shell::passthru('git', 'clone', GIT_URL, $params->appDir);

    foreach (['nginx', 'fpm', 'logrotate'] as $file) {
        (new Template("{$params->appDir}/resources/conf/{$file}.conf"))
            ->renderToFile("{$params->confDir}/{$file}.conf", $params->getTemplateVars());
    }

    FileSystem::symlink("{$params->confDir}/nginx.conf", "{$params->nginxConfDir}/{$params->appName}.conf");
    FileSystem::symlink("{$params->confDir}/fpm.conf", "{$params->fpmConfDir}/{$params->appName}.conf");
    FileSystem::symlink("{$params->confDir}/logrotate.conf", "/etc/logrotate.d/{$params->appName}.conf");

    Shell::passthru('service', 'nginx', 'reload');
    Shell::passthru('service', 'php-fpm', 'reload');
} catch (InitFailedException $e) {
    exit(output_error('Initialization failed: %s', $e->getMessage()));
} catch (SetupException $e) {
    exit(output_error($e->getMessage()));
}
