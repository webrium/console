<?php
namespace webrium\component;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use webrium\core\File;
use webrium\core\Directory;
use webrium\component\Download;
use webrium\component\Zip;

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

        $url;
        $orginal_name;

        if ($v=='3' || $v==false || $v==null) {
          $url='https://webrium.ir/content/components/jquery-3.5.1.min.zip';
          $orginal_name = 'jquery-3.5.1.min.js';
        }
        elseif ($v=='2') {
          $url='https://webrium.ir/content/components/jquery-2.2.4.min.zip';
          $orginal_name = 'jquery-2.2.4.min.js';
        }
        elseif ($v=='1') {
          $url='https://webrium.ir/content/components/jquery-1.12.4.min.zip';
          $orginal_name = 'jquery-1.12.4.min.js';
        }
        elseif ($v=='slim') {
          $url="https://webrium.ir/content/components/jquery-3.5.1.slim.zip";
          $orginal_name = 'jquery-3.5.1.slim.js';
        }
        elseif ($v=='migrate') {
          $url='https://webrium.ir/content/components/jquery-migrate-3.3.1.min.zip';
          $orginal_name = 'jquery-migrate-3.3.1.min.js';
        }
        elseif ($v=='ui') {
          $url='https://webrium.ir/content/components/jquery-ui.min-1.12.zip';
          $orginal_name = 'jquery-ui.min.js';
        }
        else {
          $output->writeln("<error>install $v not found.</error>");
          die;
        }

        $file_name = basename($url);

        $save_path = Directory::path('storage').'/temp';
        Directory::make($save_path);

        $output->writeln("Downloading $file_name\n");

        $status = Download::url($url,$save_path);

        if($status != 200){
          Download::error($output);
        }

        $extract_to = Directory::path('public')."/library/jquery/";

        $output->writeln("Extract $file_name to $extract_to");

        Zip::extract("$save_path/$file_name",$extract_to);

        File::delete("$save_path/$file_name");
        $output->writeln("Clear temp files");
        $output->writeln("----------------\n");

        $output->writeln("=======================Guide=======================");
        $output->writeln("<comment>".'<script src="{{ url(\'library/jquery/'.$orginal_name.'\') }}" charset="utf-8"></script>'."</comment>");
        $output->writeln("===================================================");
        $output->writeln("\n");

        $output->writeln("<info>Installation completed</info>");

        return Command::SUCCESS;

    }


}
