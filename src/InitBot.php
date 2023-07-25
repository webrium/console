<?php
namespace webrium\component;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use Webrium\File;
use Webrium\Directory;

class InitBot extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'botfire:init';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('token', InputArgument::OPTIONAL, 'Who do you want to greet?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        // $output->writeln("<error>Modles directory not exists\n$model_dir</error>");

        
        // $s = $output->section();

        // $s->writeln('Creating a model file ..');

        // File::putContent("$model_dir/$name.php", $this->getBasicStr($name));

        // sleep(2);

        // $s->overwrite("Model created");
        // $s->writeln("path : <info>$model_dir/$name.php</info>");

        $app_routes_dir = Directory::path('routes');
        $app_controllers_dir = Directory::path('controllers');

        $dir_root = __DIR__;
        $dir_bot = "$dir_root/Files/Bot";

        copy("$dir_bot/Bot.php", "$app_routes_dir/Bot.php" );
        copy("$dir_bot/BotController.php", "$app_controllers_dir/BotController.php" );
        copy("$dir_bot/BotWelcomeController.php", "$app_controllers_dir/BotWelcomeController.php" );

        return Command::SUCCESS;
    }

   
}