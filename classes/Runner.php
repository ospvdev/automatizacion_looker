<?php
require __DIR__ . '/Drive.php';

class Runner{
    public static function executeSite(string $siteKey, string $from, string $to): array{
        $all = require __DIR__ . '/../config/credenciales.php';
        if ( ! isset($all[$siteKey]) ) {
            throw new RuntimeException("No hay credenciales para '{$siteKey}'");
        }
        $cfg = $all[$siteKey];

        $pdo = DB::connect($cfg);

        // Carga el archivo del sitio: debe retornar un array de reports
        $defs = require __DIR__ . "/../sitios/{$siteKey}.php";
        if ( ! is_array($defs) ) {
            throw new RuntimeException("El sitio '{$siteKey}' no retornó un array de definiciones.");
        }

        $sheets = [];
        foreach ( $defs as $rep ) {
            $title   = $rep['title'];
            $sql     = self::injectPrefix( $rep['sql'], $cfg['prefix'] ?? 'ps_' );
            $params  = $rep['params'] ?? [];
            if ( ! $params ) {
                $params = [ ':from' => $from, ':to' => $to ];
            } else {
                $params += [ ':from' => $from, ':to' => $to ];
            }

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();

            $sheets[$title] = $rows;
        }

        // Excel por sitio
        $tmpDir = dirname(__DIR__) . '/tmp';
        if ( ! is_dir($tmpDir) ) { mkdir($tmpDir, 0775, true); }
        $xlsx = "{$tmpDir}/{$siteKey}__reportes.xlsx";
        $drive = new DriveHelper();

        // Intentamos crear el spreadsheet si no existe y actualizar las pestañas con los datos.
        try {
            $res = $drive->createOrUpdateSpreadsheetFromSheets("{$siteKey}__reportes", $sheets);
            $status = 'actualizado';
            $driveFileId = $res['spreadsheetId'] ?? null;
            $extra = $res['sheets'] ?? null;
        } catch (Exception $e) {
            // Fallback: crear xlsx local y subir (comportamiento anterior)
            $driveRes = $drive->upsert($xlsx, "{$siteKey}__reportes.xlsx");
            $status = $driveRes['updated'] ? 'actualizado' : 'sin cambios';
            $driveFileId = $driveRes['fileId'] ?? null;
            $extra = ['fallback' => $e->getMessage()];
        }

        $result = [
            'site' => $siteKey,
            'status' => $status,
            'file' => $xlsx,
            'driveFileId' => $driveFileId,
        ];
        if (isset($extra)) $result['sheets'] = $extra;

        return $result;
    }

    private static function injectPrefix( string $sql, string $prefix ): string{
        $p = rtrim($prefix, '_') . '_';
        // Reemplaza {{p}} por el prefijo real
        return str_replace('{{p}}', $p, $sql);
    }
}
