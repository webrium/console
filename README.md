# Webrium Console

<br>

webrium console provides a series of features to the developer and tries to make some tasks easier. For example, it can be used to create controller files or models, or view the list of databases or tables, etc.

<br>

## Model operation

### Create a model

The following example creates a blank model
```
php webrium make:model User
```

By using `--table` or `-t` according to the name of the model (for example, `User`), the name of the `users` table is automatically created and set in the model.

```
php webrium make:model User --table
or
php webrium make:model User -t
```

You can also specify the desired table name

```
php webrium make:model User --table=my_custom_table_name
```

## Controller operation


### Create a controller:
In the example below, the `AuthController.php` file is created

```
php webrium make:controller Auth
```

### Call methods

You can run your controller or model methods through the console and see its output

Call Controller method

```
php webrium call IndexController@getCurrentName
```

Call Model method

```
php webrium call -m User@getCount

```

Parameters can be passed to the function in JSON format.

```
php webrium call -m SMS@RayeganSmsPatern -p '["parameter 1", "parametr 2"]'
```

<br>

## Database operations:

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
By using `--use`, you can view the list of other databases. But you must have already defined the databases in DB.php
```
php webrium db tables --use=second_db
```

### Create a new database
```
php webrium db create prj_dbname
```

### Drop a database

```
php webrium db drop dbname
```

<br>


## Table operation

### Show columns and column information
```
php webrium table info users
// or
php webrium table columns users
```
In the example above, `users` is the name of the table we want to view its information.

### Drop a table

```
php webrium table drop categorys
```

<br>

## Logs

### Display the list of log files

```
php webrium log list
```

### Show the latest logs

```
php webrium log latest
```

### Display logs based on log file name

```
php webrium log file {log_file_name}
```

<br>

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

