<?php
namespace webrium\component;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use webrium\core\File;
use webrium\core\Directory;
use webrium\component\Download;

class DownloadUIBootstrap extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'ui:bootstrap';

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
        elseif($action=='init-view'){
            return $this->initView($arg,$output);
        }


        
    }

    private function initView($name,$output){
        $html = '<!doctype html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="@url(\'library/bootstrap/css/bootstrap.min.css\')">

    <title>Hello, world!</title>
  </head>
  <body>
    <h1>Hello, world!</h1>

    <!-- Optional JavaScript -->
    <!-- jQuery first, then Popper.js, then Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
    <script src="@url(\'library/bootstrap/js/bootstrap.min.js\')"></script>
  </body>
</html>';

        $s = $output->section();

        $view_path = Directory::path('views');

        $s->writeln('Creating a view file ..');

        File::putContent("$view_path/$name.php",$html);

        sleep(2);

        $s->overwrite("View created");
        $s->writeln("path : <info>$view_path/$name.php</info>");
        return Command::SUCCESS;
    }

    private function install($v=false,$output){

        $url= 'https://github.com/twbs/bootstrap/releases/download/v4.5.2/bootstrap-4.5.2-dist.zip';
        $file_name = basename($url);

        $save_path = Directory::path('storage').'/temp';
        Directory::make($save_path);

        $output->writeln("Downloading $file_name\n");

        $status = Download::url($url,$save_path);

        // if($status != 200){

        // }

        $extract_to = Directory::path('public')."/library/";
        
        $output->writeln("Extract $file_name to $extract_to");

        $zip = new \ZipArchive;
        if ($zip->open("$save_path/$file_name") === TRUE) {
            $zip->extractTo($extract_to);
            $zip->close();
            rename("$extract_to/bootstrap-4.5.2-dist","$extract_to/bootstrap");
        } else {
        }

        File::delete("$save_path/$file_name");
        $output->writeln("Clear temp files");
        $output->writeln("----------------\n");

        
        $output->writeln("<info>Installation completed</info>");

        return Command::SUCCESS;

    }

   
}