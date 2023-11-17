# Webrium Console

> The Console feature still needs more features to be added.

<br>

webrium console provides a series of features to the developer and tries to make some tasks easier. For example, it can be used to create controller files or models, or view the list of databases or tables, etc.

<br>

## Model operation

### Create a model

The following example creates a blank model
```
php webrium make:model User
```

By using `--table` according to the name of the model (for example, `User`), the name of the `users` table is automatically created and set in the model.

```
php webrium make:model User --table
```

You can also specify the desired table name

```
php webrium make:model User --table=my_custom_table_name
```

## Controller operation


### Create a model:
In the example below, the `AuthController.php` file is created

```
php webrium make:controller Auth
```

## Database operation

### Show Databases list

The following command shows the list of all databases

```
php webrium db list
```

### Show Tables list

It shows the list of current database tables. 

```
php webrium db tables
```
By using --use, you can view the list of other databases. But you must have already defined the databases in DB.php
```
php webrium db tables --use=second_db
```


## Table operation

### Show columns and column information
```
php webrium table users info
```
In the example above, `users` is the name of the table we want to view its information.


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

