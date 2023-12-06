<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Webrium\App;
use Webrium\Directory;

class CallController extends Command
{

    protected static $defaultName = 'call';


    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Controller->method Name');
        $this->addOption('params', 'p', InputArgument::OPTIONAL);
        $this->addOption('model', 'm', InputOption::VALUE_NEGATABLE);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $params = $input->getOption('params')??"'[]'";
        $model = $input->getOption('model');


        $params = str_replace("'", '', $params);
        $params = json_decode($params, true);

        $array = explode('@', $name);

        $controller = $array[0];
        $method = $array[1];

        if($model){
            $dir = Directory::get('models');
        }
        else{
            $dir = Directory::get('controllers');
        }

        $class = "$dir\\$controller";

        $class = str_replace('/', '\\', $class);

        $controller = new $class;


        if (method_exists($controller, $method)) {

            $data = $controller->{$method}(...$params);

            if (is_array($data) || is_object($data)) {
                $data = json_encode($data);
            }

            $output->writeln("<info>$data</info>");

        } else {
            Command::FAILURE;
            $output->writeln("<error>Method $method not found in $class.php</error>");
        }

        return Command::SUCCESS;
    }

}