<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use Webrium\File;
use Webrium\Directory;

class GenerateController extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'make:controller';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Who do you want to greet?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');

        if(strpos($name, "Controller")===false){
            $name = $name."Controller";
        }

        $model_dir = Directory::path('controllers');

        if(!is_dir($model_dir)){
            $output->writeln("<error>Controllers directory not exists\n$model_dir</error>");
            return Command::FAILURE;
        }
        
        $s = $output->section();

        $s->writeln('Creating a Controller file ..');

        File::putContent("$model_dir/$name.php", $this->getBasicStr($name));

        sleep(2);

        $s->overwrite("Controller created");
        $s->writeln("path : <info>$model_dir/$name.php</info>");

        return Command::SUCCESS;
    }

    private function getBasicStr($name){
        return "<?php\nnamespace App\Controllers;\n\nclass $name{\n//..\n\n}";
    }

   
}