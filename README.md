# Webrium Console



The Webrium Console Commands include tools for generating files, calling methods, and managing databases. Below is a list of available commands and how to use them.


## Generate a Model

The make:model command creates a new model file in the models directory. You can create either a simple model or a database-connected model.

#### Usage
```
php webrium make:model <ModelName> [--table=<TableName>] [--force] [--no-plural]
```

 - **ModelName** : The name of the model (e.g., User).
 - **--table|-t**: Specify the database table name (e.g., users). If omitted, the model name is converted to snake_case and pluralized (e.g., User becomes users).
- **--force|-f**: Overwrite the model file if it already exists.
- **--no-plural**: Prevent adding an "s" to the table name (e.g., User stays user instead of users).

#### Example
```
php webrium make:model User --table=users
```
Or instead of that
```
php webrium make:model User -t
```

This creates a User.php model file in the models directory, linked to the users table.


## Controller operation


### Generate a Controller

The make:controller command creates a new controller file in the controllers directory.

#### Usage
```
php webrium make:controller <ControllerName> [--force] [--namespace=<Namespace>]
```


ControllerName: The name of the controller (e.g., User). The suffix Controller is automatically added if not included.

- **--force|-f**: Overwrite the controller file if it already exists.
- **--namespace**: Specify a custom namespace (default: App\Controllers).

#### Example

```
php webrium make:controller User
```

This creates a UserController.php file in the controllers directory with the namespace App\Controllers.



## Call a Controller or Model Method

The call command allows you to execute a method on a controller or model, passing optional parameters.

#### Usage

```
php webrium call <Class@Method> [--params=<JSON>] [--model] [--namespace=<Namespace>]
```

- **Class@Method**: The class and method to call (e.g., UserController@getUsers or User@getDetails).
- **--params|-p**: A JSON array of parameters (e.g., [1, "active"]). Defaults to an empty array.
- **--model|-m**: Target a model instead of a controller.
- **--namespace**: Specify a custom namespace (default: App\Controllers for controllers, App\Models for models).


#### Example

```
php webrium call UserController@getUsers --params='[1, "active"]'
```

This calls the getUsers method on App\Controllers\UserController with the parameters [1, "active"].

<br>


## Manage Databases

The `db` command provides tools to manage databases, including listing databases, viewing tables, creating databases, and deleting databases.

#### Usage

```
php webrium db <action> [<DatabaseName>] [--use=<Database>] [--force]
```

- action: The action to perform:
  - list: List all databases.
  - tables: List tables in a database.
  - create: Create a new database.
  - drop: Delete a database.

- **DatabaseName**: The name of the database (required for create and drop).
- **--use|-u**: Specify a database for the tables action.
- **--force|-f**: Skip confirmation when dropping a database.


#### Examples

List all databases:
```
php webrium db list
```

List tables in a specific database:
```
php webrium db tables --use=my_database
```

Create a new database:

```
php webrium db create my_database
```

Delete a database (with confirmation):
```
php webrium db drop my_database
```



<br>


## Manage Tables

The table command allows you to manage database tables, including viewing column details and deleting tables.

#### Usage
```
php webrium table <action> <TableName> [--use=<Database>] [--force]
```

- **action**: The action to perform:
  - **info** or `columns`: Display column details (name, type, null, key, default, extra).
  - **drop**: Delete the table.

- **TableName**: The name of the table.
- **--use|-u**: Specify a database.
- **--force|-f**: Skip confirmation when dropping a table.

#### Examples

View columns of a table:
```
php webrium table columns users 
```

Delete a table (with confirmation):
```
php webrium table drop users
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



