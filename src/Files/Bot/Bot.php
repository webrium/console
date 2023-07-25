<?php

use botfire\botfire\Route;
use botfire\botfire\bot;

Route::text('/start','BotWelcomeController->sendHello');
Route::text('test','BotWelcomeController->sendHello');

Route::notFound('BotWelcomeController->notfound');

bot::id(env('bot_debug_chat_id'))->message('end bot.php')->send();
