<?php
/**
 * CRON FINALIZAR AUSENCIAS
 * 
 * Este script debe ejecutarse una vez al día (recomendado: 00:01).
 * Finaliza automáticamente las ausencias cuya fecha_fin ya haya pasado.
 * 
 * Configuración CRON en Plesk:
 * 1 0 * * * php /var/www/vhosts/tudominio/httpdocs/cron-finalizar-ausencias.php
 * 
 * O desde la interfaz de Plesk:
 * - Herramientas y configuración → Tareas programadas
 * - Añadir tarea: Diaria a las 00:01
 * - Comando: php /ruta/completa/cron-finalizar-ausencias.php
 */

// Evitar ejecución desde navegador
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    // Permitir ejecución web solo con clave secreta (para testing)
    if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== 'AusenciasCron_2026_FichajesOficia_98765') {
        die('Acceso no permitido. Este script solo puede ejecutarse vía CRON.');
    }
}

// Para ejecución web (testing), mostrar como texto
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/app/config.php';

// Log helper
function cronLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    echo $logMessage;
    
    // También guardar en archivo de log
    $logFile = dirname(__DIR__) . '/logs/finalizar-ausencias.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

cronLog("=== INICIO CRON FINALIZAR AUSENCIAS ===");

try {
    $pdo = getDBConnection();
    $fechaHoy = date('Y-m-d');
    
    cronLog("Fecha actual: $fechaHoy");
    
    // ============================================
    // 1. BUSCAR AUSENCIAS QUE DEBEN FINALIZARSE
    // ============================================
    $stmt = $pdo->query("
        SELECT 
            id, usuario_id, nombre, tipo,
            fecha_inicio, fecha_fin, motivo
        FROM ausencias
        WHERE estado = 'activa'
        AND fecha_fin IS NOT NULL
        AND fecha_fin < CURDATE()
    ");
    
    $ausencias = $stmt->fetchAll();
    
    if (empty($ausencias)) {
        cronLog("No hay ausencias para finalizar.");
        cronLog("=== FIN CRON ===\n");
        exit;
    }
    
    cronLog("Ausencias encontradas para finalizar: " . count($ausencias));
    
    // ============================================
    // 2. FINALIZAR CADA AUSENCIA
    // ============================================
    $finalizadas = 0;
    $errores = 0;
    
    foreach ($ausencias as $ausencia) {
        $id = $ausencia['id'];
        $nombre = $ausencia['nombre'];
        $tipo = $ausencia['tipo'];
        $fechaFin = $ausencia['fecha_fin'];
        
        cronLog("---");
        cronLog("Procesando: $nombre (ID: $id)");
        cronLog("  Tipo: $tipo");
        cronLog("  Fecha fin: $fechaFin");
        
        try {
            // Finalizar ausencia
            $stmt = $pdo->prepare("
                UPDATE ausencias 
                SET estado = 'finalizada',
                    fecha_actualizacion = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            // Log del resultado
            $stmt = $pdo->prepare("
                INSERT INTO sync_log (tipo, mensaje, success)
                VALUES ('cron_ausencias', ?, 1)
            ");
            $logMsg = sprintf(
                "Ausencia finalizada automáticamente - Usuario: %s, Tipo: %s, Fecha fin: %s",
                $nombre,
                $tipo,
                $fechaFin
            );
            $stmt->execute([$logMsg]);
            
            $finalizadas++;
            cronLog("  ✅ Ausencia finalizada correctamente");
            
        } catch (Exception $e) {
            cronLog("  ❌ Error finalizando ausencia: " . $e->getMessage());
            
            // Log del error
            $stmt = $pdo->prepare("
                INSERT INTO sync_log (tipo, mensaje, success)
                VALUES ('cron_ausencias', ?, 0)
            ");
            $stmt->execute(["Error finalizando ausencia ID $id: " . $e->getMessage()]);
            
            $errores++;
        }
    }
    
    cronLog("---");
    cronLog("Resumen: ✅ Finalizadas: $finalizadas");
    if ($errores > 0) {
        cronLog("         ❌ Errores: $errores");
    }
    cronLog("=== FIN CRON ===\n");
    
} catch (Exception $e) {
    cronLog("❌ ERROR FATAL: " . $e->getMessage());
    
    // Log del error fatal
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, success)
            VALUES ('cron_ausencias', ?, 0)
        ");
        $stmt->execute(["Error fatal en CRON: " . $e->getMessage()]);
    } catch (Exception $logError) {
        // Si no se puede loguear, al menos se quedará en el archivo
    }
    
    exit(1);
}
?>
