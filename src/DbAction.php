<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
        $this->addOption('use', 'u', InputOption::VALUE_OPTIONAL,'Use of custom database');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');

        if($action == 'tables'){
           return $this->showAllTables($input, $output);
        }
        else if($action == 'list'){
            return $this->showAllDb($output);
        }
        else if($action == 'create'){
            return $this->createNewTable($input, $output);
        }
        else if($action == 'drop'){
            return $this->dropDatabase($input, $output);
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

    private function showAllTables($input, $output){

        $use = $input->getOption('use');

        if($use){
            DB::useOnce($use);
        }

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
        $status = DB::query("CREATE DATABASE IF NOT EXISTS `$name`");
       
        if($status){
            $output->writeln("<info>The $name database was created</info>");
        }

        return Command::SUCCESS;
    }

    private function dropDatabase($input, $output){

        $name = $input->getArgument('name');

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>Are you sure you want to delete the "<error>'.$name.'</error>" database?(Type yes to continue):</question>', false);
        if (!$helper->ask($input, $output, $question)) {
            return Command::SUCCESS;
        }
        else{
            DB::query("DROP DATABASE IF EXISTS `$name`");
            $output->writeLn("<info>$name was deleted<info>");
        }



        return Command::SUCCESS;
    }

}