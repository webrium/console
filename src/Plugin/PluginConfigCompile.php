<?php

declare(strict_types=1);

namespace Webrium\Console\Plugin;

use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

final class PluginConfigCompile extends Command
{
    protected static $defaultName = 'plugin:config:compile';
    protected static $defaultDescription = 'Compile the plugin registry with project overrides';

    private const PACKAGE_OWNED_FIELDS = [
        'name',
        'version',
        'description',
        'author',
        'installed_at',
        'updated_at',
        'hash',
        'files',
    ];

    protected function configure(): void
    {
        Directory::initDefaultStructure();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and preview without writing the compiled file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paths = PluginPathConfig::resolve($io);
        if ($paths === null) {
            return Command::FAILURE;
        }

        $registry = $this->readJsonObject($paths['registry'], 'Plugin registry', $io, false);
        if ($registry === null || !$this->validateRegistry($registry, $io)) {
            return Command::FAILURE;
        }

        $overrides = $this->readJsonObject($paths['overrides'], 'Plugin overrides', $io, true);
        if ($overrides === null) {
            return Command::FAILURE;
        }

        $compiled = $this->compile($registry, $overrides, $io);
        if ($compiled === null) {
            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $io->definitionList(
                ['Registry' => $paths['registry']],
                ['Overrides' => is_file($paths['overrides']) ? $paths['overrides'] : '(not present)'],
                ['Compiled output' => $paths['compiled']],
            );
            $io->success('Plugin configuration is valid. No files were written.');
            return Command::SUCCESS;
        }

        if (!$this->writeAtomically($paths['compiled'], $compiled, $io)) {
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Registry' => $paths['registry']],
            ['Overrides' => is_file($paths['overrides']) ? $paths['overrides'] : '(not present)'],
            ['Compiled output' => $paths['compiled']],
        );
        $io->success('Plugin configuration compiled successfully.');

        return Command::SUCCESS;
    }

    /** @return \stdClass|null */
    private function readJsonObject(
        string $path,
        string $label,
        SymfonyStyle $io,
        bool $optional
    ): ?\stdClass {
        if (!is_file($path)) {
            if ($optional) {
                return new \stdClass();
            }
            $io->error("$label file not found: $path");
            return null;
        }

        try {
            $data = json_decode(
                (string) file_get_contents($path),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            $io->error("$label is not valid JSON: " . $e->getMessage());
            return null;
        }

        if (!$data instanceof \stdClass) {
            $io->error("$label must contain a JSON object.");
            return null;
        }

        return $data;
    }

    private function validateRegistry(\stdClass $registry, SymfonyStyle $io): bool
    {
        if (!property_exists($registry, 'installed') || !is_array($registry->installed)) {
            $io->error("Plugin registry must contain an 'installed' array.");
            return false;
        }

        $names = [];
        foreach ($registry->installed as $index => $plugin) {
            if (!$plugin instanceof \stdClass) {
                $io->error("Plugin registry entry #$index must be an object.");
                return false;
            }
            if (!isset($plugin->name) || !is_string($plugin->name) || trim($plugin->name) === '') {
                $io->error("Plugin registry entry #$index must have a non-empty name.");
                return false;
            }
            if (isset($names[$plugin->name])) {
                $io->error("Plugin registry contains duplicate name '{$plugin->name}'.");
                return false;
            }
            $names[$plugin->name] = true;
        }

        return true;
    }

    /**
     * @return \stdClass|null
     */
    private function compile(\stdClass $registry, \stdClass $overrides, SymfonyStyle $io): ?\stdClass
    {
        $unknownRootKeys = array_diff(array_keys(get_object_vars($overrides)), ['plugins']);
        if ($unknownRootKeys !== []) {
            $io->error('Unknown plugin overrides root key(s): ' . implode(', ', $unknownRootKeys));
            return null;
        }

        $pluginOverrides = $overrides->plugins ?? new \stdClass();
        if (!$pluginOverrides instanceof \stdClass) {
            $io->error("The plugin overrides 'plugins' key must be an object keyed by plugin name.");
            return null;
        }

        $indexes = [];
        foreach ($registry->installed as $index => $plugin) {
            $indexes[$plugin->name] = $index;
        }

        foreach (get_object_vars($pluginOverrides) as $name => $override) {
            if (!is_string($name) || $name === '' || !isset($indexes[$name])) {
                $io->error("Override references unknown plugin '$name'.");
                return null;
            }
            if (!$override instanceof \stdClass) {
                $io->error("Override for plugin '$name' must be an object.");
                return null;
            }

            $forbidden = array_intersect(array_keys(get_object_vars($override)), self::PACKAGE_OWNED_FIELDS);
            if ($forbidden !== []) {
                $io->error("Override for plugin '$name' cannot replace package-owned field(s): " . implode(', ', $forbidden));
                return null;
            }

            $index = $indexes[$name];
            $registry->installed[$index] = $this->mergeValues($registry->installed[$index], $override);
        }

        return $registry;
    }

    /**
     * JSON objects merge recursively. Every other JSON value, including a
     * numeric array, replaces the base value in full.
     *
     * @return mixed
     */
    private function mergeValues($base, $override)
    {
        if (!$base instanceof \stdClass || !$override instanceof \stdClass) {
            return $override;
        }

        $merged = clone $base;
        foreach (get_object_vars($override) as $key => $value) {
            $merged->{$key} = property_exists($merged, $key)
                ? $this->mergeValues($merged->{$key}, $value)
                : $value;
        }

        return $merged;
    }

    private function writeAtomically(string $path, \stdClass $compiled, SymfonyStyle $io): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $io->error("Unable to create compiled plugin directory: $directory");
            return false;
        }

        try {
            $json = json_encode(
                $compiled,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } catch (JsonException $e) {
            $io->error('Unable to encode compiled plugin configuration: ' . $e->getMessage());
            return false;
        }

        $temporaryPath = tempnam($directory, '.plugins-compile-');
        if ($temporaryPath === false) {
            $io->error("Unable to create a temporary file in: $directory");
            return false;
        }

        $written = file_put_contents($temporaryPath, $json, LOCK_EX);
        if ($written === false || !@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            $io->error("Unable to write compiled plugin configuration: $path");
            return false;
        }

        @chmod($path, 0644);
        return true;
    }
}
