<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

class PluginNew extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:new';
    protected static $defaultDescription = 'Create a new plugin definition file for export';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Plugin name (e.g. admin-panel)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite if definition already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $name  = $input->getArgument('name');
        $force = $input->getOption('force');

        if (!preg_match('/^[a-z0-9\-_]+$/', $name)) {
            $io->error("Invalid plugin name '$name'. Use lowercase letters, numbers, hyphens, and underscores only.");
            return Command::FAILURE;
        }

        $paths = PluginPathConfig::resolve($io);
        if ($paths === null) {
            return Command::FAILURE;
        }

        $defDir  = $paths['definitions'];
        $defPath = $defDir . '/' . $name . '.json';

        if (!is_dir($defDir) && !@mkdir($defDir, 0755, true) && !is_dir($defDir)) {
            $io->error("Unable to create plugin definitions directory: $defDir");
            return Command::FAILURE;
        }

        if (file_exists($defPath) && !$force) {
            $io->error("Definition '$name.json' already exists. Use --force to overwrite.");
            return Command::FAILURE;
        }

        $template = [
            'name'        => $name,
            'version'     => '1.0.0',
            'description' => '',
            'author'      => '',
            'require'     => ['webrium' => '>=1.0.0'],
            'export'      => [
                [
                    'file'      => 'app/Controllers/ExampleController.php',
                    'dest'      => 'controllers',
                    'subpath'   => null,
                    'overwrite' => false,
                ],
            ],
            'sql'   => [],
            'hooks' => [
                'before_install' => [],
                'after_install'  => [],
            ],
            'meta' => [],
        ];

        if (file_put_contents($defPath, json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            $io->error("Unable to write plugin definition: $defPath");
            return Command::FAILURE;
        }

        $io->title("Plugin Definition Created");
        $io->writeln("<fg=green>✔ Definition file:</> $defPath");
        $io->newLine();
        $io->writeln('Edit the file and add your files to the <fg=cyan>export</> array.');
        $exportCommand = 'php webrium plugin:export ' . $name . ' <version>';
        $io->writeln('Then run: <fg=cyan>' . $exportCommand . '</>');

        return Command::SUCCESS;
    }
}
