<?php
namespace App\Controllers;
use botfire\botfire\bot;

class BotWelcomeController
{

  public function sendHello(){
    bot::this()->message('Hello '.(bot::chat()->first_name??'World'))->send();
  }

  public function notFound(){
    bot::this()->message('The command was not recognized')->send();
  }
}
