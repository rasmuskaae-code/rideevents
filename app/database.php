<?php
declare(strict_types=1);

function databaseConfig(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $specified = getenv('STAEVNE_CONFIG');
    $file = $specified ?: dirname(__DIR__) . '/config.php';
    if ($specified && !is_file($file)) {
        throw new RuntimeException('Konfigurationsfilen angivet i STAEVNE_CONFIG findes ikke.');
    }
    $settings = is_file($file) ? require $file : ['driver' => 'sqlite'];
    if (!is_array($settings) || !in_array($settings['driver'] ?? '', ['sqlite', 'mysql'], true)) {
        throw new RuntimeException('Vælg driveren sqlite eller mysql i config.php.');
    }
    if ($settings['driver'] === 'mysql') {
        $mysql = $settings['mysql'] ?? [];
        foreach (['database', 'username', 'password'] as $key) {
            if (!is_string($mysql[$key] ?? null) || ($key !== 'password' && $mysql[$key] === '')) {
                throw new RuntimeException("Angiv mysql.$key i config.php.");
            }
        }
        $endpoint = !empty($mysql['socket'])
            ? 'unix_socket=' . dsnPart($mysql['socket'])
            : 'host=' . dsnPart($mysql['host'] ?? '127.0.0.1') . ';port=' . (int) ($mysql['port'] ?? 3306);
        return $config = [
            'driver' => 'mysql',
            'dsn' => 'mysql:' . $endpoint . ';dbname=' . dsnPart($mysql['database']) . ';charset=utf8mb4',
            'username' => $mysql['username'],
            'password' => $mysql['password'],
        ];
    }

    return $config = ['driver' => 'sqlite', 'path' => getenv('STAEVNE_DB') ?: ($settings['sqlite']['path'] ?? dirname(__DIR__) . '/var/staevnevagt.sqlite')];
}

function dsnPart(string $value): string
{
    if ($value === '' || strpbrk($value, ";\0") !== false) {
        throw new RuntimeException('Ugyldig databaseadresse i config.php.');
    }
    return $value;
}

function databaseIdentity(): string
{
    $settings = databaseConfig();
    return hash('sha256', $settings['dsn'] ?? ('sqlite:' . $settings['path']));
}

function usingMysql(): bool
{
    return databaseConfig()['driver'] === 'mysql';
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $settings = databaseConfig();
    $options = [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if (!in_array($settings['driver'], PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Aktivér PHP-udvidelsen pdo_' . $settings['driver'] . '.');
    }
    if (usingMysql()) {
        $options += [PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
        $connection = new PDO($settings['dsn'], $settings['username'], $settings['password'], $options);
        $connection->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        initializeMysql($connection);
        return $pdo = $connection;
    }

    $path = $settings['path'];
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Kunne ikke oprette datamappen.');
    }

    $connection = new PDO('sqlite:' . $path, options: $options);
    $connection->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    initializeSqlite($connection);
    return $pdo = $connection;
}

function initializeSqlite(PDO $pdo): void
{
    $migrations = [1 => __DIR__ . '/schema.sql', 2 => __DIR__ . '/migrations/002-organizers.sql'];
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= array_key_last($migrations)) {
        return;
    }
    transaction($pdo, function () use ($pdo, $migrations): void {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        foreach ($migrations as $target => $file) {
            if ($version < $target) {
                $pdo->exec(file_get_contents($file));
                $pdo->exec('PRAGMA user_version = ' . $target);
            }
        }
    });
}

function mysqlHasSchema(PDO $pdo): bool
{
    $exists = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='staevne_schema'")->fetchColumn();
    return $exists && (int) $pdo->query('SELECT MAX(version) FROM staevne_schema')->fetchColumn() >= 1;
}

function initializeMysql(PDO $pdo): void
{
    if (mysqlHasSchema($pdo)) {
        return;
    }
    $lock = mysqlLock($pdo, 'schema', 20) ?? throw new RuntimeException('Databasen klargøres af en anden proces.');
    try {
        if (mysqlHasSchema($pdo)) {
            return;
        }
        $statements = explode(';', file_get_contents(__DIR__ . '/schema-mysql.sql'));
        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        transaction($pdo, function () use ($pdo): void {
            $pdo->exec("INSERT INTO organizers (name) VALUES ('Stævnevagt')");
            $pdo->exec('INSERT INTO staevne_schema (version) VALUES (1)');
        });
    } finally {
        releaseMysqlLock($pdo, $lock);
    }
}

function mysqlLock(PDO $pdo, string $purpose, int $seconds): ?string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $name = hash('sha256', "staevnevagt:$database:$purpose");
    $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $statement->execute([$name,$seconds]);
    return (int) $statement->fetchColumn() === 1 ? $name : null;
}

function releaseMysqlLock(PDO $pdo, string $name): void
{
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$name]);
}

function query(string $sql, array $args = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    return $statement;
}

function row(string $sql, array $args = []): ?array
{
    return query($sql, $args)->fetch() ?: null;
}

function lockedRow(string $sql, array $args = []): ?array
{
    return row($sql . (usingMysql() ? ' FOR UPDATE' : ''), $args);
}

function atomic(callable $work): mixed
{
    return transaction(db(), $work);
}

function transaction(PDO $pdo, callable $work): mixed
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    for ($attempt = 1; ; $attempt++) {
        if ($mysql) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            $result = $work();
            if ($mysql) {
                $pdo->commit();
            } else {
                $pdo->exec('COMMIT');
            }
            return $result;
        } catch (Throwable $error) {
            if (!$mysql) {
                $pdo->exec('ROLLBACK');
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$mysql || $attempt >= 3 || !$error instanceof PDOException || !in_array((int) ($error->errorInfo[1] ?? 0), [1205,1213], true)) {
                throw $error;
            }
            usleep(20000 * $attempt);
        }
    }
}

function insertUnique(string $sql, array $args): bool
{
    if (!usingMysql()) {
        return query(preg_replace('/^INSERT INTO /', 'INSERT OR IGNORE INTO ', $sql), $args)->rowCount() === 1;
    }
    try {
        query($sql, $args);
        return true;
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
            throw $error;
        }
        return false;
    }
}

function sqlConcat(string ...$values): string
{
    return usingMysql() ? 'CONCAT(' . implode(',', $values) . ')' : '(' . implode(' || ', $values) . ')';
}
