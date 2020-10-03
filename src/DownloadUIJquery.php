<?php
namespace webrium\component;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use webrium\core\File;
use webrium\core\Directory;
use webrium\component\Download;

class DownloadUIJquery extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'ui:jquery';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('action', InputArgument::REQUIRED, '');
        $this->addArgument('arg', InputArgument::OPTIONAL, '');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $arg      = $input->getArgument('arg');

        if($action=='install'){
            return $this->install($arg,$output);
        }




    }

    private function install($v=false,$output){

        $url= '';
        if ($v=='3' || $v==false || $v==null) {
          $url='https://webrium.ir/content/components/jquery-3.5.1.min.zip';
        }
        elseif ($v=='2') {
          $url='https://webrium.ir/content/components/jquery-2.2.4.min.zip';
        }
        elseif ($v=='1') {
          $url='https://webrium.ir/content/components/jquery-1.12.4.min.zip';
        }
        elseif ($v=='slim') {
          $url="https://webrium.ir/content/components/jquery-3.5.1.slim.zip";
        }
        elseif ($v=='migrate') {
          $url='https://webrium.ir/content/components/jquery-migrate-3.3.1.min.zip';
        }
        elseif ($v=='ui') {
          $url='https://webrium.ir/content/components/jquery-ui.min-1.12.zip';
        }

        $file_name = basename($url);

        $save_path = Directory::path('storage').'/temp';
        Directory::make($save_path);

        $output->writeln("Downloading $file_name\n");

        $status = Download::url($url,$save_path);

        // if($status != 200){

        // }

        $extract_to = Directory::path('public')."/library/jquery/";

        $output->writeln("Extract $file_name to $extract_to");

        $zip = new \ZipArchive;
        if ($zip->open("$save_path/$file_name") === TRUE) {
            $zip->extractTo($extract_to);
            $zip->close();
        } else {
        }

        File::delete("$save_path/$file_name");
        $output->writeln("Clear temp files");
        $output->writeln("----------------\n");


        $output->writeln("<info>Installation completed</info>");

        return Command::SUCCESS;

    }


}
