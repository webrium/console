<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\Table;


use Webrium\Directory;
use Webrium\File;

class LogAction extends Command
{

    protected static $defaultName = 'log';


    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('action', InputArgument::REQUIRED);
        $this->addArgument('name', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');

        if($action == 'list'){
            return $this->showLogList($output);
        }
        else if($action == 'clear'){
            return $this->clearLogs();
        }
        else if($action == 'latest'){
            return $this->showLatestLog($input, $output);
        }
        else if($action == 'file'){
            return $this->showLogFile($input, $output);
        }


        return Command::INVALID;
    }

    private function showLogList($output){

        $list = json_decode(json_encode(File::getFiles(Directory::path('logs'))), true) ;
        
        $logs = [];

        foreach($list as $log){
            $logs[] = [$log];
        }
        $table = new Table($output);
        $table->setHeaders(['Log name']);
        $table->setRows($logs);
        $table->render();

        return Command::SUCCESS;
    }


    public function clearLogs(){
        $files = array_diff(scandir(Directory::path('logs'), SCANDIR_SORT_DESCENDING), ['.', '..', '.gitignore']);
        foreach($files as $log){
            File::delete(Directory::path('logs').'/'.$log);
        }

        return Command::SUCCESS;
    }

    public function showLatestLog($input, $output){
        $files = array_diff(scandir(Directory::path('logs'), SCANDIR_SORT_DESCENDING), ['.', '..', '.gitignore']);

        if(count($files)>=1){
            $this->showLog($files[0], $input, $output);
        }
        else{
            $output->writeln('<info>Log file not found</info>');
        }
        return Command::SUCCESS;
    }

    private function showLogFile($input, $output){
        $name = $input->getArgument('name');
        return $this->showLog($name, $input, $output);
    }


    private function showLog($file_path, $input, $output){
        $path = Directory::path('logs').'/'.$file_path;
        if(File::exists($path)){
            $text = File::getContent($path);
            $array = explode('##', $text);
    
            foreach($array as $log){
                $log = str_replace("#", "\n  #", $log);
                $output->writeln("<error> ## </error>$log");
                $output->writeln('');
            }
    
            return Command::SUCCESS;
        }
        else{
            $output->writeLn("<error>File not found</error>");
            return Command::INVALID;
        }
    }



}
