<?php
return [
    [
        'title' => 'TotalClientes',
        'sql'   => "            
            SELECT 
                DATE_FORMAT(date_add, '%Y-%m') AS mes,
                COUNT(id_customer) AS total_clientes
            FROM {{p}}customer
            WHERE date_add >= :from
            AND date_add < :to
            GROUP BY DATE_FORMAT(date_add, '%Y-%m')
            ORDER BY mes;
        ",
        // 'params' => [':from' => '2025-01-01 00:00:00', ':to' => '2026-01-01 00:00:00'], // opcional; si no, Runner pone :from/:to
    ],
    [
        'title' => 'TotalPedidos',
        'sql' => "
            SELECT 
                DATE_FORMAT(o.date_add, '%Y-%m') AS mes,
                COUNT(o.id_order) AS total_pedidos,
                ROUND(SUM(o.total_paid_tax_incl), 2) AS total_monto
            FROM {{p}}orders o
            WHERE o.valid = 1
              AND o.date_add >= :from
              AND o.date_add < :to
            GROUP BY DATE_FORMAT(o.date_add, '%Y-%m')
            ORDER BY mes;
        ",
    ]
];