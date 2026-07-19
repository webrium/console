<?php

declare(strict_types=1);

namespace Webrium\Console\Plugin;

use JsonException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

final class PluginAuthoringConfig
{
    public const CONFIG_FILE = '.webrium.conf.json';
    public const DEFAULT_ROOT = 'storage/app/plugins';

    public static function resolve(?string $option, SymfonyStyle $io): ?string
    {
        $projectRoot = self::projectRoot();
        $configuredRoot = $option;

        if ($configuredRoot === null) {
            $configuredRoot = self::readConfiguredRoot($projectRoot, $io);
            if ($configuredRoot === false) {
                return null;
            }
        }

        $relativeRoot = self::normalizeRelativeRoot(
            $configuredRoot ?? self::DEFAULT_ROOT,
            $io
        );

        if ($relativeRoot === null) {
            return null;
        }

        $absoluteRoot = $projectRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);

        $existingPath = $absoluteRoot;
        while (!file_exists($existingPath)) {
            $parent = dirname($existingPath);
            if ($parent === $existingPath) {
                break;
            }
            $existingPath = $parent;
        }

        $resolvedExistingPath = realpath($existingPath);
        $projectPrefix = $projectRoot . DIRECTORY_SEPARATOR;
        if ($resolvedExistingPath === false || (
            $resolvedExistingPath !== $projectRoot
            && !str_starts_with($resolvedExistingPath, $projectPrefix)
        )) {
            $io->error('Plugin authoring root must resolve inside the project root.');
            return null;
        }

        if (file_exists($absoluteRoot)) {
            if (!is_dir($absoluteRoot)) {
                $io->error('Plugin authoring root must be a directory.');
                return null;
            }

            $resolvedRoot = realpath($absoluteRoot);

            if ($resolvedRoot === false || (
                $resolvedRoot !== $projectRoot
                && !str_starts_with($resolvedRoot, $projectPrefix)
            )) {
                $io->error('Plugin authoring root must resolve inside the project root.');
                return null;
            }

            return $resolvedRoot;
        }

        return $absoluteRoot;
    }

    /**
     * @return string|false|null
     */
    private static function readConfiguredRoot(string $projectRoot, SymfonyStyle $io)
    {
        $configPath = $projectRoot . DIRECTORY_SEPARATOR . self::CONFIG_FILE;

        if (!is_file($configPath)) {
            return null;
        }

        try {
            $config = json_decode(
                (string) file_get_contents($configPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            $io->error(self::CONFIG_FILE . ' is not valid JSON: ' . $e->getMessage());
            return false;
        }

        if (!is_array($config)) {
            $io->error(self::CONFIG_FILE . ' must contain a JSON object.');
            return false;
        }

        if (!array_key_exists('console', $config)) {
            return null;
        }

        if (!is_array($config['console'])) {
            $io->error("The 'console' key in " . self::CONFIG_FILE . ' must be an object.');
            return false;
        }

        if (!array_key_exists('authoring_root', $config['console'])) {
            return null;
        }

        $root = $config['console']['authoring_root'];
        if (!is_string($root) || trim($root) === '') {
            $io->error("The 'console.authoring_root' value in " . self::CONFIG_FILE . ' must be a non-empty string.');
            return false;
        }

        return $root;
    }

    private static function normalizeRelativeRoot(string $root, SymfonyStyle $io): ?string
    {
        $root = str_replace('\\', '/', trim($root));

        if ($root === '' || str_starts_with($root, '/') || preg_match('/^[A-Za-z]:\//', $root)) {
            $io->error('Plugin authoring root must be a non-empty path relative to the project root.');
            return null;
        }

        $segments = [];
        foreach (explode('/', $root) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $io->error("Plugin authoring root cannot contain '..' path traversal segments.");
                return null;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            $io->error('Plugin authoring root must not resolve to the project root.');
            return null;
        }

        return implode('/', $segments);
    }

    private static function projectRoot(): string
    {
        $root = Directory::path('root');
        $resolved = $root !== null ? realpath($root) : false;

        return $resolved ?: (realpath(Directory::path('app') . '/../') ?: getcwd());
    }
}
