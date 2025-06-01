<?php

use Botfire\Route;
use Botfire\Bot;

Route::text('/start','BotWelcomeController->sendHello');
Route::text('test','BotWelcomeController->sendHello');

Route::notFound('BotWelcomeController->notfound');