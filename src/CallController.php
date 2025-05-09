<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\App;
use Webrium\Directory;

class CallController extends Command
{
    protected static $defaultName = 'call';

    private const CONTROLLER_DIR = 'controllers';
    private const MODEL_DIR = 'models';
    private const DEFAULT_CONTROLLER_NAMESPACE = 'App\Controllers';
    private const DEFAULT_MODEL_NAMESPACE = 'App\Models';

    /**
     * Configures the command, defining the controller/model method call.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->setDescription('Call a method on a controller or model (format: Controller@method)')
            ->addArgument('name', InputArgument::REQUIRED, 'Controller@method or Model@method name')
            ->addOption('params', 'p', InputOption::VALUE_OPTIONAL, 'JSON array of parameters', '[]')
            ->addOption('model', 'm', InputOption::VALUE_NONE, 'Target a model instead of a controller')
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Custom namespace for the class');
    }

    /**
     * Executes the command to call a method on a controller or model.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $params = $input->getOption('params');
        $is_model = $input->getOption('model');
        $namespace = $input->getOption('namespace');

        // Validate name format
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*@[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid format for '$name'. Use 'Controller@method' or 'Model@method' format.");
            return Command::FAILURE;
        }

        // Parse controller/model and method
        [$controller, $method] = explode('@', $name, 2);

        // Validate controller/model name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $controller)) {
            $io->error("Invalid " . ($is_model ? 'model' : 'controller') . " name '$controller'.");
            return Command::FAILURE;
        }

        // Validate method name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $method)) {
            $io->error("Invalid method name '$method'.");
            return Command::FAILURE;
        }

        // Parse JSON parameters
        $params = json_decode($params, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->error("Invalid JSON in --params: " . json_last_error_msg());
            return Command::FAILURE;
        }
        if (!is_array($params)) {
            $io->error("Parameters must be a JSON array.");
            return Command::FAILURE;
        }

        // Determine namespace and directory
        $default_namespace = $is_model ? self::DEFAULT_MODEL_NAMESPACE : self::DEFAULT_CONTROLLER_NAMESPACE;
        $namespace = $namespace ?? $default_namespace;
        $dir = $is_model ? self::MODEL_DIR : self::CONTROLLER_DIR;
        $class = "$namespace\\$controller";

        // Check if class exists
        if (!class_exists($class)) {
            $io->error(($is_model ? 'Model' : 'Controller') . " class '$class' does not exist.");
            return Command::FAILURE;
        }

        // Instantiate class
        try {
            $instance = new $class();
        } catch (\Exception $e) {
            $io->error("Failed to instantiate '$class': " . $e->getMessage());
            return Command::FAILURE;
        }

        // Check if method exists
        if (!method_exists($instance, $method)) {
            $io->error("Method '$method' not found in class '$class'.");
            return Command::FAILURE;
        }

        // Call method
        $io->title("Calling Method: $class::$method");
        $io->writeln("<fg=cyan>Parameters: " . json_encode($params) . "</>");
        try {
            $data = $instance->$method(...$params);
        } catch (\Exception $e) {
            $io->error("Failed to execute '$method': " . $e->getMessage());
            return Command::FAILURE;
        }

        // Format output
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, JSON_PRETTY_PRINT);
        }

        $io->success("Method executed successfully.");
        $io->writeln("<fg=green>$data</>");

        return Command::SUCCESS;
    }
}