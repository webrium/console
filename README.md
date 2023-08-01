# Webrium Console


### Model making:
```
php webrium make:model User --table
// or
php webrium make:model User --table=my-custom-table-name
```

Making the controller:

```
php webrium make:controller Auth
```

## Init Telegram bot
There is also a feature for developers who want to develop Telegram bots
We use [BotFire](github.com/botfire/botfire/) library for Telegram bot. And using the following commands, the initial configuration is created to implement the robot

```
php webrium botfire:init your_bot_api_token
```
Replace your_bot_api_token with your bot token.

You can also enable debug mode as below

```
php webrium botfire:init your_bot_api_token --debug=your_chat_id
```
By activating debug mode, when an error occurs, its text will be sent to your account instantly

