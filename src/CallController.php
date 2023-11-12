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
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $params = $input->getOption('params')??"'[]'";


        $params = str_replace("'", '', $params);
        $params = json_decode($params, true);

        $array = explode('@', $name);

        $controller = $array[0];
        $method = $array[1];

        $dir = Directory::get('controllers');

        $class = "$dir\\$controller";

        $class = str_replace('/', '\\', $class);

        $controller = new $class;


        if (method_exists($controller, '__init')) {
            $controller->__init();
        }


        if (method_exists($controller, $method)) {
            App::ReturnData($controller->{$method}(...$params));
        } else {
            Command::FAILURE;
            $output->writeln("<error>Method $method not found in $class.php</error>");
        }


        if (method_exists($controller, '__end')) {
            $controller->__end();
        }

        echo "\n";

        return Command::SUCCESS;
    }

}