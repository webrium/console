<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\Table;


use Foxdb\DB;
use Webrium\Directory;

class TableAction extends Command
{

    protected static $defaultName = 'table';


    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('table', InputArgument::REQUIRED);
        $this->addArgument('action', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $table = $input->getArgument('table');

        if ($action == 'info' || $action == 'columns') {
            $this->showAllTableColumns($output, $table);
        }

        return Command::SUCCESS;
    }

    private function showAllTableColumns($output, $name)
    {
        $res = DB::query("DESCRIBE $name;", [], true);
        $res = json_decode(json_encode($res), true);

        $table = new Table($output);
        $table->setHeaders(['Name', 'Type', 'Null', 'Attributes','Default','Extra']);
        $table->setRows($res);
        $table->render();
    }

}