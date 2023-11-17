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
        $this->addArgument('name', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');

        if($action == 'tables'){
           return $this->showAllTables($output);
        }
        else if($action == 'list'){
            return $this->showAllDb($output);
        }
        else if($action == 'create'){
            return $this->createNewTable($input, $output);
        }

    }


    private function showAllDb($output){
        $res = DB::query('SHOW DATABASES;', [], true);
        $res = json_decode(json_encode($res), true);
        $table = new Table($output);
        $table->setHeaders(['Databases name']);
        $table->setRows($res);
        $table->render();

        return Command::SUCCESS;
    }

    private function showAllTables($output){
        $res = DB::query('SHOW TABLES;', [], true);
        $res = json_decode(json_encode($res), true);

        $table = new Table($output);
        $table->setHeaders(['Table name']);
        $table->setRows($res);
        $table->render();

        return Command::SUCCESS;
    }

    private function createNewTable($input,$output){
        $name = $input->getArgument('name');
        DB::query("CREATE DATABASE IF NOT EXISTS `$name`");

        return Command::SUCCESS;
    }

}