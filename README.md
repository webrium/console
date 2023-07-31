# component

##Install

```
$ composer require webrium/component
$ php -r "copy('vendor/webrium/component/src/webrium','webrium');"
```

## Init Telegram bot

```
php webrium botfire:init your_bot_api_token
```

You can also enable debug mode as below

```
php webrium botfire:init your_bot_api_token --debug=your_chat_id
```
By activating debug mode, when an error occurs, its text will be sent to your account instantly
