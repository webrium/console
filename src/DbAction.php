<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\Table;

use Foxdb\DB;
use Webrium\Directory;

class DbAction extends Command
{

    protected static $defaultName = 'db';


    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('action', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');

        if($action == 'tables'){
            $this->showAllTables($output);
        }

        return Command::SUCCESS;
    }

    private function showAllTables($output){
        $res = DB::query('show tables;', [], true);
        $res = json_decode(json_encode($res), true);

        $table = new Table($output);
        $table->setHeaders(['Table name']);
        $table->setRows($res);
        $table->render();
    }

}