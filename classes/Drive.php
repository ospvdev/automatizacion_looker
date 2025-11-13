<?php

class DriveHelper{
    private string $folderId;
    private array  $sa;

    public function __construct(){
        $cfg = require __DIR__ . '/../config/drive.php';

        if ( empty($cfg['folder_id']) ) {
            throw new RuntimeException('Drive: folder_id no está definido en config/drive.php');
        }
        if ( empty($cfg['service_account_json']) ) {
            throw new RuntimeException('Drive: ruta de service_account_json no está definida en config/drive.php');
        }

        $this->folderId = $cfg['folder_id'];

        $jsonPath = $cfg['service_account_json'];

        if ( ! file_exists($jsonPath) ) {
            throw new RuntimeException("Drive: no se encontró el archivo de service account en: {$jsonPath}");
        }

        $json = file_get_contents($jsonPath);
        if ($json === false) {
            throw new RuntimeException("Drive: no se pudo leer el archivo de service account: {$jsonPath}");
        }

        $data = json_decode($json, true);
        if ( ! is_array($data) ) {
            throw new RuntimeException("Drive: el JSON de service account es inválido (no se pudo decodificar).");
        }

        $this->sa = $data;
        error_log("JSON PATH: " . $jsonPath);
        error_log("JSON EXISTS: " . (file_exists($jsonPath) ? "YES" : "NO"));
        error_log("JSON SIZE: " . filesize($jsonPath));
    }

    private function getAccessToken(): string{
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();

        $claimset = [
            'iss' => $this->sa['client_email'],
            // necesitamos Drive y Sheets
            'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $jwt =
            $this->b64(json_encode($header)) . '.' .
            $this->b64(json_encode($claimset));

        // firmamos el JWT usando la constante correcta y validamos
        $signed = openssl_sign(
            $jwt,
            $signature,
            $this->sa['private_key'],
            OPENSSL_ALGO_SHA256
        );

        if (! $signed || empty($signature)) {
            throw new RuntimeException("Drive: openssl_sign falló (firma inválida). Verifique la private_key en el JSON de service account.");
        }

        $jwt .= '.' . $this->b64($signature);

        // pedimos token: debemos enviar application/x-www-form-urlencoded
        $postBody = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $resp = $this->curl(
            'https://oauth2.googleapis.com/token',
            $postBody,
            ['Content-Type: application/x-www-form-urlencoded']
        );

        if (!is_array($resp) || empty($resp['access_token'])) {
            throw new RuntimeException("Drive: no se obtuvo access_token del endpoint de token. Response: " . var_export($resp, true));
        }

        return $resp['access_token'];
    }

    private function b64($data){
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Realiza una petición cURL.
     * $post puede ser array (form-data) o string (application/x-www-form-urlencoded o body raw).
     */
    private function curl(string $url, $post = null, array $headers = []){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("CURL error: " . $err);
        }
        curl_close($ch);

        $decoded = json_decode($res, true);
        if ($decoded === null) {
            return $res;
        }
        return $decoded;
    }

    private function driveApi(string $method, string $url, $body = null, string $contentType = null){
        $token = $this->getAccessToken();
        $headers = [
            "Authorization: Bearer {$token}",
        ];
        if ($contentType) {
            $headers[] = "Content-Type: {$contentType}";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        switch ($method) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                break;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("Drive API error: " . $err);
        }
        curl_close($ch);

        $data = json_decode($res, true);
        // Si la respuesta no es JSON válido devolvemos la cadena cruda
        if ($data === null) return $res;

        if (isset($data['error'])) {
            // detectar storageQuotaExceeded y dar instrucción clara
            $reason = $data['error']['errors'][0]['reason'] ?? '';
            if ($reason === 'storageQuotaExceeded' || strpos($data['error']['message'] ?? '', 'Service Accounts do not have storage quota') !== false) {
                throw new Exception("Drive: storageQuotaExceeded. Los Service Accounts no tienen cuota de almacenamiento personal. Opciones:\n"
                    . "- Use una Shared Drive y añada el service account como miembro de la Shared Drive,\n"
                    . "- o use OAuth (delegación) con una cuenta de usuario.\n"
                    . "Respuesta API: " . var_export($data, true));
            }

            throw new Exception("Drive API returned error: " . var_export($data, true));
        }

        return $data;
    }

    private function findByName(string $name): ?array{
        $token = $this->getAccessToken();

       // Buscar por nombre dentro de la carpeta; ordenamos por modifiedTime y devolvemos solo el más reciente
       $url = "https://www.googleapis.com/drive/v3/files"
           . "?q=" . urlencode("name='{$name}' and '{$this->folderId}' in parents and trashed=false")
           . "&fields=files(id, md5Checksum, createdTime, modifiedTime)"
           . "&orderBy=modifiedTime desc&pageSize=1"
           // incluir items de Shared Drives
           . "&supportsAllDrives=true&includeItemsFromAllDrives=true";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        if (!isset($data['files'][0])) return null;

        return $data['files'][0];
    }

    public function upsert(string $localPath, string $remoteName): array{
        // Intentamos usar un mapping local (si existe) para evitar crear duplicados por nombre.
        $existing = null;
        $mappings = $this->loadMappings();
        if (isset($mappings[$remoteName])) {
            $mappedId = $mappings[$remoteName];
            try {
                $meta = $this->driveApi('GET', "https://www.googleapis.com/drive/v3/files/{$mappedId}?fields=id,md5Checksum&supportsAllDrives=true");
                if (is_array($meta) && isset($meta['id'])) {
                    $existing = $meta;
                }
            } catch (Exception $e) {
                // mapping inválido o borrado: lo ignoramos y buscamos por nombre
                $existing = null;
            }
        }

        if (!$existing) {
            $existing = $this->findByName($remoteName);
        }

        $localMd5 = md5_file($localPath);

        // No subir si es igual
        if ($existing && ($existing['md5Checksum'] ?? '') === $localMd5) {
            return [
                'updated' => false,
                'fileId'  => $existing['id'],
            ];
        }

        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $content = file_get_contents($localPath);

        if ($existing) {
            // UPDATE (soporte Shared Drives)
            $url = "https://www.googleapis.com/upload/drive/v3/files/{$existing['id']}?uploadType=media&supportsAllDrives=true";

            $res = $this->driveApi('PATCH', $url, $content, $mime);

            $fileId = is_array($res) ? ($res['id'] ?? $existing['id']) : $existing['id'];
            if ($fileId) $this->saveMapping($remoteName, $fileId);

            return [
                'updated' => true,
                'fileId'  => $fileId,
            ];
        }

        // CREATE
        $metadata = [
            'name'    => $remoteName,
            'parents' => [$this->folderId]
        ];

        // multipart upload
        $boundary = uniqid();
        $body =
            "--{$boundary}\r\n" .
            "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
            json_encode($metadata) . "\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: {$mime}\r\n\r\n" .
            $content . "\r\n" .
            "--{$boundary}--";

    // pedir supportsAllDrives=true para crear dentro de Shared Drive
    $url = "https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true";

    $res = $this->driveApi('POST', $url, $body, "multipart/related; boundary={$boundary}");

    $fileId = is_array($res) ? ($res['id'] ?? null) : null;
    if ($fileId) $this->saveMapping($remoteName, $fileId);

        return [
            'updated' => true,
            'fileId'  => $fileId,
        ];
    }

    // Mapping local para recordar fileId por nombre (reduce duplicados)
    private function mappingPath(): string{
        $tmp = dirname(__DIR__) . '/tmp';
        if (!is_dir($tmp)) mkdir($tmp, 0775, true);
        return $tmp . '/drive_map.json';
    }

    private function loadMappings(): array{
        $path = $this->mappingPath();
        if (!file_exists($path)) return [];
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function saveMapping(string $name, string $fileId): void{
        $m = $this->loadMappings();
        $m[$name] = $fileId;
        @file_put_contents($this->mappingPath(), json_encode($m));
    }

    // Crear un Google Spreadsheet en la carpeta si no existe y devolver su ID
    public function ensureSpreadsheet(string $remoteName): string{
        // intentar mapping
        $mappings = $this->loadMappings();
        if (isset($mappings[$remoteName])) {
            $mappedId = $mappings[$remoteName];
            try {
                $meta = $this->driveApi('GET', "https://www.googleapis.com/drive/v3/files/{$mappedId}?fields=id&supportsAllDrives=true");
                if (is_array($meta) && isset($meta['id'])) return $meta['id'];
            } catch (Exception $e) {
                // crear archivo nuevo
            }
        }

        // buscar por nombre
        $found = $this->findByName($remoteName);
        if ($found && isset($found['id'])) {
            $this->saveMapping($remoteName, $found['id']);
            return $found['id'];
        }

        // crear nuevo Google Sheet dentro de la carpeta
        $metadata = [
            'name' => $remoteName,
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
            'parents' => [$this->folderId],
        ];

        $url = "https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id";
        $res = $this->driveApi('POST', $url, json_encode($metadata), 'application/json');

        $fileId = is_array($res) ? ($res['id'] ?? null) : null;
        if ($fileId) $this->saveMapping($remoteName, $fileId);
        if (! $fileId) throw new RuntimeException("No se pudo crear el spreadsheet en Drive. Respuesta: " . var_export($res, true));

        return $fileId;
    }

    // Actualiza valores en un Google Sheet (values.update, valueInputOption=RAW)
    public function updateSheetValues(string $spreadsheetId, string $range, array $values): array{
        $token = $this->getAccessToken();
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?valueInputOption=RAW";

        $payload = json_encode(['values' => $values]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json",
            "Content-Length: " . strlen($payload),
        ]);

        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("Sheets API error: " . $err);
        }
        curl_close($ch);

        $data = json_decode($res, true);
        return $data ?? $res;
    }

    public function createOrUpdateSpreadsheetFromSheets(string $remoteName, array $sheets): array{
        $spreadsheetId = $this->ensureSpreadsheet($remoteName);


        $matrix      = []; // [fecha][campo] = valor
        $metricNames = []; // nombres de columnas (sin 'fecha')

        $dateCandidates = ['fecha', 'mes', 'date', 'dia', 'day'];

        foreach ($sheets as $title => $rows) {
            if (empty($rows)) {
                continue;
            }

            $firstRow = (array) reset($rows);
            $dateCol  = null;

            // detectar la columna de fecha
            foreach (array_keys($firstRow) as $colName) {
                if (in_array(strtolower($colName), $dateCandidates, true)) {
                    $dateCol = $colName;
                    break;
                }
            }

            foreach ($rows as $row) {
                $row = (array) $row;

                // clave de la fila (fecha/mes)
                if ($dateCol !== null && isset($row[$dateCol])) {
                    $key = $row[$dateCol];
                } else {
                    $key = 'TOTAL';
                }

                if (!isset($matrix[$key])) {
                    $matrix[$key] = ['fecha' => $key];
                }

                foreach ($row as $colName => $val) {
                    if ($colName === $dateCol) {
                        continue;
                    }

                    $metric = $colName;
                    $matrix[$key][$metric] = $val;

                    if (!in_array($metric, $metricNames, true)) {
                        $metricNames[] = $metric;
                    }
                }
            }
        }

        $keys = array_keys($matrix);
        sort($keys, SORT_STRING);

        $values = [];

        // encabezados
        $headerRow = array_merge(['fecha'], $metricNames);
        $values[] = $headerRow;

        // filas de datos
        foreach ($keys as $key) {
            $rowData = $matrix[$key];

            $row = [];
            // Columna A = fecha
            $row[] = $rowData['fecha'] ?? $key;

            foreach ($metricNames as $metric) {
                $row[] = $rowData[$metric] ?? null;
            }

            $values[] = $row;
        }

        $resp = $this->updateSheetValues($spreadsheetId, 'A1', $values);

        // Resultado
        return [
            'spreadsheetId' => $spreadsheetId,
            'sheets'        => [
                'Reporte' => [
                    'ok'   => !(is_array($resp) && isset($resp['error'])),
                    'resp' => $resp,
                ],
            ],
        ];
    }

}
