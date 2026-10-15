<?php
/**
 * reportes.php - Script de ejemplo para generar reportes
 * 
 * Este archivo muestra cómo crear reportes personalizados usando los datos
 * almacenados en la base de datos.
 */

require_once __DIR__ . '/config.php';

// Configurar zona horaria
date_default_timezone_set('Europe/Madrid');

/**
 * Genera reporte de horas trabajadas del mes actual
 */
function reporteMensual() {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("
        SELECT 
            u.nombre,
            u.email,
            SUM(f.total_horas) as horas_mes,
            SUM(f.total_minutos) as minutos_mes,
            COUNT(DISTINCT f.fecha) as dias_trabajados,
            ROUND(AVG(f.total_horas), 2) as promedio_diario,
            MIN(f.primer_entrada) as entrada_mas_temprana,
            MAX(f.ultima_salida) as salida_mas_tarde
        FROM usuarios u
        LEFT JOIN fichajes_resumen_diario f ON u.id = f.usuario_id
        WHERE u.activo = 1
        AND YEAR(f.fecha) = YEAR(CURDATE())
        AND MONTH(f.fecha) = MONTH(CURDATE())
        GROUP BY u.id, u.nombre, u.email
        ORDER BY horas_mes DESC
    ");
    
    $resultados = $stmt->fetchAll();
    
    return [
        'titulo' => 'Reporte Mensual - ' . date('F Y'),
        'fecha_generacion' => date('Y-m-d H:i:s'),
        'mes' => date('Y-m'),
        'empleados' => $resultados
    ];
}

/**
 * Genera reporte de empleados actualmente fichados
 */
function reporteEmpleadosFichados() {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("
        SELECT 
            u.nombre,
            u.email,
            f.primer_entrada,
            f.total_horas as horas_hoy,
            TIME_TO_SEC(TIMEDIFF(NOW(), CONCAT(CURDATE(), ' ', f.primer_entrada))) / 3600 as horas_transcurridas
        FROM usuarios u
        INNER JOIN fichajes_resumen_diario f ON u.id = f.usuario_id
        WHERE f.fecha = CURDATE()
        AND f.actualmente_fichado = 1
        AND u.activo = 1
        ORDER BY f.primer_entrada
    ");
    
    $empleados = $stmt->fetchAll();
    
    return [
        'titulo' => 'Empleados Actualmente Fichados',
        'fecha' => date('Y-m-d'),
        'hora_reporte' => date('H:i:s'),
        'total_fichados' => count($empleados),
        'empleados' => $empleados
    ];
}

/**
 * Genera reporte de fichajes incompletos
 */
function reporteFichajesIncompletos() {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("
        SELECT 
            u.nombre,
            DATE(f.fecha_hora) as fecha,
            TIME(f.fecha_hora) as hora_entrada,
            DATEDIFF(CURDATE(), DATE(f.fecha_hora)) as dias_antiguedad
        FROM usuarios u
        INNER JOIN fichajes f ON u.id = f.usuario_id
        WHERE f.tipo = 'In'
        AND NOT EXISTS (
            SELECT 1 FROM fichajes f2 WHERE f2.fichaje_entrada_id = f.id
        )
        AND DATE(f.fecha_hora) < CURDATE()
        ORDER BY f.fecha_hora DESC
        LIMIT 50
    ");
    
    $fichajes = $stmt->fetchAll();
    
    return [
        'titulo' => 'Fichajes Incompletos (Entrada sin Salida)',
        'fecha_reporte' => date('Y-m-d H:i:s'),
        'total_incompletos' => count($fichajes),
        'fichajes' => $fichajes
    ];
}

/**
 * Genera reporte de comparación semanal
 */
function reporteComparacionSemanal() {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("
        SELECT 
            u.nombre,
            -- Semana actual
            SUM(CASE WHEN YEARWEEK(f.fecha) = YEARWEEK(CURDATE()) THEN f.total_horas ELSE 0 END) as horas_semana_actual,
            COUNT(DISTINCT CASE WHEN YEARWEEK(f.fecha) = YEARWEEK(CURDATE()) THEN f.fecha END) as dias_semana_actual,
            -- Semana anterior
            SUM(CASE WHEN YEARWEEK(f.fecha) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK)) THEN f.total_horas ELSE 0 END) as horas_semana_anterior,
            COUNT(DISTINCT CASE WHEN YEARWEEK(f.fecha) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK)) THEN f.fecha END) as dias_semana_anterior,
            -- Diferencia
            SUM(CASE WHEN YEARWEEK(f.fecha) = YEARWEEK(CURDATE()) THEN f.total_horas ELSE 0 END) -
            SUM(CASE WHEN YEARWEEK(f.fecha) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK)) THEN f.total_horas ELSE 0 END) as diferencia_horas
        FROM usuarios u
        LEFT JOIN fichajes_resumen_diario f ON u.id = f.usuario_id
        WHERE u.activo = 1
        AND YEARWEEK(f.fecha) >= YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK))
        GROUP BY u.id, u.nombre
        ORDER BY diferencia_horas DESC
    ");
    
    $comparacion = $stmt->fetchAll();
    
    return [
        'titulo' => 'Comparación Semanal',
        'semana_actual' => date('W', strtotime('this week')),
        'semana_anterior' => date('W', strtotime('last week')),
        'empleados' => $comparacion
    ];
}

/**
 * Genera reporte de productividad (análisis de patrones)
 */
function reporteProductividad($fechaDesde = null, $fechaHasta = null) {
    $pdo = getDBConnection();
    
    if (!$fechaDesde) $fechaDesde = date('Y-m-01'); // Primer día del mes
    if (!$fechaHasta) $fechaHasta = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT 
            u.nombre,
            -- Horas trabajadas
            SUM(f.total_horas) as total_horas,
            AVG(f.total_horas) as promedio_horas_dia,
            -- Asistencia
            COUNT(DISTINCT f.fecha) as dias_trabajados,
            -- Puntualidad (entradas antes de las 9:00)
            SUM(CASE WHEN f.primer_entrada <= '09:00:00' THEN 1 ELSE 0 END) as dias_puntuales,
            -- Horas extra (días con más de 8 horas)
            SUM(CASE WHEN f.total_horas > 8 THEN 1 ELSE 0 END) as dias_con_extras,
            -- Entradas múltiples
            AVG(f.num_entradas) as promedio_entradas_dia
        FROM usuarios u
        LEFT JOIN fichajes_resumen_diario f ON u.id = f.usuario_id
        WHERE u.activo = 1
        AND f.fecha BETWEEN ? AND ?
        GROUP BY u.id, u.nombre
        ORDER BY total_horas DESC
    ");
    
    $stmt->execute([$fechaDesde, $fechaHasta]);
    $analisis = $stmt->fetchAll();
    
    return [
        'titulo' => 'Reporte de Productividad',
        'periodo' => [
            'desde' => $fechaDesde,
            'hasta' => $fechaHasta
        ],
        'analisis' => $analisis
    ];
}

/**
 * Genera reporte de top performers
 */
function reporteTopPerformers($limite = 5) {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            u.nombre,
            SUM(f.total_horas) as horas_mes,
            COUNT(DISTINCT f.fecha) as dias_trabajados,
            ROUND(SUM(f.total_horas) / COUNT(DISTINCT f.fecha), 2) as promedio_diario,
            MIN(f.primer_entrada) as entrada_promedio
        FROM usuarios u
        INNER JOIN fichajes_resumen_diario f ON u.id = f.usuario_id
        WHERE YEAR(f.fecha) = YEAR(CURDATE())
        AND MONTH(f.fecha) = MONTH(CURDATE())
        AND u.activo = 1
        GROUP BY u.id, u.nombre
        ORDER BY horas_mes DESC
        LIMIT ?
    ");
    
    $stmt->execute([$limite]);
    $top = $stmt->fetchAll();
    
    return [
        'titulo' => 'Top ' . $limite . ' Empleados del Mes',
        'mes' => date('F Y'),
        'top_performers' => $top
    ];
}

/**
 * Exporta reporte a formato HTML
 */
function exportarHTML($reporte) {
    $html = "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$reporte['titulo']}</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #333; 
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0;
        }
        th { 
            background: #4CAF50; 
            color: white; 
            padding: 12px;
            text-align: left;
        }
        td { 
            padding: 10px; 
            border-bottom: 1px solid #ddd;
        }
        tr:hover { background: #f5f5f5; }
        .numero { text-align: right; font-weight: bold; }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>{$reporte['titulo']}</h1>";
    
    if (isset($reporte['fecha_generacion'])) {
        $html .= "<div class='info'>Generado el: {$reporte['fecha_generacion']}</div>";
    }
    
    // Convertir datos a tabla HTML
    if (isset($reporte['empleados']) && count($reporte['empleados']) > 0) {
        $html .= "<table><thead><tr>";
        
        // Headers
        foreach (array_keys($reporte['empleados'][0]) as $header) {
            $html .= "<th>" . ucfirst(str_replace('_', ' ', $header)) . "</th>";
        }
        $html .= "</tr></thead><tbody>";
        
        // Rows
        foreach ($reporte['empleados'] as $row) {
            $html .= "<tr>";
            foreach ($row as $key => $value) {
                $class = is_numeric($value) ? 'numero' : '';
                $valor = is_numeric($value) && !in_array($key, ['id']) ? round($value, 2) : $value;
                $html .= "<td class='$class'>" . htmlspecialchars($valor ?? '-') . "</td>";
            }
            $html .= "</tr>";
        }
        
        $html .= "</tbody></table>";
    }
    
    $html .= "
        <div class='footer'>
            Sistema de Control de Fichajes - " . date('Y') . "
        </div>
    </div>
</body>
</html>";
    
    return $html;
}

/**
 * Exporta reporte a formato JSON
 */
function exportarJSON($reporte) {
    return json_encode($reporte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Exporta reporte a formato CSV
 */
function exportarCSV($reporte) {
    if (!isset($reporte['empleados']) || count($reporte['empleados']) === 0) {
        return "No hay datos para exportar";
    }
    
    $csv = "";
    
    // Headers
    $headers = array_keys($reporte['empleados'][0]);
    $csv .= implode(',', array_map(function($h) {
        return '"' . str_replace('"', '""', ucfirst(str_replace('_', ' ', $h))) . '"';
    }, $headers)) . "\n";
    
    // Rows
    foreach ($reporte['empleados'] as $row) {
        $csv .= implode(',', array_map(function($v) {
            return '"' . str_replace('"', '""', $v ?? '') . '"';
        }, $row)) . "\n";
    }
    
    return $csv;
}

// ============================================
// EJEMPLOS DE USO
// ============================================

// Si se ejecuta directamente (no como include)
if (php_sapi_name() === 'cli' || basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    
    echo "=== SISTEMA DE REPORTES ===\n\n";
    
    // Generar reporte mensual
    echo "Generando reporte mensual...\n";
    $reporteMensual = reporteMensual();
    echo "Total empleados: " . count($reporteMensual['empleados']) . "\n";
    
    // Generar reporte de fichados
    echo "\nGenerando reporte de empleados fichados...\n";
    $reporteFichados = reporteEmpleadosFichados();
    echo "Empleados fichados: " . $reporteFichados['total_fichados'] . "\n";
    
    // Generar reporte de incompletos
    echo "\nGenerando reporte de fichajes incompletos...\n";
    $reporteIncompletos = reporteFichajesIncompletos();
    echo "Fichajes incompletos: " . $reporteIncompletos['total_incompletos'] . "\n";
    
    // Exportar a HTML
    echo "\nExportando reporte mensual a HTML...\n";
    $html = exportarHTML($reporteMensual);
    file_put_contents(dirname(__DIR__) . '/logs/reporte_mensual_' . date('Y-m') . '.html', $html);
    echo "Guardado como: reporte_mensual_" . date('Y-m') . ".html\n";
    
    // Exportar a JSON
    echo "\nExportando reporte mensual a JSON...\n";
    $json = exportarJSON($reporteMensual);
    file_put_contents(dirname(__DIR__) . '/logs/reporte_mensual_' . date('Y-m') . '.json', $json);
    echo "Guardado como: reporte_mensual_" . date('Y-m') . ".json\n";
    
    // Exportar a CSV
    echo "\nExportando reporte mensual a CSV...\n";
    $csv = exportarCSV($reporteMensual);
    file_put_contents(dirname(__DIR__) . '/logs/reporte_mensual_' . date('Y-m') . '.csv', $csv);
    echo "Guardado como: reporte_mensual_" . date('Y-m') . ".csv\n";
    
    echo "\n¡Reportes generados exitosamente!\n";
}
?>