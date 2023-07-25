<?php
namespace App\Controllers;
use Webrium\File;
use Webrium\Event;
use botfire\botfire\bot;

class BotController
{

  function __construct(){
    bot::token(env('bot_token'));
    bot::autoInput();
  }

  public function setWebhook(){
    $url = url('bot/run');
    return bot::webhook()->url($url)->set();
  }

  public function getWebhook(){
    return bot::webhook()->getInfo();
  }


  public function runCommand()
  {

    if(env('bot_debug')=='true'){
      Event::on('error', function($args){
        bot::id(env('bot_debug_chat_id'))->message("Error:\n Message: ".($args['message']??'')."\n"."File: ".($args['file']??'').' Line:'.($args['line']??''));
      });
    }

    File::source('routes', ['Bot.php']);
  }

}
