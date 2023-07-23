<?php
namespace webrium\component;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use Webrium\File;
use Webrium\Directory;

class GenerateModel extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'make:model';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Who do you want to greet?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');

        $model_dir = Directory::path('models');

        if(!is_dir($model_dir)){
            $output->writeln("<error>Modles directory not exists\n$model_dir</error>");
            return Command::FAILURE;
        }
        
        $s = $output->section();

        $s->writeln('Creating a model file ..');

        File::putContent("$model_dir/$name.php", $this->getBasicStr($name));

        sleep(2);

        $s->overwrite("Model created");
        $s->writeln("path : <info>$model_dir/$name.php</info>");

        return Command::SUCCESS;
    }

    private function getBasicStr($name){
        return "<?php\nnamespace App\Models;\n\nclass $name{\n//..\n\n}";
    }

   
}