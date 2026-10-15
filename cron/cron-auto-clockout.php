<?php
/**
 * CRON AUTO CLOCK-OUT
 *
 * Este script debe ejecutarse cada minuto vía CRON.
 * Revisa todos los usuarios con auto clock-out activado y los ficha automáticamente.
 *
 * Configuración CRON en Plesk:
 * * * * * * php /var/www/vhosts/tudominio/httpdocs/cron-auto-clockout.php
 *
 * O desde la interfaz de Plesk:
 * - Herramientas y configuración → Tareas programadas
 * - Añadir tarea: cada 1 minuto
 * - Comando: php /ruta/completa/cron-auto-clockout.php
 */

// Evitar ejecución desde navegador
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    // Permitir ejecución web solo con clave secreta (para testing)
    if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== 'TU_CLAVE_SECRETA_AQUI_12345') {
        die('Acceso no permitido. Este script solo puede ejecutarse vía CRON.');
    }
}

// Para ejecución web (testing), mostrar como texto
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once dirname(__DIR__) . '/app/config.php';
require_once dirname(__DIR__) . '/app/JibbleAPI.php';
require_once dirname(__DIR__) . '/app/auditoria_helper.php';

// Log helper
function cronLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    echo $logMessage . "\n";

    $logFile = dirname(__DIR__) . '/logs/auto-clockout.log';

    // Si supera 15 MB, borrar y empezar de cero
    if (file_exists($logFile) && filesize($logFile) > 15 * 1024 * 1024) {
        unlink($logFile);
    }

    file_put_contents($logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
}

cronLog("=== INICIO CRON AUTO CLOCK-OUT ===");

try {
    $pdo = getDBConnection();
    // Historial de cambios: las salidas que cree este cron constan como "Sistema"
    auditoriaContextoSistema($pdo, 'cron-auto-clockout');
    $jibble = new JibbleAPI();
    $horaActual = date('H:i');
    $fechaHoy = date('Y-m-d');

    cronLog("Hora actual: $horaActual");

    // ============================================
    // 1. OBTENER USUARIOS CON AUTO CLOCK-OUT ACTIVO
    // ============================================
    $stmt = $pdo->query("
        SELECT
            u.id as usuario_id,
            u.jibble_person_id,
            u.nombre,
            us_enabled.setting_value as enabled,
            us_time.setting_value as clockout_time,
            us_lat.setting_value as saved_latitude,
            us_lon.setting_value as saved_longitude
        FROM usuarios u
        INNER JOIN user_settings us_enabled
            ON u.id = us_enabled.usuario_id
            AND us_enabled.setting_key = 'auto_clockout_enabled'
            AND us_enabled.setting_value = '1'
        LEFT JOIN user_settings us_time
            ON u.id = us_time.usuario_id
            AND us_time.setting_key = 'auto_clockout_time'
        LEFT JOIN user_settings us_lat
            ON u.id = us_lat.usuario_id
            AND us_lat.setting_key = 'auto_clockout_latitude'
        LEFT JOIN user_settings us_lon
            ON u.id = us_lon.usuario_id
            AND us_lon.setting_key = 'auto_clockout_longitude'
        WHERE u.activo = 1
    ");

    $usuarios = $stmt->fetchAll();

    if (empty($usuarios)) {
        cronLog("No hay usuarios con auto clock-out activado.");
        cronLog("=== FIN CRON ===\n");
        exit;
    }

    cronLog("Usuarios con auto clock-out: " . count($usuarios));

    // ============================================
    // 2. PROCESAR CADA USUARIO
    // ============================================
    foreach ($usuarios as $user) {
        $userId = $user['usuario_id'];
        $personId = $user['jibble_person_id'];
        $nombre = $user['nombre'];
        $clockoutTime = $user['clockout_time'] ?? '18:00';
        $savedLat = $user['saved_latitude'] ?? null;
        $savedLon = $user['saved_longitude'] ?? null;

        cronLog("---");
        cronLog("Procesando: $nombre (ID: $userId)");
        cronLog("  Hora configurada: $clockoutTime");

        // ¿Es hora de fichar?
        if ($horaActual < $clockoutTime) {
            cronLog("  ⏳ Aún no es hora (actual: $horaActual < configurada: $clockoutTime)");
            continue;
        }

        // ============================================
        // 3. VERIFICAR ESTADO ACTUAL (última entrada vs última salida)
        // ============================================
        $stmt = $pdo->prepare("
            SELECT tipo, timestamp_unix
            FROM fichajes
            WHERE usuario_id = ?
            AND DATE(fecha_hora) = CURDATE()
            AND es_descanso = 0
            ORDER BY fecha_hora DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $ultimoFichaje = $stmt->fetch();

        // Si el último fichaje del día es una salida, ya está fichado
        if ($ultimoFichaje && $ultimoFichaje['tipo'] === 'Out') {
            cronLog("  ✓ Último fichaje es salida, saltando.");
            continue;
        }

        // Si no hay fichajes hoy, saltar
        if (!$ultimoFichaje) {
            cronLog("  ℹ No hay fichajes hoy, saltando.");
            continue;
        }

        // ============================================
        // 4. OBTENER DATOS DE LA ÚLTIMA ENTRADA
        // ============================================
        $stmt = $pdo->prepare("
            SELECT id, timestamp_unix, latitud, longitud
            FROM fichajes
            WHERE usuario_id = ?
            AND tipo = 'In'
            AND DATE(fecha_hora) = CURDATE()
            AND es_descanso = 0
            ORDER BY fecha_hora DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $ultimaEntrada = $stmt->fetch();

        if (!$ultimaEntrada) {
            cronLog("  ⚠ No se encontró la entrada, saltando.");
            continue;
        }

        // ============================================
        // 5. DETERMINAR SI ES USUARIO MANUAL O JIBBLE
        // ============================================
        // El estado ya se verificó en el paso 3 desde la BD (más fiable que Jibble)
        $esManual = (strpos($personId, 'manual_') === 0);

        // ============================================
        // 6. EJECUTAR CLOCK-OUT
        // ============================================
        cronLog("  🔄 Ejecutando clock-out automático...");

        try {
            try {
                $result = $esManual ? true : $jibble->clockOut($personId);
            } catch (Exception $e) {
                cronLog("  ⚠ Jibble clockOut error (se registra salida en BD igualmente): " . $e->getMessage());
                $result = true; // Insertar salida en BD aunque Jibble falle
            }

            if ($result) {
                $timestamp = time();
                $fechaHora = date('Y-m-d H:i:s');

                // Calcular horas trabajadas
                $segundos = $timestamp - $ultimaEntrada['timestamp_unix'];
                $minutosTrabajados = floor($segundos / 60);
                $horasTrabajadas = round($minutosTrabajados / 60, 2);

                // Actualizar entrada con horas trabajadas
                $stmt = $pdo->prepare("
                    UPDATE fichajes
                    SET horas_trabajadas = ?, minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$horasTrabajadas, $minutosTrabajados, $ultimaEntrada['id']]);

                // Usar ubicación guardada o la de la última entrada
                $latitud = $savedLat ?? $ultimaEntrada['latitud'] ?? null;
                $longitud = $savedLon ?? $ultimaEntrada['longitud'] ?? null;

                // Insertar fichaje de salida
                $stmt = $pdo->prepare("
                    INSERT INTO fichajes (
                        usuario_id, jibble_person_id, nombre, tipo,
                        fecha_hora, timestamp_unix, nota, dispositivo,
                        fichaje_entrada_id, horas_trabajadas, minutos_trabajados,
                        sincronizado_jibble, auto_clockout,
                        latitud, longitud, ubicacion_validada
                    ) VALUES (
                        ?, ?, ?, 'Out',
                        ?, ?, 'Auto Clock-Out (CRON)', 'Sistema CRON',
                        ?, ?, ?,
                        ?, 1,
                        ?, ?, 1
                    )
                ");
                $stmt->execute([
                    $userId, $personId, $nombre,
                    $fechaHora, $timestamp,
                    $ultimaEntrada['id'], $horasTrabajadas, $minutosTrabajados,
                    $esManual ? 0 : 1,
                    $latitud, $longitud
                ]);

                // Actualizar resumen diario
                actualizarResumenDiarioCron($pdo, $userId, $personId, $nombre, $fechaHoy);

                // Log en sync_log
                $stmt = $pdo->prepare("
                    INSERT INTO sync_log (tipo, mensaje, success)
                    VALUES ('auto_clockout_cron', ?, 1)
                ");
                $stmt->execute(["Auto clock-out ejecutado para $nombre a las $fechaHora"]);

                cronLog("  ✅ Clock-out exitoso! Horas: $horasTrabajadas");

            } else {
                cronLog("  ❌ Error: Jibble no respondió correctamente");
            }

        } catch (Exception $e) {
            cronLog("  ❌ Error ejecutando clock-out: " . $e->getMessage());

            // Log del error
            $stmt = $pdo->prepare("
                INSERT INTO sync_log (tipo, mensaje, success)
                VALUES ('auto_clockout_cron', ?, 0)
            ");
            $stmt->execute(["Error auto clock-out para $nombre: " . $e->getMessage()]);
        }
    }

    cronLog("=== FIN CRON ===\n");

} catch (Exception $e) {
    cronLog("❌ ERROR FATAL: " . $e->getMessage());
    exit(1);
}

/**
 * Función auxiliar para actualizar resumen diario (sin sesión)
 */
function actualizarResumenDiarioCron($pdo, $userId, $personId, $nombre, $fecha) {
    try {
        // Calcular totales del día
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN tipo = 'In' AND horas_trabajadas IS NOT NULL THEN minutos_trabajados ELSE 0 END) as total_minutos
            FROM fichajes
            WHERE usuario_id = ?
            AND DATE(fecha_hora) = ?
            AND es_descanso = 0
        ");
        $stmt->execute([$userId, $fecha]);
        $totales = $stmt->fetch();

        $totalMinutos = $totales['total_minutos'] ?? 0;
        $totalHoras = round($totalMinutos / 60, 2);

        // Actualizar o insertar resumen
        $stmt = $pdo->prepare("
            INSERT INTO resumen_diario (usuario_id, jibble_person_id, nombre, fecha, total_horas, total_minutos)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_horas = VALUES(total_horas),
                total_minutos = VALUES(total_minutos),
                fecha_actualizacion = NOW()
        ");
        $stmt->execute([$userId, $personId, $nombre, $fecha, $totalHoras, $totalMinutos]);

    } catch (Exception $e) {
        cronLog("  ⚠ Error actualizando resumen: " . $e->getMessage());
    }
}
