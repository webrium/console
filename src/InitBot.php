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

class InitBot extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'botfire:init';
    protected static $defaultDescription = 'Creates files and initial values to start Telegram bot';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('token', InputArgument::OPTIONAL, 'Your Telegram bot token');
            $this->addOption('debug',null,InputOption::VALUE_REQUIRED, 'It activates the debug mode. Also, you must enter the ID of the chat account to which you want to send error messages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        $app_routes_dir = Directory::path('routes');
        $app_controllers_dir = Directory::path('controllers');

        $dir_root = __DIR__;
        $dir_bot = "$dir_root/Files/Bot";

        copy("$dir_bot/Bot.php", "$app_routes_dir/Bot.php" );
        copy("$dir_bot/BotController.php", "$app_controllers_dir/BotController.php" );
        copy("$dir_bot/BotWelcomeController.php", "$app_controllers_dir/BotWelcomeController.php" );

        $output->writeln('');
        $output->writeln("'Bot.php' file was added to Routes Directory");
        $output->writeln("'BotController.php' file was added to Controllers Directory");
        $output->writeln("'BotWelcomeController.php' file was added to Controllers Directory");
        $output->writeln('');

        
        if(empty($token)==false){

            $env = File::getContent(App::rootPath('.env'));
            $env_array = explode("\n", $env);
    
            $find_bot_token = false;
            $find_bot_debug = false;
            $find_bot_debug_id = false;

            foreach ($env_array as $key => &$line) {
                if(strpos($line, 'bot_token')!==false){
                    $find_bot_token = true;
                }

                if(strpos($line, 'bot_debug') !== false ){
                    $find_bot_debug = true;
                }

                if(strpos($line, 'bot_debug_chat_id') !== false ){
                    $find_bot_debug_id = true;
                }
            }

            if($find_bot_token == false){
                $env_array[] = "bot_token = $token";
                $output->writeln("Added bot token in '.env' file.");
            }
            else{
                $output->writeln("The bot_token value already exists in the .env file.");
            }

            if($find_bot_debug == false){
                if(empty($input->getOption('debug'))==false){
                    $env_array[] = "bot_debug = true";
                    $output->writeln("Debug mode of the bot is activated.");


                    if($find_bot_debug_id == false){
                        $env_array[] = "bot_debug_chat_id = ".$input->getOption('debug');
                        $find_bot_debug_id = true;
                        $output->writeln("Added debugger chat id.");
                    }
                }
                else{
                    $env_array[] = "bot_debug = false";
                }
                
            }

            if($find_bot_debug_id == false){
                $env_array[] = "bot_debug_chat_id = ";
            }

 

            $env_str = implode("\n", $env_array);
            File::putContent(App::rootPath('.env'), $env_str);

        }

        if(File::exists("$app_routes_dir/Web.php")){

            $web_route = File::getContent("$app_routes_dir/Web.php");
            $web_array = explode("\n", $web_route);

            $find_web_route = false;
            foreach ($web_array as $key => &$line) {
                if(strpos($line, '// Added by botfire')!==false){
                    $find_web_route = true;
                }
            }

            if($find_web_route == false){
                $web_route = $web_route . "\n\n// Added by botfire\n\nRoute::post('bot/run', 'BotController->runCommand');\nRoute::get('bot/webhook/set', 'BotController->setWebhook');\n//Route::get('bot/webhook/get', 'BotController->getWebhook');";
                File::putContent("$app_routes_dir/Web.php", $web_route);
                $output->writeln("Added required routes to 'Web.php'");
            }
        }

        $output->writeln('');
        $output->writeln("Bot initialization completed.");
        return Command::SUCCESS;
    }

   
}