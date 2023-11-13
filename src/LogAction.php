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
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');

        if($action == 'list'){
            $this->showLogList($output);
        }
        else if($action == 'clear'){
            $this->clearLogs();
        }
        else if($action == 'latest'){
            $this->showLatestLog($output);

        }


        return Command::SUCCESS;
    }

    private function showLogList($output){

        $list = json_decode(json_encode(File::getFiles(Directory::path('logs'))), true) ;
        $table = new Table($output);
        $table->setHeaders(['Log name']);
        $table->setRows([$list]);
        $table->render();
    }


    public function clearLogs(){
        $files = array_diff(scandir(Directory::path('logs'), SCANDIR_SORT_DESCENDING), ['.', '..', '.gitignore']);
        foreach($files as $log){
            File::delete(Directory::path('logs').'/'.$log);
        }
    }

    public function showLatestLog($output){
        $files = array_diff(scandir(Directory::path('logs'), SCANDIR_SORT_DESCENDING), ['.', '..', '.gitignore']);

        if(count($files)>=1){
            $text = File::getContent(Directory::path('logs').'/'.$files[0]);
            $array = explode('##', $text);

            foreach($array as $log){
                $output->writeln("<error> ## </error>$log");
                $output->writeln('');
            }
        }
        else{
            $output->writeln('<info>Log file not found</info>');
        }
    }



}