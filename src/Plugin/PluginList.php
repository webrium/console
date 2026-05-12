<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Webrium\Directory;

class PluginList extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:list';
    protected static $defaultDescription = 'List all installed plugins';

    protected function configure()
    {
        Directory::initDefaultStructure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io       = new SymfonyStyle($input, $output);
        $registry = $this->readRegistry();

        if (empty($registry['installed'])) {
            $io->info('No plugins installed yet.');
            return Command::SUCCESS;
        }

        $io->title('Installed Plugins');

        $table = new Table($output);
        $table->setHeaders(['Name', 'Version', 'Author', 'Files', 'Installed At', 'Updated At']);

        foreach ($registry['installed'] as $plugin) {
            $table->addRow([
                $plugin['name'],
                $plugin['version'],
                $plugin['author']       ?? '-',
                count($plugin['files'] ?? []),
                $plugin['installed_at'] ?? '-',
                $plugin['updated_at']   ?? '-',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }
}
