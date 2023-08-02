<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Webrium\File;
use Webrium\Directory;

class GenerateModel extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'make:model';

    public static function test(){
        echo 'hi';
    }

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Model Name');
        $this->addOption('table', null, InputOption::VALUE_OPTIONAL, 'Model table name', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $table = $input->getOption('table');

        $model_dir = Directory::path('models');
        $root = __DIR__.'/Files/Framework';

        $model_string = '';

        $class_name = '';
        $file_name = '';


        $class_name = $name;
        $file_name = "$name.php";

        // die(json_encode($table));

        if($table === null || (empty($table) == false && $table !== false)){

            $model_string = File::getContent("$root/DbModel.php");
            $model_string = \str_replace('DbModel', $class_name, $model_string);

            if($table == null){
                $table = strtolower($name);
                
                if(substr($table, strlen($table)-1, 1)!='s'){
                    $table.='s';
                }
            }

            $model_string = \str_replace('model_db', $table, $model_string);

        }
        else{

            $model_string = File::getContent("$root/SimpleModel.php");
            $model_string = \str_replace('SimpleModel', $class_name, $model_string);
        }


        $file_path = "$model_dir/$file_name";
        if(File::exists($file_path)){
            $output->writeln("<error>ERROR: The '$file_name' model already exists</error>");
            return Command::FAILURE;
        }

        $output->writeln("Model Name: <comment>$class_name</comment>");
        
        if(empty($table)==false){
            $output->writeln("Model Table: <comment>$table</comment>");
        }


        $output->writeln("<info>The '$file_name' model was created.</info>");



        File::putContent($file_path, $model_string);

        return Command::SUCCESS;
    }
   
}