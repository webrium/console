<?php

declare(strict_types=1);

namespace Webrium\Console\Plugin;

use JsonException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

final class PluginPathConfig
{
    public const CONFIG_FILE = '.webrium.conf.json';

    private const DEFAULTS = [
        'registry' => 'storage/app/plugins/plugins.json',
        'overrides' => 'storage/app/plugins/plugins.overrides.json',
        'compiled' => 'storage/framework/cache/plugins.compiled.json',
        'definitions' => 'storage/app/plugins/definitions',
        'dist' => 'storage/app/plugins/dist',
        'backups' => 'storage/app/plugins/backups',
    ];

    /**
     * Resolve every plugin-system path to an absolute path inside the project.
     *
     * @return array<string, string>|null
     */
    public static function resolve(SymfonyStyle $io): ?array
    {
        $projectRoot = self::projectRoot();
        $values = self::DEFAULTS;
        $config = self::readConfig($projectRoot, $io);

        if ($config === null) {
            return null;
        }

        $console = $config->console ?? new \stdClass();
        if (!$console instanceof \stdClass) {
            $io->error("The 'console' key in " . self::CONFIG_FILE . ' must be an object.');
            return null;
        }

        if (property_exists($console, 'authoring_root')) {
            $io->error(
                "The 'console.authoring_root' setting is not supported. "
                . "Configure 'console.plugins.definitions' and 'console.plugins.dist' instead."
            );
            return null;
        }

        if (property_exists($console, 'plugins')) {
            if (!$console->plugins instanceof \stdClass) {
                $io->error("The 'console.plugins' key in " . self::CONFIG_FILE . ' must be an object.');
                return null;
            }

            $pluginPaths = get_object_vars($console->plugins);
            $unknown = array_diff(array_keys($pluginPaths), array_keys(self::DEFAULTS));
            if ($unknown !== []) {
                $io->error('Unknown console.plugins path key(s): ' . implode(', ', $unknown));
                return null;
            }

            foreach ($pluginPaths as $key => $value) {
                if (!is_string($value) || trim($value) === '') {
                    $io->error("The 'console.plugins.$key' value must be a non-empty string.");
                    return null;
                }
                $values[$key] = $value;
            }
        }

        $resolved = [];
        foreach ($values as $key => $value) {
            $path = self::resolveRelativePath($projectRoot, $value, $key, $io);
            if ($path === null) {
                return null;
            }
            $resolved[$key] = $path;
        }

        if (count(array_unique($resolved)) !== count($resolved)) {
            $io->error('Each console.plugins path must resolve to a distinct location.');
            return null;
        }

        foreach (['registry', 'overrides', 'compiled'] as $fileKey) {
            if (is_dir($resolved[$fileKey])) {
                $io->error("The 'console.plugins.$fileKey' path must point to a file, not a directory.");
                return null;
            }
        }

        foreach (['definitions', 'dist', 'backups'] as $directoryKey) {
            if (is_file($resolved[$directoryKey])) {
                $io->error("The 'console.plugins.$directoryKey' path must point to a directory, not a file.");
                return null;
            }
        }

        return $resolved;
    }

    private static function readConfig(string $projectRoot, SymfonyStyle $io): ?\stdClass
    {
        $configPath = $projectRoot . DIRECTORY_SEPARATOR . self::CONFIG_FILE;
        if (!is_file($configPath)) {
            return new \stdClass();
        }

        try {
            $config = json_decode(
                (string) file_get_contents($configPath),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            $io->error(self::CONFIG_FILE . ' is not valid JSON: ' . $e->getMessage());
            return null;
        }

        if (!$config instanceof \stdClass) {
            $io->error(self::CONFIG_FILE . ' must contain a JSON object.');
            return null;
        }

        return $config;
    }

    private static function resolveRelativePath(
        string $projectRoot,
        string $path,
        string $key,
        SymfonyStyle $io
    ): ?string {
        $relative = str_replace('\\', '/', trim($path));
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative)) {
            $io->error("The 'console.plugins.$key' path must be relative to the project root.");
            return null;
        }

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $io->error("Invalid path traversal in 'console.plugins.$key': '..' segments are not allowed.");
                return null;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            $io->error("The 'console.plugins.$key' path must not resolve to the project root.");
            return null;
        }

        $absolute = $projectRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $existing = $absolute;
        while (!file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }

        $resolvedExisting = realpath($existing);
        $prefix = $projectRoot . DIRECTORY_SEPARATOR;
        if ($resolvedExisting === false || (
            $resolvedExisting !== $projectRoot
            && !str_starts_with($resolvedExisting, $prefix)
        )) {
            $io->error("The 'console.plugins.$key' path must resolve inside the project root.");
            return null;
        }

        if (file_exists($absolute)) {
            $resolved = realpath($absolute);
            if ($resolved === false || (
                $resolved !== $projectRoot
                && !str_starts_with($resolved, $prefix)
            )) {
                $io->error("The 'console.plugins.$key' path must resolve inside the project root.");
                return null;
            }
            return $resolved;
        }

        return $absolute;
    }

    private static function projectRoot(): string
    {
        $root = Directory::path('root');
        $resolved = $root !== null ? realpath($root) : false;

        return $resolved ?: (realpath(Directory::path('app') . '/../') ?: getcwd());
    }
}
