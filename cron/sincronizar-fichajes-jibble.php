<?php
/**
 * sincronizar-fichajes-jibble.php
 * 
 * Este script sincroniza todos los fichajes históricos desde Jibble
 * y los guarda en tu base de datos local.
 * 
 * USO:
 * - Desde línea de comandos: php sincronizar-fichajes-jibble.php
 * - Desde navegador: http://localhost/tu-carpeta/sincronizar-fichajes-jibble.php
 * - Con parámetros: ?dias=30 (para sincronizar últimos 30 días)
 */

require_once dirname(__DIR__) . '/app/config.php';
require_once dirname(__DIR__) . '/app/JibbleAPI.php';

// Configurar zona horaria
date_default_timezone_set('Europe/Madrid');

// Parámetros configurables
$diasAtras = isset($_GET['dias']) ? intval($_GET['dias']) : 90; // Por defecto últimos 90 días
$sobreescribir = isset($_GET['sobreescribir']) ? true : false; // Si true, sobreescribe fichajes existentes

/**
 * Obtiene todos los fichajes de un empleado en un rango de fechas
 */
function obtenerFichajesJibble($personId, $fechaDesde, $fechaHasta) {
    $jibble = new JibbleAPI();
    
    try {
        // Formatear fechas para la API de Jibble
        $from = date('Y-m-d', strtotime($fechaDesde)) . 'T00:00:00Z';
        $to = date('Y-m-d', strtotime($fechaHasta)) . 'T23:59:59Z';
        
        // Construir URL con filtros
        $url = 'https://time-tracking.prod.jibble.io/v1/TimeEntries'
             . '?$filter=personId eq ' . $personId
             . ' and time ge ' . $from
             . ' and time le ' . $to
             . '&$orderby=time asc'
             . '&$top=1000'; // Máximo de registros
        
        // Hacer la petición
        $token = obtenerAccessToken();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error al obtener fichajes de Jibble: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        return $data['value'] ?? [];
        
    } catch (Exception $e) {
        echo "⚠️ Error para persona $personId: " . $e->getMessage() . "\n";
        return [];
    }
}

/**
 * Obtiene el access token de Jibble
 */
function obtenerAccessToken() {
    static $token = null;
    
    if ($token) {
        return $token;
    }
    
    $ch = curl_init('https://identity.prod.jibble.io/connect/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 
        'grant_type=client_credentials&client_id=' . JIBBLE_API_ID .
        '&client_secret=' . JIBBLE_API_SECRET);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to obtain OAuth token");
    }
    
    $json = json_decode($response, true);
    $token = $json['access_token'];
    return $token;
}

/**
 * Guarda un fichaje en la base de datos local
 */
function guardarFichaje($pdo, $usuario, $fichaje, $sobreescribir) {
    try {
        // Parsear fecha y hora
        $fechaHora = new DateTime($fichaje['time']);
        $fechaHoraStr = $fechaHora->format('Y-m-d H:i:s');
        $timestamp = $fechaHora->getTimestamp();
        $tipo = $fichaje['type'] ?? 'In';
        $nota = $fichaje['note'] ?? null;
        
        // Verificar si ya existe este fichaje
        $stmt = $pdo->prepare("
            SELECT id FROM fichajes 
            WHERE usuario_id = ? 
            AND timestamp_unix = ? 
            AND tipo = ?
        ");
        $stmt->execute([$usuario['id'], $timestamp, $tipo]);
        $existe = $stmt->fetch();
        
        if ($existe && !$sobreescribir) {
            return ['accion' => 'omitido', 'id' => $existe['id']];
        }
        
        if ($existe && $sobreescribir) {
            // Actualizar fichaje existente
            $stmt = $pdo->prepare("
                UPDATE fichajes SET
                    nota = ?,
                    fecha_hora = ?,
                    sincronizado_jibble = 1
                WHERE id = ?
            ");
            $stmt->execute([$nota, $fechaHoraStr, $existe['id']]);
            return ['accion' => 'actualizado', 'id' => $existe['id']];
        }
        
        // Insertar nuevo fichaje
        $stmt = $pdo->prepare("
            INSERT INTO fichajes (
                usuario_id, jibble_person_id, nombre, tipo,
                fecha_hora, timestamp_unix, nota, dispositivo,
                sincronizado_jibble
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Jibble Sync', 1)
        ");
        
        $stmt->execute([
            $usuario['id'],
            $usuario['jibble_person_id'],
            $usuario['nombre'],
            $tipo,
            $fechaHoraStr,
            $timestamp,
            $nota
        ]);
        
        $fichajeId = $pdo->lastInsertId();
        return ['accion' => 'insertado', 'id' => $fichajeId];
        
    } catch (Exception $e) {
        return ['accion' => 'error', 'mensaje' => $e->getMessage()];
    }
}

/**
 * Calcula horas trabajadas y relaciona entradas con salidas
 */
function calcularHorasYRelaciones($pdo, $usuarioId, $fecha) {
    try {
        // Obtener todos los fichajes del día ordenados por hora
        $stmt = $pdo->prepare("
            SELECT id, tipo, timestamp_unix
            FROM fichajes
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) = ?
            ORDER BY timestamp_unix ASC
        ");
        $stmt->execute([$usuarioId, $fecha]);
        $fichajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Emparejar entradas con salidas
        $entradaActual = null;
        $parejasCreadas = 0;
        
        foreach ($fichajes as $fichaje) {
            if ($fichaje['tipo'] === 'In') {
                $entradaActual = $fichaje;
            } elseif ($fichaje['tipo'] === 'Out' && $entradaActual) {
                // Calcular tiempo trabajado
                $segundos = $fichaje['timestamp_unix'] - $entradaActual['timestamp_unix'];
                $minutos = floor($segundos / 60);
                $horas = round($minutos / 60, 2);
                
                // Actualizar la salida con la relación y horas
                $stmt = $pdo->prepare("
                    UPDATE fichajes SET
                        fichaje_entrada_id = ?,
                        horas_trabajadas = ?,
                        minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $entradaActual['id'],
                    $horas,
                    $minutos,
                    $fichaje['id']
                ]);
                
                // Actualizar también la entrada con las horas
                $stmt = $pdo->prepare("
                    UPDATE fichajes SET
                        horas_trabajadas = ?,
                        minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $horas,
                    $minutos,
                    $entradaActual['id']
                ]);
                
                $parejasCreadas++;
                $entradaActual = null; // Resetear para la próxima pareja
            }
        }
        
        return $parejasCreadas;
        
    } catch (Exception $e) {
        echo "⚠️ Error calculando horas para usuario $usuarioId fecha $fecha: " . $e->getMessage() . "\n";
        return 0;
    }
}

/**
 * Actualiza el resumen diario después de sincronizar
 */
function actualizarResumenDiario($pdo, $usuarioId, $personId, $nombre, $fecha) {
    try {
        // Obtener estadísticas del día
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN tipo = 'In' THEN 1 END) as num_entradas,
                COUNT(CASE WHEN tipo = 'Out' THEN 1 END) as num_salidas,
                MIN(CASE WHEN tipo = 'In' THEN TIME(fecha_hora) END) as primer_entrada,
                MAX(CASE WHEN tipo = 'Out' THEN TIME(fecha_hora) END) as ultima_salida,
                SUM(CASE WHEN tipo = 'Out' THEN COALESCE(horas_trabajadas, 0) ELSE 0 END) as total_horas,
                SUM(CASE WHEN tipo = 'Out' THEN COALESCE(minutos_trabajados, 0) ELSE 0 END) as total_minutos
            FROM fichajes
            WHERE usuario_id = ? AND DATE(fecha_hora) = ?
        ");
        $stmt->execute([$usuarioId, $fecha]);
        $stats = $stmt->fetch();
        
        // Verificar si está actualmente fichado
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as hay_entrada_activa
            FROM fichajes
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) = ?
            AND tipo = 'In'
            AND NOT EXISTS (
                SELECT 1 FROM fichajes f2 
                WHERE f2.fichaje_entrada_id = fichajes.id
            )
        ");
        $stmt->execute([$usuarioId, $fecha]);
        $actualmenteFichado = $stmt->fetch()['hay_entrada_activa'] > 0 ? 1 : 0;
        
        // Insertar o actualizar
        $stmt = $pdo->prepare("
            INSERT INTO fichajes_resumen_diario (
                usuario_id, jibble_person_id, nombre, fecha,
                total_horas, total_minutos, 
                num_entradas, num_salidas,
                primer_entrada, ultima_salida,
                actualmente_fichado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_horas = VALUES(total_horas),
                total_minutos = VALUES(total_minutos),
                num_entradas = VALUES(num_entradas),
                num_salidas = VALUES(num_salidas),
                primer_entrada = VALUES(primer_entrada),
                ultima_salida = VALUES(ultima_salida),
                actualmente_fichado = VALUES(actualmente_fichado)
        ");
        
        return $stmt->execute([
            $usuarioId,
            $personId,
            $nombre,
            $fecha,
            $stats['total_horas'] ?? 0,
            $stats['total_minutos'] ?? 0,
            $stats['num_entradas'] ?? 0,
            $stats['num_salidas'] ?? 0,
            $stats['primer_entrada'],
            $stats['ultima_salida'],
            $actualmenteFichado
        ]);
        
    } catch (Exception $e) {
        echo "⚠️ Error actualizando resumen para usuario $usuarioId fecha $fecha: " . $e->getMessage() . "\n";
        return false;
    }
}

// ============================================
// EJECUCIÓN PRINCIPAL
// ============================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     SINCRONIZACIÓN DE FICHAJES HISTÓRICOS DE JIBBLE       ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$pdo = getDBConnection();

// Calcular rango de fechas
$fechaHasta = date('Y-m-d');
$fechaDesde = date('Y-m-d', strtotime("-$diasAtras days"));

echo "📅 Período a sincronizar:\n";
echo "   Desde: $fechaDesde\n";
echo "   Hasta: $fechaHasta\n";
echo "   Días: $diasAtras\n\n";

// Obtener todos los usuarios activos
$stmt = $pdo->query("SELECT id, jibble_person_id, nombre, email FROM usuarios WHERE activo = 1");
$usuarios = $stmt->fetchAll();

echo "👥 Usuarios a sincronizar: " . count($usuarios) . "\n\n";

$estadisticas = [
    'total_fichajes_nuevos' => 0,
    'total_fichajes_actualizados' => 0,
    'total_fichajes_omitidos' => 0,
    'total_errores' => 0,
    'usuarios_procesados' => 0,
    'dias_procesados' => []
];

$tiempoInicio = microtime(true);

// Procesar cada usuario
foreach ($usuarios as $usuario) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📋 Procesando: {$usuario['nombre']} ({$usuario['email']})\n";
    echo "   Person ID: {$usuario['jibble_person_id']}\n";

    // Saltar usuarios manuales (no existen en Jibble)
    if (strpos($usuario['jibble_person_id'], 'manual_') === 0) {
        echo "   ⏭️ Usuario manual, saltando sincronización con Jibble\n\n";
        continue;
    }

    try {
        // Obtener fichajes de Jibble
        echo "   🔄 Obteniendo fichajes de Jibble...\n";
        $fichajesJibble = obtenerFichajesJibble($usuario['jibble_person_id'], $fechaDesde, $fechaHasta);
        
        echo "   ✅ Fichajes encontrados: " . count($fichajesJibble) . "\n";
        
        if (count($fichajesJibble) === 0) {
            echo "   ⚠️ Sin fichajes en este período\n\n";
            continue;
        }
        
        $nuevos = 0;
        $actualizados = 0;
        $omitidos = 0;
        $errores = 0;
        
        // Guardar cada fichaje
        foreach ($fichajesJibble as $fichaje) {
            $resultado = guardarFichaje($pdo, $usuario, $fichaje, $sobreescribir);
            
            switch ($resultado['accion']) {
                case 'insertado':
                    $nuevos++;
                    break;
                case 'actualizado':
                    $actualizados++;
                    break;
                case 'omitido':
                    $omitidos++;
                    break;
                case 'error':
                    $errores++;
                    echo "   ❌ Error: {$resultado['mensaje']}\n";
                    break;
            }
        }
        
        echo "   📊 Resultados:\n";
        echo "      • Nuevos: $nuevos\n";
        echo "      • Actualizados: $actualizados\n";
        echo "      • Omitidos: $omitidos\n";
        if ($errores > 0) {
            echo "      • Errores: $errores\n";
        }
        
        // Calcular horas y relaciones para cada día
        echo "   🔧 Calculando horas trabajadas...\n";
        $stmt = $pdo->prepare("
            SELECT DISTINCT DATE(fecha_hora) as fecha 
            FROM fichajes 
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) BETWEEN ? AND ?
            ORDER BY fecha
        ");
        $stmt->execute([$usuario['id'], $fechaDesde, $fechaHasta]);
        $fechas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $parejasTotal = 0;
        foreach ($fechas as $fecha) {
            $parejas = calcularHorasYRelaciones($pdo, $usuario['id'], $fecha);
            $parejasTotal += $parejas;
            
            // Actualizar resumen diario
            actualizarResumenDiario($pdo, $usuario['id'], $usuario['jibble_person_id'], $usuario['nombre'], $fecha);
            
            if (!in_array($fecha, $estadisticas['dias_procesados'])) {
                $estadisticas['dias_procesados'][] = $fecha;
            }
        }
        
        echo "   ✅ Parejas entrada-salida: $parejasTotal\n";
        echo "   ✅ Días actualizados: " . count($fechas) . "\n\n";
        
        // Actualizar estadísticas globales
        $estadisticas['total_fichajes_nuevos'] += $nuevos;
        $estadisticas['total_fichajes_actualizados'] += $actualizados;
        $estadisticas['total_fichajes_omitidos'] += $omitidos;
        $estadisticas['total_errores'] += $errores;
        $estadisticas['usuarios_procesados']++;
        
    } catch (Exception $e) {
        echo "   ❌ ERROR: " . $e->getMessage() . "\n\n";
        $estadisticas['total_errores']++;
    }
}

$tiempoFin = microtime(true);
$tiempoTotal = round($tiempoFin - $tiempoInicio, 2);

// Resumen final
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                    RESUMEN FINAL                           ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "✅ Usuarios procesados: {$estadisticas['usuarios_procesados']}\n";
echo "✅ Días únicos procesados: " . count($estadisticas['dias_procesados']) . "\n";
echo "✅ Fichajes nuevos: {$estadisticas['total_fichajes_nuevos']}\n";
echo "✅ Fichajes actualizados: {$estadisticas['total_fichajes_actualizados']}\n";
echo "⚠️  Fichajes omitidos: {$estadisticas['total_fichajes_omitidos']}\n";

if ($estadisticas['total_errores'] > 0) {
    echo "❌ Errores: {$estadisticas['total_errores']}\n";
}

echo "\n⏱️  Tiempo total: {$tiempoTotal} segundos\n";

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🎉 ¡SINCRONIZACIÓN COMPLETADA!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Registrar en sync_log
try {
    $stmt = $pdo->prepare("
        INSERT INTO sync_log (tipo, mensaje, usuarios_nuevos, usuarios_actualizados, success)
        VALUES ('fichajes_sync', ?, ?, ?, 1)
    ");
    $mensaje = "Sincronizados {$estadisticas['total_fichajes_nuevos']} fichajes nuevos, "
             . "{$estadisticas['total_fichajes_actualizados']} actualizados, "
             . "{$estadisticas['total_fichajes_omitidos']} omitidos";
    $stmt->execute([
        $mensaje,
        $estadisticas['total_fichajes_nuevos'],
        $estadisticas['total_fichajes_actualizados']
    ]);
} catch (Exception $e) {
    echo "⚠️ No se pudo registrar en sync_log: " . $e->getMessage() . "\n";
}

echo "💡 Tip: Ahora puedes generar reportes con:\n";
echo "   php reportes-mejorado.php\n\n";
?>