<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\File;
use Webrium\App;
use Webrium\Directory;

class InitBot extends Command
{
    protected static $defaultName = 'botfire:init';
    protected static $defaultDescription = 'Creates files and initial values to start a Telegram bot';

    private const BOT_SOURCE_DIR = 'Files/Bot';
    private const BOT_TOKEN_KEY = 'bot_token';
    private const BOT_DEBUG_KEY = 'bot_debug';
    private const BOT_DEBUG_CHAT_ID_KEY = 'bot_debug_chat_id';

    /**
     * Configures the command, defining the bot token and debug options.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('token', InputArgument::OPTIONAL, 'Your Telegram bot token')
            ->addOption('debug', null, InputOption::VALUE_REQUIRED, 'Chat ID for debug mode error messages')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite of existing files')
            ->addOption('routes-dir', null, InputOption::VALUE_OPTIONAL, 'Custom routes directory')
            ->addOption('controllers-dir', null, InputOption::VALUE_OPTIONAL, 'Custom controllers directory');
    }

    /**
     * Executes the command to initialize a Telegram bot.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = $input->getArgument('token');
        $debug_chat_id = $input->getOption('debug');
        $force = $input->getOption('force');
        $routes_dir = $input->getOption('routes-dir') ?? Directory::path('routes');
        $controllers_dir = $input->getOption('controllers-dir') ?? Directory::path('controllers');
        $dir_bot = __DIR__ . '/' . self::BOT_SOURCE_DIR;

        // Validate token
        if ($token && !preg_match('/^(bot)?[0-9]+:[a-zA-Z0-9_-]+$/', $token)) {
            $io->error("Invalid Telegram bot token format.");
            return Command::FAILURE;
        }

        // Validate debug chat ID
        if ($debug_chat_id && !is_numeric($debug_chat_id)) {
            $io->error("Debug chat ID must be a numeric value.");
            return Command::FAILURE;
        }

        // Check directories
        foreach ([$routes_dir, $controllers_dir] as $dir) {
            if (!is_dir($dir)) {
                $io->error("Directory '$dir' does not exist.");
                return Command::FAILURE;
            }
            if (!is_writable($dir)) {
                $io->error("Directory '$dir' is not writable.");
                return Command::FAILURE;
            }
        }

        // Copy bot files
        $io->section('Initializing Bot Files');
        $files_to_copy = [
            ['source' => 'Bot.php', 'dest' => "$routes_dir/Bot.php", 'type' => 'route'],
            ['source' => 'BotController.php', 'dest' => "$controllers_dir/BotController.php", 'type' => 'controller'],
            ['source' => 'BotWelcomeController.php', 'dest' => "$controllers_dir/BotWelcomeController.php", 'type' => 'controller'],
        ];

        foreach ($files_to_copy as $file) {
            $source = "$dir_bot/{$file['source']}";
            $dest = $file['dest'];
            if (!File::exists($source)) {
                $io->error("Source file '{$file['source']}' not found in '$dir_bot'.");
                return Command::FAILURE;
            }
            if (File::exists($dest) && !$force) {
                $io->error("File '$dest' already exists. Use --force to overwrite.");
                return Command::FAILURE;
            }
            if (!copy($source, $dest)) {
                $io->error("Failed to initialize '{$file['source']}' at '$dest'.");
                return Command::FAILURE;
            }
            if ($file['type'] === 'route') {
                $io->writeln("<fg=green>✔ Added route file '{$file['source']}' to '$dest'.</>");
            } else {
                $io->writeln("<fg=green>✔ Created controller '{$file['source']}' in '$dest'.</>");
            }
        }

        // Update .env file
        if ($token) {
            $io->section('Updating .env File');
            $env_file = App::rootPath('.env');
            if (!File::exists($env_file)) {
                File::putContent($env_file, '');
            }
            if (!is_writable($env_file)) {
                $io->error("The '.env' file at '$env_file' is not writable.");
                return Command::FAILURE;
            }

            $env = File::getContent($env_file);
            $env_lines = array_filter(explode("\n", $env), fn($line) => trim($line) !== '');
            $env_map = [];
            foreach ($env_lines as $line) {
                if (strpos($line, '=') !== false) {
                    [$key, $value] = array_map('trim', explode('=', $line, 2));
                    $env_map[$key] = $value;
                }
            }

            // Update bot_token
            if (!isset($env_map[self::BOT_TOKEN_KEY])) {
                $env_map[self::BOT_TOKEN_KEY] = $token;
                $io->writeln("<fg=green>✔ Added bot token to '.env' file.</>");
            } else {
                $io->note("The 'bot_token' value already exists in the '.env' file.");
            }

            // Update bot_debug
            if (!isset($env_map[self::BOT_DEBUG_KEY])) {
                $env_map[self::BOT_DEBUG_KEY] = $debug_chat_id ? 'true' : 'false';
                if ($debug_chat_id) {
                    $io->writeln("<fg=green>✔ Activated debug mode in '.env' file.</>");
                }
            }

            // Update bot_debug_chat_id
            if (!isset($env_map[self::BOT_DEBUG_CHAT_ID_KEY])) {
                $env_map[self::BOT_DEBUG_CHAT_ID_KEY] = $debug_chat_id ?: '';
                if ($debug_chat_id) {
                    $io->writeln("<fg=green>✔ Added debug chat ID to '.env' file.</>");
                }
            }

            // Write updated .env file
            $env_str = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($env_map), $env_map));
            File::putContent($env_file, $env_str);
        }

        // Update Web.php routes
        $web_file = "$routes_dir/Web.php";
        if (File::exists($web_file)) {
            $io->section('Updating Routes');
            $web_route = File::getContent($web_file);
            $routes_to_add = [
                "Route::post('bot/run', 'BotController->runCommand');",
                "Route::get('bot/webhook/set', 'BotController->setWebhook');",
                "//Route::get('bot/webhook/get', 'BotController->getWebhook');",
            ];
            $new_routes = '';

            foreach ($routes_to_add as $route) {
                if (!preg_match('/' . preg_quote($route, '/') . '/', $web_route)) {
                    $new_routes .= "\n$route";
                }
            }

            if ($new_routes) {
                $web_route .= "\n\n// Added by botfire$new_routes";
                File::putContent($web_file, $web_route);
                $io->writeln("<fg=green>✔ Added required routes to '$web_file'.</>");
            } else {
                $io->note("Required routes already exist in '$web_file'.");
            }
        }

        $io->newLine();
        $io->success("Bot initialization completed successfully.");
        return Command::SUCCESS;
    }
}