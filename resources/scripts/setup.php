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
final class InitFailedException extends SetupException {}
final class MkDirFailedException extends SetupException {}
final class RealPathFailedException extends SetupException {}
final class ExecFailedException extends SetupException {}

/**
 * @property-read string $vhostsRoot
 * @property-read string $certRoot
 * @property-read string $domainName
 * @property-read string $projectRoot
 */
final class SetupParams
{
    private const DNS_LABEL_EXPR = '(?:[a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])';
    private const DNS_NAME_EXPR = '(?:' . self::DNS_LABEL_EXPR . '\.)*' . self::DNS_LABEL_EXPR;

    private const VHOSTS_ROOT_ARG = 'vhostsroot';
    private const CERT_ROOT_ARG = 'certroot';

    private const OPTIONS = [
        self::VHOSTS_ROOT_ARG . ':',
        self::CERT_ROOT_ARG . ':',
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
        if (!$args = \getopt('', self::OPTIONS, $last)) {
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

    /**
     * @throws RealPathFailedException
     */
    private function tryRealPath(string $description, string $path): string
    {
        $result = \realpath($path);

        if ($result === false) {
            throw new RealPathFailedException("Path '%s' for %s is invalid", $path, $description);
        }

        return $result;
    }

    /** @throws InitFailedException */
    public function __construct()
    {
        $this->assertRunningAsRoot();

        $args = $this->parseOptions($last);
        $name = $GLOBALS['argv'][$last] ?? '';

        $this->domainName = $this->validateDomainName($name);

        try {
            $this->vhostsRoot = $this->tryRealPath(
                'virtual hosts root',
                $args[self::VHOSTS_ROOT_ARG] ?? '/srv/www'
            );

            $this->certRoot = $this->tryRealPath(
                'ACME auth web root',
                $args[self::CERT_ROOT_ARG] ?? "{$this->vhostsRoot}/default/public"
            );
        } catch (RealPathFailedException $e) {
            throw new InitFailedException($e->getMessage());
        }

        $this->projectRoot = "{$this->vhostsRoot}/{$this->domainName}";
    }
}

const GIT_URL = 'git@github.com:DaveRandom/WebHost.git';

function output_error(string $format, string ...$args): int
{
    \fprintf(\STDERR, \rtrim($format) . "\n", ...$args);
    return 1;
}

function format_fs_mode(int $mode): string
{
    $result = '';

    for ($i = 9; $i > 0; $i--) {
        $result .= ($mode & (1 << ($i - 1)))
            ? 'rxw'[$i % 3]
            : '-';
    }

    return $result;
}

/** @throws MkDirFailedException */
function try_mkdir(string $path, int $mode, string $owner = null, string $group = null): void
{
    if (!\file_exists($path)) {
        if (!\mkdir($path, $mode, true)) {
            throw new MkDirFailedException('Failed to create directory %s with mode %s', $path, format_fs_mode($mode));
        }
    } else if (!\is_dir($path)) {
        throw new MkDirFailedException('Path %s already exists and is not a directory', $path);
    }

    if (!\chmod($path, $mode)) {
        throw new MkDirFailedException('Failed to set directory %s to mode %s', $path, format_fs_mode($mode));
    }

    if ($owner !== null && !\chown($path, $owner)) {
        throw new MkDirFailedException('Failed to change ownership of directory %s to %s', $path, $owner);
    }

    if ($group !== null && !\chgrp($path, $group)) {
        throw new MkDirFailedException('Failed to change group of directory %s to %s', $path, $group);
    }
}

/** @throws ExecFailedException */
function try_passthru(string ...$args): void
{
    $command = \implode(' ', \array_map('escapeshellarg', $args));
    \passthru($command, $exitCode);

    if ($exitCode !== 0) {
        throw new ExecFailedException('Command "%s" exited with code %d', $command, $exitCode);
    }
}

try {
    $params = new SetupParams();
} catch (InitFailedException $e) {
    exit(output_error('Initialization failed: %s', $e->getMessage()));
}

try {
    try_mkdir("{$params->projectRoot}/conf", 0755);
    try_mkdir("{$params->projectRoot}/logs", 0775, null, 'nginx');
    try_mkdir("{$params->projectRoot}/tmp",  0755);
} catch (MkDirFailedException $e) {
    exit(output_error($e->getMessage()));
}

try {
    try_passthru('git', 'clone', GIT_URL, "{$params->projectRoot}/app");
} catch (ExecFailedException $e) {
    exit(output_error($e->getMessage()));
}
