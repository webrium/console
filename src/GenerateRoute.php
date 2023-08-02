<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Webrium\File;
use Webrium\Directory;

class GenerateRoute extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'make:route';


    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Route Name');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');

        $model_dir = Directory::path('routes');
        $root = __DIR__.'/Files/Framework';

        $route_string = '';


        $class_name = $name;
        $file_name = "$name.php";


        $route_string = File::getContent("$root/Route.php");

        $file_path = "$model_dir/$file_name";
        if(File::exists($file_path)){
            $output->writeln("<error>ERROR: The '$file_name' route already exists</error>");
            return Command::FAILURE;
        }

        $output->writeln("Route Name: <comment>$class_name</comment>");
        


        $output->writeln("<info>The '$file_name' route was created.</info>");



        File::putContent($file_path, $route_string);

        return Command::SUCCESS;
    }
   
}