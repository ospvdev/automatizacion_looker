<?php
declare(strict_types=1);

date_default_timezone_set('America/Santiago');

require __DIR__ . '/classes/DB.php';
require __DIR__ . '/classes/Excel.php';
require __DIR__ . '/classes/Runner.php';

$from = date('2024-01-01');
$to = date('2025-12-31');

// Log
$logDir = __DIR__ . '/logs';
if ( ! is_dir($logDir) ) { mkdir($logDir, 0775, true); }
$today = date('Y-m-d');
$log   = [ $today => [] ];

foreach ( glob(__DIR__ . '/sitios/*.php') as $file ) {
    $siteKey = basename($file, '.php');
    try {
        // Runner carga y ejecuta las queries definidas en sitios/<siteKey>.php
        $res = Runner::executeSite($siteKey, $from, $to);
        $log[$today][$siteKey] = $res['status'] ?? 'ok';
        var_dump($res); // para debug en consola
    } catch (Throwable $e) {
        $log[$today][$siteKey] = 'error: ' . $e->getMessage();
        echo "[{$siteKey}] ERROR: {$e->getMessage()}\n";
    }
}

file_put_contents("{$logDir}/{$today}.json", json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));