<?php

class Excel{
    public static function saveMultiSheet(array $sheets, string $path): void{
        require_once '../PHPExcel/Classes/PHPExcel.php';

        $excel = new PHPExcel();
        $sheet = $excel->getActiveSheet();
        $sheet->setTitle('Reporte');

        // Unificamos por fecha
        $matrix       = []; // [fecha][columna] = valor
        $metricNames  = []; // orden de columnas (sin fecha)

        $dateCandidates = ['fecha', 'mes', 'date', 'dia', 'day'];

        foreach ($sheets as $title => $rows) {
            if (empty($rows)) {
                continue;
            }

            // Detectar la columna de fecha (si existe)
            $firstRow  = (array)reset($rows);
            $dateCol   = null;
            foreach (array_keys($firstRow) as $colName) {
                if (in_array(strtolower($colName), $dateCandidates, true)) {
                    $dateCol = $colName;
                    break;
                }
            }

            foreach ($rows as $row) {
                $row = (array)$row;

                if ($dateCol !== null && isset($row[$dateCol])) {
                    $key = $row[$dateCol];
                } else {
                    // Si la query no trae fecha, usamos 'TOTAL' como "fecha"
                    $key = 'TOTAL';
                }

                if (!isset($matrix[$key])) {
                    $matrix[$key] = ['fecha' => $key];
                }

                // Cada columna distinta de la fecha se considera métrica
                foreach ($row as $colName => $val) {
                    if ($colName === $dateCol) {
                        continue;
                    }

                    // Usamos el nombre tal cual viene de la query
                    $metric = $colName;

                    $matrix[$key][$metric] = $val;

                    if (!in_array($metric, $metricNames, true)) {
                        $metricNames[] = $metric;
                    }
                }
            }
        }

        // Ordenar por fecha (clave)
        $keys = array_keys($matrix);
        sort($keys, SORT_STRING);

        // Escribir encabezados: A = fecha, luego cada métrica
        $col = 0; // 1 = columna A
        $sheet->setCellValueByColumnAndRow($col, 1, 'fecha');
        $sheet->getStyleByColumnAndRow($col, 1)->getFont()->setBold(true);
        $col++;

        foreach ($metricNames as $metric) {
            $sheet->setCellValueByColumnAndRow($col, 1, $metric);
            $sheet->getStyleByColumnAndRow($col, 1)->getFont()->setBold(true);
            $col++;
        }

        // Escribir filas
        $rowIndex = 2;
        foreach ($keys as $key) {
            $rowData = $matrix[$key];

            // Columna A: fecha
            $sheet->setCellValueByColumnAndRow(0, $rowIndex, $rowData['fecha'] ?? $key);

            // Métricas
            $colIndex = 1; // desde A
            foreach ($metricNames as $metric) {
                $val = $rowData[$metric] ?? null;
                $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $val);
                $colIndex++;
            }

            $rowIndex++;
        }

        // Autosize columnas
        $totalCols = 1 + count($metricNames);
        for ($c = 1; $c <= $totalCols; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        // Guardar
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $writer->save($path);

        $excel->disconnectWorksheets();
        unset($excel);
    }
}