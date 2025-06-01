<?php
namespace App\Controllers;
use Webrium\File;
use Webrium\Event;
use Botfire\Bot;

class BotController
{

  function __construct(){
    bot::setToken(env('bot_token'));
  }

  public function setWebhook(){
    $url = url('bot/run');
    return Bot::setWebhook($url);
  }

  public function getWebhook(){
    return bot::getWebhook();
  }


  public function runCommand()
  {

    if(env('bot_debug')=='true'){
      Event::on('error', function($args){
        Bot::new()->text("Error:\n Message: ".($args['message']??'')."\n"."File: ".($args['file']??'').' Line:'.($args['line']??''))->send(env('bot_debug_chat_id'));
      });
    }

    File::source('routes', ['Bot.php']);
  }

}
