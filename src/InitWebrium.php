<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Webrium\File;
use Webrium\App;
use Webrium\Directory;

class InitWebrium extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'init';
    protected static $defaultDescription = 'Perform initial configuration and create project structure (directories)';

    protected function configure()
    {        
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        Directory::makes();
        return Command::SUCCESS;
    }

   
}