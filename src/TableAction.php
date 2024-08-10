<?php
namespace Webrium\Console;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ConfirmationQuestion;


use Foxdb\DB;
use Foxdb\Schema;
use Webrium\Directory;

class TableAction extends Command
{

    protected static $defaultName = 'table';


    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('action', InputArgument::REQUIRED, 'info:To display table columns, drop:To delete the table');
        $this->addArgument('table_name', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $table = $input->getArgument('table_name');

        if ($action == 'info' || $action == 'columns') {
            return $this->showAllTableColumns($output, $table);
        } else if ($action == 'drop') {
            return $this->dropTable($input, $output);
        } else {
            $output->writeln('<error>The entered command is not correct!</error>');
            $output->writeln('   To view the help for the "table" command, please execute the following command in your terminal');
            $output->writeln('   <info>#</info> php webrium table -h');
        }

        return Command::INVALID;
    }

    private function showAllTableColumns($output, $name)
    {
        $res = DB::query("DESCRIBE $name;", [], true);
        $res = json_decode(json_encode($res), true);

        $table = new Table($output);
        $table->setHeaders(['Name', 'Type', 'Null', 'Attributes', 'Default', 'Extra']);
        $table->setRows($res);
        $table->render();
        return Command::SUCCESS;
    }

    public function dropTable($input, $output)
    {
        $name = $input->getArgument('table_name');

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>Are you sure you want to delete the "<error>' . $name . '</error>" tables?(Type yes to continue):</question>', false);
        if (!$helper->ask($input, $output, $question)) {
            return Command::SUCCESS;
        } else {
            (new Schema($name))->drop();
            $output->writeLn("<info>$name was deleted<info>");
            return Command::SUCCESS;
        }
    }

}
