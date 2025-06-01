<?php
namespace App\Controllers;
use Botfire\Bot;

class BotWelcomeController
{

  public function sendHello(){
    Bot::new()->text('Hello World'))->send();
  }

  public function notFound(){
    Bot::new()->text('The command was not recognized')->send();
  }
}
