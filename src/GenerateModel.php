<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\File;
use Webrium\Directory;

class GenerateModel extends Command
{
    protected static $defaultName = 'make:model';

    private const DB_MODEL_TEMPLATE = 'Files/Framework/DbModel.php';
    private const SIMPLE_MODEL_TEMPLATE = 'Files/Framework/SimpleModel.php';

    /**
     * Configures the command, defining the model name argument and optional table option.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Model Name');
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Model table name', false);
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite if the model already exists');
        $this->addOption('no-plural', null, InputOption::VALUE_NONE, 'Prevent adding "s" to the table name');
    }

    /**
     * Converts a camelCase or PascalCase string to snake_case.
     *
     * @param string $input The input string (e.g., UserPayment)
     * @return string The snake_case string (e.g., user_payment)
     */
    private function convertToSnakeCase(string $input): string
    {
        $result = '';
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if (ctype_upper($char)) {
                if ($i > 0) {
                    $result .= '_';
                }
                $result .= strtolower($char);
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Executes the command to generate a model file.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $table = $input->getOption('table');
        $force = $input->getOption('force');

        // Validate model name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid model name '$name'. Model names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        // Check directory
        $model_dir = Directory::path('models');
        if (!is_dir($model_dir)) {
            $io->error("The models directory '$model_dir' does not exist.");
            return Command::FAILURE;
        }
        if (!is_writable($model_dir)) {
            $io->error("The models directory '$model_dir' is not writable.");
            return Command::FAILURE;
        }

        // Determine template
        $use_db_model = $table !== false;
        $template_file = $use_db_model ? self::DB_MODEL_TEMPLATE : self::SIMPLE_MODEL_TEMPLATE;

        // Check template file existence
        $root = __DIR__;
        if (!File::exists("$root/$template_file")) {
            $io->error("Template file '$template_file' not found.");
            return Command::FAILURE;
        }

        // Generate model content
        $class_name = $name;
        $file_name = "$name.php";
        $file_path = "$model_dir/$file_name";

        // Check if file exists
        if (File::exists($file_path) && !$force) {
            $io->error("The model '$file_name' already exists at '$file_path'. Use --force to overwrite.");
            return Command::FAILURE;
        }

        // Read and modify template
        $model_string = File::getContent("$root/$template_file");
        $model_string = str_replace($use_db_model ? 'DbModel' : 'SimpleModel', $class_name, $model_string);

        if ($use_db_model) {
            if (empty($table)) {
                // Convert model name to snake_case and add 's' if needed
                $table = $this->convertToSnakeCase($name);
                
                // Add 's' to pluralize the table name only if it doesn't already end with 's'
                if (!$input->getOption('no-plural') && substr($table, -1) !== 's') {
                    $table .= 's';
                }
            }
            $model_string = str_replace('model_db', $table, $model_string);
        }

        // Write file
        File::putContent($file_path, $model_string);

        // Output results
        $io->title('Model Generation');
        $io->writeln("<fg=green>✔ Model '$class_name' created successfully at '$file_path'.</>");
        if ($use_db_model) {
            $io->writeln("<fg=cyan>↳ Table: $table</>");
        }

        return Command::SUCCESS;
    }
}