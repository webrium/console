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

class DownloadUIKit extends Command
{
  // the name of the command (the part after "bin/console")
  protected static $defaultName = 'ui:kit';

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
    $html = '<!DOCTYPE html>
    <html>
    <head>
    <title>Title</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="@url(\'library/uikit/css/uikit.min.css\')" />
    <script src="@url(\'library/uikit/js/uikit.min.js\')"></script>
    <script src="@url(\'library/uikit/js/uikit-icons.min.js\')"></script>
    </head>
    <body>
    <h1>Hello, world!</h1>


    </body>
    </html>

    ';

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

    $url= 'https://webrium.ir/content/components/uikit-3.5.8.zip';
    $file_name = basename($url);

    $save_path = Directory::path('storage').'/temp';
    Directory::make($save_path);

    $output->writeln("Downloading $file_name\n");

    $status = Download::url($url,$save_path);

    if($status != 200){
      Download::error($output);
    }

    $extract_to = Directory::path('public')."/library/uikit/";

    $output->writeln("Extract $file_name to $extract_to");

    Zip::extract("$save_path/$file_name",$extract_to);

    File::delete("$save_path/$file_name");
    $output->writeln("Clear temp files");
    $output->writeln("----------------\n");

    $output->writeln("<info>Installation completed</info>");

    return Command::SUCCESS;

  }


}
