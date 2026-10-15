<?php
// get-sync-logs.php - Obtener historial de sincronizaciones
require_once dirname(__DIR__) . '/app/config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDBConnection();
    
    // Obtener últimos 50 logs
    $stmt = $pdo->prepare("
        SELECT 
            id,
            tipo,
            mensaje,
            usuarios_nuevos,
            usuarios_actualizados,
            usuarios_desactivados,
            success,
            fecha
        FROM sync_log 
        ORDER BY fecha DESC
        LIMIT 50
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => count($logs)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener logs: ' . $e->getMessage()
    ]);
}