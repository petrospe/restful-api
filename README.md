# PHP 8.4 RESTful API

This package implements the REST concepts described in `restfull-api.txt` as a runnable PHP 8.4 example.

## What Is Included

- `GET /api/users` lists users.
- `GET /api/users/{id}` shows one user.
- `POST /api/users` creates a user from JSON or form data.
- `PUT /api/users/{id}` updates a user from JSON or form data.
- `DELETE /api/users/{id}` deletes a user.
- `.json` and `.xml` URL suffixes are supported for response negotiation, with JSON as the default.
- `RestFullApi\Client\RestClientRequest` provides a cURL client for GET, POST, PUT, and DELETE requests.

## Run Locally

```bash
cd restfull-api
php -S 127.0.0.1:8080 -t public public/index.php
```

Example requests:

```bash
curl -i http://127.0.0.1:8080/api/users
curl -i -X POST http://127.0.0.1:8080/api/users \
  -H 'Content-Type: application/json' \
  -d '{"name":"Ada Lovelace","email":"ada@example.test"}'
curl -i http://127.0.0.1:8080/api/users/1.json
curl -i -X PUT http://127.0.0.1:8080/api/users/1 \
  -H 'Content-Type: application/json' \
  -d '{"name":"Ada Byron"}'
curl -i -X DELETE http://127.0.0.1:8080/api/users/1
```

## Add New Routes

Routes are registered on `RestFullApi\Http\Router`. The first argument is the HTTP method, the second is the path pattern, and the third is a callable that receives a `Request` plus route params.

Add simple routes in `public/index.php`:

```php
use RestFullApi\Http\Request;
use RestFullApi\Http\Response;

$router->add('GET', '/api/ping', function (Request $request, array $params): Response {
    return Response::json(['data' => ['message' => 'pong']]);
});

$router->add('GET', '/api/products/{id}', function (Request $request, array $params): Response {
    return Response::json([
        'data' => [
            'id' => (int) $params['id'],
            'name' => 'Example product',
        ],
    ]);
});
```

For bigger resources, create a controller class and register its routes, like `src/User/UserController.php` does:

```php
final readonly class ProductController
{
    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/products', $this->index(...));
        $router->add('GET', '/api/products/{id}', $this->show(...));
        $router->add('POST', '/api/products', $this->create(...));
        $router->add('PUT', '/api/products/{id}', $this->update(...));
        $router->add('DELETE', '/api/products/{id}', $this->delete(...));
    }
}
```

Then wire it in `public/index.php`:

```php
$productController = new ProductController(/* repository or dependencies */);
$productController->registerRoutes($router);
```

## Handle Request Methods

Use `GET` for reads. Route params come from path placeholders like `{id}`. Query string values are available through `$request->query()`.

```php
$router->add('GET', '/api/products/{id}', function (Request $request, array $params): Response {
    $id = (int) $params['id'];

    return Response::json(['data' => ['id' => $id]]);
});
```

Use `POST` for creates. JSON request bodies and form posts are normalized through `$request->input()`.

```php
$router->add('POST', '/api/products', function (Request $request, array $params): Response {
    $input = $request->input();

    if (!isset($input['name']) || !is_string($input['name'])) {
        return Response::error(422, 'The name field is required.');
    }

    return Response::json([
        'data' => [
            'id' => 1,
            'name' => trim($input['name']),
        ],
    ], 201);
});
```

Use `PUT` for updates. The package accepts JSON bodies and URL-encoded form bodies.

```php
$router->add('PUT', '/api/products/{id}', function (Request $request, array $params): Response {
    $input = $request->input();

    return Response::json([
        'data' => [
            'id' => (int) $params['id'],
            'name' => $input['name'] ?? 'Unchanged',
        ],
    ]);
});
```

Use `DELETE` for deletes. Return `204` when the delete succeeds and no body is needed.

```php
$router->add('DELETE', '/api/products/{id}', function (Request $request, array $params): Response {
    return new Response(204);
});
```

Supported response helpers:

- `Response::json($payload, $status)` returns JSON.
- `Response::xml($payload, $status)` returns XML.
- `Response::error($status, $message)` returns a JSON error.
- `new Response(204)` returns an empty response.

## Connect To MySQL

The sample API currently stores users in `data/users.json` through `src/Storage/JsonUserRepository.php`. To use MySQL, replace that repository with one that uses PDO.

Install/enable the PHP MySQL extension:

```bash
php -m | grep pdo_mysql
```

If it is missing on Ubuntu/Debian with PHP 8.4:

```bash
sudo apt install php8.4-mysql
sudo systemctl restart apache2
# or restart php-fpm/nginx if that is your web server stack
```

Store database credentials outside source control, for example in server environment variables:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_DATABASE=restfull_api
export DB_USERNAME=restfull_api_user
export DB_PASSWORD=change-me
```

Create a PDO connection helper, for example `src/Storage/Database.php`:

```php
namespace RestFullApi\Storage;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_DATABASE') ?: 'restfull_api',
        );

        return new PDO($dsn, getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
```

Create the table your repository will use:

```sql
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

Then create a database-backed repository, for example `src/Storage/MySqlUserRepository.php`:

```php
namespace RestFullApi\Storage;

use PDO;

final readonly class MySqlUserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT id, name, email FROM users ORDER BY id')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, email FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function create(array $attributes): array
    {
        $statement = $this->db->prepare('INSERT INTO users (name, email) VALUES (:name, :email)');
        $statement->execute([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ]);

        return $this->find((int) $this->db->lastInsertId()) ?? [];
    }

    public function update(int $id, array $attributes): ?array
    {
        $fields = [];
        $values = ['id' => $id];

        foreach (['name', 'email'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[] = $field . ' = :' . $field;
                $values[$field] = $attributes[$field];
            }
        }

        if ($fields === []) {
            return $this->find($id);
        }

        $statement = $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $statement->execute($values);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}
```

Finally, wire the MySQL repository in `public/index.php` instead of `JsonUserRepository`:

```php
use RestFullApi\Storage\Database;
use RestFullApi\Storage\MySqlUserRepository;

$repository = new MySqlUserRepository(Database::connect());
$controller = new UserController($repository);
$controller->registerRoutes($router);
```

For production, keep using prepared statements, validate all request input in the controller, and add indexes for columns used in `WHERE`, `JOIN`, and `ORDER BY` clauses.

## Connect To SQLite

SQLite is the simplest database option for local tools, demos, and small single-server APIs. It uses one local database file and does not need a separate database server.

Install/enable the PHP SQLite extension:

```bash
php -m | grep pdo_sqlite
```

If it is missing on Ubuntu/Debian with PHP 8.4:

```bash
sudo apt install php8.4-sqlite3
sudo systemctl restart apache2
# or restart php-fpm/nginx if that is your web server stack
```

Choose where the SQLite database file will live:

```bash
export SQLITE_DATABASE=/absolute/path/to/packages/restfull-api/data/restfull_api.sqlite
```

Create a SQLite PDO helper, for example `src/Storage/SqliteDatabase.php`:

```php
namespace RestFullApi\Storage;

use PDO;

final class SqliteDatabase
{
    public static function connect(): PDO
    {
        $path = getenv('SQLITE_DATABASE') ?: __DIR__ . '/../../data/restfull_api.sqlite';
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $db->exec('PRAGMA foreign_keys = ON');

        return $db;
    }
}
```

Create the table. You can run this once from a small setup script or from the SQLite CLI:

```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS users_email_idx ON users (email);
```

Create a SQLite-backed repository, for example `src/Storage/SqliteUserRepository.php`. It is almost the same as the MySQL PDO repository:

```php
namespace RestFullApi\Storage;

use PDO;

final readonly class SqliteUserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT id, name, email FROM users ORDER BY id')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, email FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function create(array $attributes): array
    {
        $statement = $this->db->prepare('INSERT INTO users (name, email) VALUES (:name, :email)');
        $statement->execute([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ]);

        return $this->find((int) $this->db->lastInsertId()) ?? [];
    }

    public function update(int $id, array $attributes): ?array
    {
        $fields = [];
        $values = ['id' => $id];

        foreach (['name', 'email'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[] = $field . ' = :' . $field;
                $values[$field] = $attributes[$field];
            }
        }

        if ($fields === []) {
            return $this->find($id);
        }

        $statement = $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute($values);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}
```

Wire it in `public/index.php`:

```php
use RestFullApi\Storage\SqliteDatabase;
use RestFullApi\Storage\SqliteUserRepository;

$repository = new SqliteUserRepository(SqliteDatabase::connect());
$controller = new UserController($repository);
$controller->registerRoutes($router);
```

SQLite is file-based, so make sure the web server user can read and write the `.sqlite` file and its parent directory. For production with many concurrent writes, prefer MySQL or PostgreSQL.

## Connect To PostgreSQL

PostgreSQL can use the same PDO repository pattern as MySQL, but with the `pdo_pgsql` extension and PostgreSQL SQL syntax.

Install/enable the PHP PostgreSQL extension:

```bash
php -m | grep pdo_pgsql
```

If it is missing on Ubuntu/Debian with PHP 8.4:

```bash
sudo apt install php8.4-pgsql
sudo systemctl restart apache2
# or restart php-fpm/nginx if that is your web server stack
```

Use environment variables for the connection:

```bash
export PGHOST=127.0.0.1
export PGPORT=5432
export PGDATABASE=restfull_api
export PGUSER=restfull_api_user
export PGPASSWORD=change-me
```

Create a PostgreSQL PDO helper, for example `src/Storage/PostgresDatabase.php`:

```php
namespace RestFullApi\Storage;

use PDO;

final class PostgresDatabase
{
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('PGHOST') ?: '127.0.0.1',
            getenv('PGPORT') ?: '5432',
            getenv('PGDATABASE') ?: 'restfull_api',
        );

        return new PDO($dsn, getenv('PGUSER') ?: '', getenv('PGPASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
```

Create the table:

```sql
CREATE TABLE users (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX users_email_idx ON users (email);
```

The repository is almost the same as the MySQL example. The main difference is using `RETURNING id` after inserts:

```php
public function create(array $attributes): array
{
    $statement = $this->db->prepare(
        'INSERT INTO users (name, email) VALUES (:name, :email) RETURNING id'
    );
    $statement->execute([
        'name' => $attributes['name'],
        'email' => $attributes['email'],
    ]);

    $id = (int) $statement->fetchColumn();

    return $this->find($id) ?? [];
}
```

Wire it in `public/index.php`:

```php
use RestFullApi\Storage\PostgresDatabase;
use RestFullApi\Storage\PostgresUserRepository;

$repository = new PostgresUserRepository(PostgresDatabase::connect());
$controller = new UserController($repository);
$controller->registerRoutes($router);
```

For production PostgreSQL, keep transactions short, use prepared statements, and add indexes that match your `WHERE`, `JOIN`, and `ORDER BY` patterns. For large lists, prefer keyset pagination over large `OFFSET` values.

## Connect To MongoDB

MongoDB needs the PHP MongoDB extension plus the MongoDB PHP library. This is different from MySQL/PostgreSQL because it does not use PDO.

Install/enable the extension:

```bash
php -m | grep mongodb
```

If it is missing:

```bash
sudo pecl install mongodb
```

Then add this line to the active PHP configuration file for Apache or PHP-FPM:

```ini
extension=mongodb.so
```

Restart the web server after enabling it. Then install the PHP library in this package:

```bash
cd packages/restfull-api
composer require mongodb/mongodb
```

Use environment variables for the connection:

```bash
export MONGODB_URI='mongodb://127.0.0.1:27017'
export MONGODB_DATABASE=restfull_api
```

Create a MongoDB connection helper, for example `src/Storage/MongoDatabase.php`:

```php
namespace RestFullApi\Storage;

use MongoDB\Client;
use MongoDB\Database;

final class MongoDatabase
{
    public static function connect(): Database
    {
        $client = new Client(getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017');

        return $client->selectDatabase(getenv('MONGODB_DATABASE') ?: 'restfull_api');
    }
}
```

Create indexes for fields you query often:

```php
$database = MongoDatabase::connect();
$database->users->createIndex(['email' => 1], ['unique' => true]);
$database->users->createIndex(['created_at' => -1]);
```

Create a MongoDB-backed repository, for example `src/Storage/MongoUserRepository.php`:

```php
namespace RestFullApi\Storage;

use MongoDB\Collection;
use MongoDB\BSON\ObjectId;

final readonly class MongoUserRepository
{
    public function __construct(private Collection $users)
    {
    }

    public function all(): array
    {
        $documents = $this->users->find([], ['sort' => ['created_at' => -1]]);

        return array_map($this->normalize(...), iterator_to_array($documents));
    }

    public function find(string $id): ?array
    {
        $document = $this->users->findOne(['_id' => new ObjectId($id)]);

        return $document === null ? null : $this->normalize($document);
    }

    public function create(array $attributes): array
    {
        $document = [
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        $result = $this->users->insertOne($document);

        return $this->find((string) $result->getInsertedId()) ?? [];
    }

    public function update(string $id, array $attributes): ?array
    {
        $this->users->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $attributes + ['updated_at' => new \MongoDB\BSON\UTCDateTime()]],
        );

        return $this->find($id);
    }

    public function delete(string $id): bool
    {
        return $this->users->deleteOne(['_id' => new ObjectId($id)])->getDeletedCount() > 0;
    }

    private function normalize(object $document): array
    {
        return [
            'id' => (string) $document->_id,
            'name' => (string) $document->name,
            'email' => (string) $document->email,
        ];
    }
}
```

Wire it in `public/index.php`:

```php
use RestFullApi\Storage\MongoDatabase;
use RestFullApi\Storage\MongoUserRepository;

$database = MongoDatabase::connect();
$repository = new MongoUserRepository($database->users);
$controller = new UserController($repository);
$controller->registerRoutes($router);
```

If you use MongoDB string IDs, update the controller route handling to accept string IDs instead of casting route params to `int`. Keep validation in the controller, create indexes for frequently filtered fields, and avoid returning unbounded collections from `GET` list routes.

## Test

```bash
cd packages/restfull-api
php tests/run.php
```

The sample API stores users in `data/users.json`. This keeps the implementation self-contained and easy to run without a database.
