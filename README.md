# Webrium Console

A command-line toolkit for the [Webrium](https://github.com/webrium) PHP framework. Provides commands for scaffolding files, managing databases, inspecting logs, and installing plugins.

## Requirements

- PHP 8.1+
- Symfony Console 6.4+

## Installation

```bash
composer require webrium/console
```

---

## Available Commands

| Command | Description |
|---|---|
| `init` | Create the project directory structure |
| `make:model` | Generate a model file |
| `make:controller` | Generate a controller file |
| `make:route` | Generate a route file |
| `call` | Call a method on a controller or model |
| `db` | Manage databases |
| `table` | Manage database tables |
| `log` | Manage log files |
| `botfire:init` | Initialize a Telegram bot |
| `plugin:install` | Install a plugin |
| `plugin:update` | Update an installed plugin |
| `plugin:remove` | Remove an installed plugin |
| `plugin:list` | List installed plugins |
| `plugin:info` | Preview a plugin without installing |

---

## `init`

Creates all standard Webrium project directories.

```bash
php webrium init
```

---

## `make:model`

Generates a model file in the models directory. Without `--table`, creates a simple model. With `--table`, creates a database-connected model.

```bash
php webrium make:model <Name> [--table=<table>] [--no-plural] [--force]
```

| Argument / Option | Description |
|---|---|
| `Name` | Model class name (e.g. `User`) |
| `--table, -t` | Database table name. If omitted, the name is auto-converted to snake_case and pluralized |
| `--no-plural` | Prevent automatic pluralization of the table name |
| `--force, -f` | Overwrite if the file already exists |

```bash
# DB model with explicit table name
php webrium make:model User --table=users

# DB model — table name auto-generated as "users"
php webrium make:model User -t

# Simple model (no DB)
php webrium make:model UserHelper

# DB model — table stays "status" instead of "statuses"
php webrium make:model Status -t --no-plural
```

---

## `make:controller`

Generates a controller file in the controllers directory. Automatically appends `Controller` to the name if not already present.

```bash
php webrium make:controller <Name> [--namespace=<Namespace>] [--force]
```

| Argument / Option | Description |
|---|---|
| `Name` | Controller name (e.g. `User` → `UserController`) |
| `--namespace` | Custom namespace (default: `App\Controllers`) |
| `--force, -f` | Overwrite if the file already exists |

```bash
php webrium make:controller User
php webrium make:controller Admin --namespace="App\Controllers\Admin"
```

---

## `make:route`

Generates a route file in the routes directory.

```bash
php webrium make:route <Name> [--force]
```

| Argument / Option | Description |
|---|---|
| `Name` | Route file name (e.g. `Api` → `Api.php`) |
| `--force, -f` | Overwrite if the file already exists |

```bash
php webrium make:route Api
php webrium make:route Web --force
```

---

## `call`

Calls a method on a controller or model class directly from the terminal.

```bash
php webrium call <Class@Method> [--params=<JSON>] [--model] [--namespace=<Namespace>]
```

| Argument / Option | Description |
|---|---|
| `Class@Method` | Class and method name (e.g. `UserController@index`) |
| `--params, -p` | JSON array of arguments passed to the method (default: `[]`) |
| `--model, -m` | Target a model instead of a controller |
| `--namespace` | Custom namespace (default: `App\Controllers` or `App\Models`) |

```bash
php webrium call UserController@index
php webrium call UserController@find --params='[42]'
php webrium call User@active --model
php webrium call Report@generate --params='["2024-01", true]' --namespace="App\Services"
```

---

## `db`

Manages databases.

```bash
php webrium db <action> [<name>] [--use=<database>] [--force]
```

| Action | Description |
|---|---|
| `list` | List all databases |
| `tables` | List tables in a database |
| `create` | Create a new database |
| `drop` | Delete a database (prompts for confirmation) |

| Option | Description |
|---|---|
| `--use, -u` | Specify a database for the `tables` action |
| `--force, -f` | Skip confirmation prompt when dropping |

```bash
php webrium db list
php webrium db tables --use=my_database
php webrium db create my_database
php webrium db drop my_database
php webrium db drop my_database --force
```

---

## `table`

Inspects and manages individual tables.

```bash
php webrium table <action> <table_name> [--use=<database>] [--force]
```

| Action | Description |
|---|---|
| `info` / `columns` | Show column details (name, type, nullable, key, default, extra) |
| `drop` | Delete the table (prompts for confirmation) |

| Option | Description |
|---|---|
| `--use, -u` | Specify a database |
| `--force, -f` | Skip confirmation prompt when dropping |

```bash
php webrium table info users
php webrium table columns orders --use=shop_db
php webrium table drop sessions
php webrium table drop sessions --force
```

---

## `log`

Manages Webrium log files stored in the logs directory.

```bash
php webrium log <action> [<name>]
```

| Action | Description |
|---|---|
| `list` | List all log files |
| `latest` | Display the most recent log file |
| `file <name>` | Display a specific log file by name |
| `clear` | Delete all log files |

```bash
php webrium log list
php webrium log latest
php webrium log file 2024-01-15.log
php webrium log clear
```

---

## `botfire:init`

Scaffolds the files needed to connect a Telegram bot to your Webrium project.

```bash
php webrium botfire:init [<token>] [--debug=<chat_id>] [--force]
```

| Argument / Option | Description |
|---|---|
| `token` | Your Telegram bot token (optional at this stage) |
| `--debug` | Chat ID to receive error messages in debug mode |
| `--force, -f` | Overwrite existing bot files |

```bash
php webrium botfire:init 123456:ABC-DEF
php webrium botfire:init 123456:ABC-DEF --debug=987654321
```

The command copies route and controller files for the bot and adds the token and debug settings to your `.env` file.

---

## Plugin System

Webrium Console includes a full plugin system for installing and managing distributable components.

```bash
php webrium plugin:install <source> [--force] [--dry-run] [--no-backup]
php webrium plugin:update  <source> [--force] [--no-backup]
php webrium plugin:remove  <name>   [--no-backup] [--keep-files]
php webrium plugin:list
php webrium plugin:info    <source>
```

The `source` argument accepts a local `.zip` file path or an `https://` URL:

```bash
php webrium plugin:install ./my-plugin.zip
php webrium plugin:install https://example.com/releases/my-plugin.zip
php webrium plugin:install https://github.com/user/repo/releases/download/v1.0.0/plugin.zip
```

For full documentation on creating and distributing plugins, see the **[Plugin System Wiki](https://github.com/webrium/console/wiki/webrium-plugin-system)**.

---

## License

MIT