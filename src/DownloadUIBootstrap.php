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
    protected static $defaultName = 'ui:bootstrap-install';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Who do you want to greet?');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $v = $input->getArgument('version');


        $url= 'https://github.com/twbs/bootstrap/releases/download/v4.5.2/bootstrap-4.5.2-dist.zip';
        $file_name = basename($url);

        $s = $output->section();

        $s->writeln("Downloading ". $file_name);

        $status = Download::url($url,false,$save_path);

        if($status != 200){

        }

        $zip = new ZipArchive;
        if ($zip->open('test.zip') === TRUE) {
            $zip->extractTo('/my/destination/dir/');
            $zip->close();
            echo 'ok';
        } else {
            echo 'failed';
        }

        $zip = new ZipArchive;
        $res = $zip->open("$save_path/$file_name");

        if ($res === TRUE) {
            $zip->extractTo(Directory::path('public').'/library/');
            $zip->close();
            echo 'woot!';
        } else {
            echo 'doh!';
        }


       // $s->overwrite("Model created");

        return Command::SUCCESS;
    }

    private function getBasicStr($name){
        return "<?php\nnamespace app\models;\n\nclass $name{\n//..\n\n}";
    }

   
}