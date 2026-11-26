<?php
declare(strict_types=1);

return [
    // Vælg 'mysql' til MySQL/MariaDB eller 'sqlite' til en lokal databasefil.
    'driver' => 'mysql',
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'staevnevagt',
        'username' => 'staevnevagt',
        'password' => '',
        // 'socket' => '/var/run/mysqld/mysqld.sock',
    ],
    // 'sqlite' => ['path' => __DIR__ . '/var/staevnevagt.sqlite'],
];
