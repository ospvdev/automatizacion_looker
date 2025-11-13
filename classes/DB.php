<?php
class DB{
    public static function connect(array $cfg): PDO{
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['ip'],
            $cfg['port'] ?? 3306,
            $cfg['dbname']
        );

        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+00:00'"
        ]);

        return $pdo;
    }
}