<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Brug: php bin/import-sqlite.php /absolut/sti/til/staevnevagt.sqlite\n");
    exit(1);
}

try {
    if (!usingMysql()) {
        throw new RuntimeException('Vælg mysql i config.php før importen.');
    }

    $sqlite = new PDO('sqlite:' . realpath($argv[1]), options: [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $sqlite->beginTransaction();
    $version = (int) $sqlite->query('PRAGMA user_version')->fetchColumn();
    if (!in_array($version, [1,2,3,4], true)) {
        throw new RuntimeException('Filen skal være en Stævnevagt-database med skemaversion 1, 2, 3 eller 4.');
    }

    $mysql = db();
    $lock = mysqlLock($mysql, 'import', 0) ?? throw new RuntimeException('En import kører allerede.');
    try {
        $tables = [
            'users' => 'id,name,email,password,role',
            'organizers' => 'id,name,owner_id,active',
            'events' => 'id,name,discipline,location,starts_on,ends_on,description,visible,organizer_id',
            'groups' => 'id,event_id,name,description,visible',
            'images' => 'id,group_id,position,mime,data',
            'shifts' => 'id,group_id,name,starts_at,ends_at,capacity,visible,bookable',
            'signups' => 'id,user_id,shift_id,created_at',
            'contacts' => 'id,event_id,name,contact,task',
            'notices' => 'id,user_id,title,body,created_at,read_at,mail_sent_at,reminder_key',
            'login_attempts' => '`key`,`window`,`count`',
            'helper_applications' => 'event_id,user_id,status,requested_at,decided_at',
            'organizer_event_usage' => 'organizer_id,created_count',
            'billing_orders' => 'id,organizer_id,plan,live_mode,price_id,session_id,payment_intent,checkout_url,expires_at,created_at,closed_at,paid_at,access_until,consumed_at,refunded_at',
        ];
        $assertEmpty = static function () use ($tables): int {
            $organizers = query('SELECT id,name,owner_id,active FROM organizers')->fetchAll();
            if (count($organizers) !== 1 || $organizers[0]['name'] !== 'Stævnevagt' ||
                $organizers[0]['owner_id'] !== null || $organizers[0]['active'] !== 1) {
                throw new RuntimeException('MySQL-databasen skal være en ny, tom Stævnevagt-installation.');
            }
            foreach (array_keys($tables) as $table) {
                if ($table !== 'organizers' && query("SELECT 1 FROM `$table` LIMIT 1")->fetchColumn()) {
                    throw new RuntimeException('MySQL-databasen indeholder allerede Stævnevagt-data.');
                }
            }
            return $organizers[0]['id'];
        };

        $assertEmpty();
        // Bevar nummerserien, også hvis de seneste tilmeldinger er slettet.
        $lastSignup = (int) $sqlite->query("SELECT seq FROM sqlite_sequence WHERE name='signups'")->fetchColumn();
        if ($lastSignup > 0) {
            $mysql->exec('ALTER TABLE signups AUTO_INCREMENT = ' . ($lastSignup + 1));
        }

        $counts = atomic(function () use ($assertEmpty, $tables, $mysql, $sqlite, $version): array {
            $platform = $assertEmpty();
            if ($version >= 2) {
                query('DELETE FROM organizers WHERE id=?', [$platform]);
            }

            $counts = [];
            foreach ($tables as $table => $columns) {
                if (($version === 1 && $table === 'organizers') || ($version < 3 && in_array($table, ['helper_applications','organizer_event_usage','billing_orders'], true))) {
                    continue;
                }

                $source = $version === 1 && $table === 'events'
                    ? str_replace('organizer_id', "$platform AS organizer_id", $columns)
                    : $columns;
                $data = $sqlite->query("SELECT $source FROM `$table`");
                $placeholders = implode(',', array_fill(0, count(explode(',', $columns)), '?'));
                $insert = $mysql->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");
                $count = 0;
                while ($record = $data->fetch(PDO::FETCH_NUM)) {
                    foreach ($record as $index => $value) {
                        $type = match (true) {
                            $value === null => PDO::PARAM_NULL,
                            is_int($value) => PDO::PARAM_INT,
                            $table === 'images' && $index === 4 => PDO::PARAM_LOB,
                            default => PDO::PARAM_STR,
                        };
                        $insert->bindValue($index + 1, $value, $type);
                    }
                    $insert->execute();
                    $count++;
                }
                $counts[$table] = $count;
            }
            if ($version < 3) {
                runMysqlSql($mysql, dirname(__DIR__) . '/app/migrations/access-and-billing-backfill.sql');
            }
            if (!row('SELECT id FROM organizers WHERE owner_id IS NULL')) {
                throw new RuntimeException('SQLite-databasen mangler platformarrangøren.');
            }
            return $counts;
        });
        foreach ($counts as $table => $count) {
            fwrite(STDOUT, "$table: $count\n");
        }
        fwrite(STDOUT, "Importen er gennemført.\n");
    } finally {
        releaseMysqlLock($mysql, $lock);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Importen mislykkedes. ' . $error->getMessage() . "\n");
    exit(1);
}
