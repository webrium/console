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
| `make:migration` | Generate a database migration file |
| `make:seeder` | Generate a database seeder file |
| `migrate` | Run, roll back, or inspect database migrations |
| `db:seed` | Run database seeders |
| `call` | Call a method on a controller or model |
| `db` | Manage databases |
| `table` | Manage database tables and execute SQL files |
| `log` | Manage log files |
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

## `make:migration`

Generates a timestamped migration file in `database/migrations`. Builds on top of [`webrium/foxdb`](https://github.com/webrium/foxdb)'s migration system (`Foxdb\Migrations\Migration`, `Schema`, `Blueprint`).

```bash
php webrium make:migration <name> [--table=<table>] [--force]
```

| Argument / Option | Description |
|---|---|
| `name` | Migration name, e.g. `create_posts_table` or `add_status_to_posts_table` |
| `--table, -t` | Explicit table name. If omitted, it's inferred from the migration name |
| `--force, -f` | Allow generating another migration with the same descriptive name |

The generated stub depends on the naming convention used:

- **`create_..._table`** → uses the *create* stub, with `Schema::create()` already wired up and a ready-to-run `id()` + `timestamps()` example.
- **`add_..._to_..._table`** / **`remove_..._from_..._table`** → uses the *update* stub, with an empty `Schema::table()` block in both `up()` and `down()` for you to fill in.
- Anything else falls back to the *create* stub.

In every case the table name is inferred automatically from the migration name, unless `--table` is given explicitly.

```bash
# Create stub — Schema::create('posts', ...) is pre-filled
php webrium make:migration create_posts_table

# Update stub — Schema::table('posts', ...) with an empty body
php webrium make:migration add_status_to_posts_table
php webrium make:migration remove_legacy_id_from_posts_table

# Explicit table name, useful when the migration name doesn't follow either convention
php webrium make:migration setup_indexes --table=posts

# Allow a duplicate descriptive name (creates a second, separately timestamped file)
php webrium make:migration create_posts_table --force
```

---

## `migrate`

Runs database migrations from `database/migrations` using [`webrium/foxdb`](https://github.com/webrium/foxdb)'s `Migrator`. Tracks applied migrations in a `migrations` table, batched the same way per run so a whole batch can be rolled back together.

```bash
php webrium migrate [<action>] [--step=<n>] [--connection=<name>] [--force]
```

| Action | Description |
|---|---|
| `run` *(default)* | Apply all pending migrations |
| `rollback` | Roll back the last batch (or `--step` migrations) |
| `reset` | Roll back every migration that has been run |
| `refresh` | Roll back everything, then run all migrations again |
| `status` | Show which migrations have run, and in which batch |

| Option | Description |
|---|---|
| `--step` | Limit `run`/`rollback` to a specific number of migrations |
| `--connection, -c` | Run against a named connection instead of the default one |
| `--seed` | After a successful `run` or `refresh`, also run every seeder in `database/seeders` |
| `--force, -f` | Skip the confirmation prompt for `reset`/`refresh` |

```bash
# Apply all pending migrations
php webrium migrate
php webrium migrate run

# Show migration status
php webrium migrate status

# Roll back the most recent batch
php webrium migrate rollback

# Roll back only the last 2 migrations
php webrium migrate rollback --step=2

# Roll back everything, with confirmation
php webrium migrate reset

# Roll back everything, skipping the confirmation prompt
php webrium migrate reset --force

# Roll back and re-run all migrations
php webrium migrate refresh --force

# Run against a non-default connection
php webrium migrate --connection=secondary

# Apply pending migrations, then run all seeders
php webrium migrate --seed

# Reset, re-run, and re-seed in one command
php webrium migrate refresh --seed --force
```

Each migration runs inside its own database transaction. If a migration fails, `migrate` stops and reports it — earlier migrations in the same run stay applied, matching the underlying `Migrator::run()` behavior.

---

## `make:seeder`

Generates a seeder class in `database/seeders`. Seeders populate the database with default or test data (admin users, lookup tables, categories, etc.) and are built on top of [`webrium/foxdb`](https://github.com/webrium/foxdb)'s `Foxdb\Seeders\Seeder` base class.

```bash
php webrium make:seeder <Name> [--force]
```

| Argument / Option | Description |
|---|---|
| `Name` | Seeder class name (e.g. `UsersSeeder`). Auto-converted to PascalCase if given in snake_case |
| `--force, -f` | Overwrite if the file already exists |

```bash
php webrium make:seeder UsersSeeder
php webrium make:seeder roles_seeder        # generated as RolesSeeder.php
php webrium make:seeder UsersSeeder --force
```

The generated stub looks like:

```php
<?php

use Foxdb\DB;
use Foxdb\Seeders\Seeder;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // DB::table('users')->insert([
        //     'name'  => 'Admin',
        //     'email' => 'admin@example.com',
        // ]);

        // To call other seeders:
        // $this->call(RolesSeeder::class);
    }
}
```

Inside a seeder you can chain to other seeders with `$this->call(...)`, which accepts a class name or an array of class names. This is the recommended way to build a master seeder that orchestrates the others.

---

## `db:seed`

Runs database seeders from `database/seeders` using [`webrium/foxdb`](https://github.com/webrium/foxdb)'s `SeederRunner`. Unlike migrations, seeders are **not tracked** — every invocation runs them fresh, so they should be written to be idempotent if you intend to run them more than once.

```bash
php webrium db:seed [<class>] [--connection=<name>] [--no-transaction] [--force]
```

| Argument / Option | Description |
|---|---|
| `class` | Optional. Class or file name of a single seeder to run. If omitted, every seeder in `database/seeders` is executed in alphabetical order |
| `--connection, -c` | Run against a named connection instead of the default one |
| `--no-transaction` | Do not wrap each seeder in a transaction (use when seeders contain DDL or are intentionally non-atomic) |
| `--force, -f` | Skip the production confirmation prompt (relevant only when `APP_ENV=production`) |

```bash
# Run every seeder in database/seeders
php webrium db:seed

# Run a single seeder by file name
php webrium db:seed UsersSeeder

# Run a single seeder by fully qualified class name
php webrium db:seed "App\\Seeders\\UsersSeeder"

# Use a non-default connection
php webrium db:seed --connection=secondary

# Disable the per-seeder transaction
php webrium db:seed --no-transaction

# Run in production without an interactive prompt
APP_ENV=production php webrium db:seed --force
```

Each seeder runs inside its own transaction by default, so a failure mid-seed rolls back any inserts from that seeder. If a seeder fails, `db:seed` stops and reports it — seeders that already completed remain applied.

When `APP_ENV` is set to `production` (or `prod`), `db:seed` asks for confirmation before running. Pass `--force` to bypass the prompt in automated environments.

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

Inspects and manages individual tables, and can also execute SQL files against a database.

```bash
php webrium table <action> <table_name> [--use=<database>] [--force]
```

| Action | Description |
|---|---|
| `info` | Show table information |
| `columns` | Show column details (name, type, nullable, key, default, extra) |
| `drop` | Delete the table (prompts for confirmation) |
| `truncate` | Remove all rows from the table (prompts for confirmation) |
| `rename` | Rename an existing table |
| `copy` | Copy table structure to a new table |
| `exists` | Check whether a table exists |
| `count` | Count rows in a table |
| `run` | Execute a SQL file (`<table_name>` is treated as a file path) |

| Option | Description |
|---|---|
| `--use, -u` | Specify a database |
| `--force, -f` | Skip confirmation prompts for destructive actions |

```bash
php webrium table info users
php webrium table columns orders --use=shop_db
php webrium table drop sessions
php webrium table drop sessions --force
php webrium table rename old_table new_table
php webrium table copy products products_backup
php webrium table exists users
php webrium table count orders
php webrium table run sql/setup_tables.sql --use=shop_db
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