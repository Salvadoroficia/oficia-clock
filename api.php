<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * api.php - API Unificada del Sistema de Fichajes
 * 
 * Endpoints:
 * - POST /api.php?action=login
 * - POST /api.php?action=logout
 * - POST /api.php?action=check_auth
 * - POST /api.php?action=admin_login
 * - POST /api.php?action=admin_check
 * - POST /api.php?action=get_pin
 * - POST /api.php?action=clock_in
 * - POST /api.php?action=clock_out
 * - POST /api.php?action=get_status
 * - POST /api.php?action=sync_workers
 * - POST /api.php?action=get_users
 * - POST /api.php?action=get_logs
 */

date_default_timezone_set('Europe/Madrid');

session_start();
header('Referrer-Policy: strict-origin-when-cross-origin');
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/JibbleAPI.php';
require_once __DIR__ . '/app/horarios_helper.php';
require_once __DIR__ . '/app/auditoria_helper.php';
require_once __DIR__ . '/app/informes_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Obtener acción
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

// Obtener datos del body
$input = file_get_contents('php://input');
$data = json_decode($input, true) ?? [];

// Historial de cambios: quién, desde dónde y por qué (lo usan los triggers de `fichajes`)
if (!auditoriaContexto($action, $data)) exit;
if (strpos($action, 'auditoria_') === 0 || $action === 'mis_cambios_fichajes') {
    auditoriaDispatch($action, $data);
    exit;
}
if (strpos($action, 'informe_') === 0) {
    informesDispatch($action, $data);
    exit;
}

// Router de acciones
try {
    switch ($action) {
        
        // ============================================
        // AUTENTICACIÓN DE EMPLEADOS
        // ============================================
        
        case 'login':
            handleLogin($data);
            break;
            
        case 'logout':
            handleLogout();
            break;
            
        case 'check_auth':
            handleCheckAuth();
            break;
        
        // ============================================
        // AUTENTICACIÓN DE ADMIN
        // ============================================
        
        case 'admin_login':
            handleAdminLogin($data);
            break;
            
        case 'admin_check':
            handleAdminCheck();
            break;
        
        // ============================================
        // GESTIÓN DE USUARIOS
        // ============================================
        
        case 'get_pin':
            handleGetPin($data);
            break;
            
        case 'get_users':
            requireAdmin();
            handleGetUsers();
            break;
            
        case 'sync_workers':
            requireAdmin();
            handleSyncWorkers();
            break;
            
        case 'get_logs':
            requireAdmin();
            handleGetLogs();
            break;
        
        // ============================================
        // FICHAJES
        // ============================================
        
        case 'clock_in':
            handleClockIn($data);
            break;
            
        case 'clock_out':
            handleClockOut($data);
            break;
            
        case 'get_status':
            handleGetStatus($data);
            break;
        
        case 'get_mis_fichajes':
            handleGetMisFichajes();
            break;
            
        case 'clock_out_all':
            requireAdmin();
            handleClockOutAll();
            break;
        
        case 'sync_fichajes_jibble':
            requireAdmin();
            handleSyncFichajesJibble();
            break;
            
        case 'get_all_fichajes':
            requireAdmin();
            handleGetAllFichajes();
            break;

        case 'break_start':
            handleBreakStartLocal($data);
            break;
    
        case 'break_end':
            handleBreakEndLocal($data);
            break;

        case 'get_break_status':
            handleGetBreakStatus();
            break;
    
        case 'set_auto_clockout':
            handleSetAutoClockout($data);
            break;
    
        case 'get_auto_clockout':
            handleGetAutoClockout();
            break;
    
        case 'check_auto_clockout':
            handleCheckAutoClockout();
            break;

        case 'generar_reporte':
            requireAdmin();
            handleGenerarReporte();
            break;

        case 'get_estadisticas_generales':
            requireAdmin();
            handleGetEstadisticasGenerales();
            break;

        case 'exportar_datos':
            requireAdmin();
            handleExportarDatos();
            break;

        case 'get_all_breaks':
            requireAdmin();
            handleGetAllBreaks();
            break;

        case 'get_breaks_by_user':
            requireAdmin();
            handleGetBreaksByUser();
            break;

        case 'generar_reporte_con_breaks':
            requireAdmin();
            handleGenerarReporteConBreaks();
            break;

        case 'manual_clockout_user':
            requireAdmin();
            handleManualClockoutUser($data);
            break;

        case 'get_users_clocked_in':
            requireAdmin();
            handleGetUsersClockedIn();
            break;

   		case 'set_geolocation_settings':
        	requireAdmin();
        	handleSetGeolocationSettings($data);
        	break;

    	case 'get_geolocation_settings':
        	handleGetGeolocationSettings();
        	break;

    	case 'validate_location':
        	handleValidateLocation($data);
        	break;

    	case 'delete_fichaje':
        	requireAdmin();
        	handleDeleteFichaje($data);
        	break;
			
		case 'edit_fichaje':
    		requireAdmin();
    		handleEditFichaje($data);
    		break;
			
		case 'add_fichaje_manual':
    		requireAdmin();
    		handleAddFichajeManual($data);
    		break;
			
		case 'rfid_checkin':
    		handleRFIDCheckin($data);
    		break;

		case 'get_my_stats':
    		handleGetMyStats($data);
    		break;
			
		case 'get_anos_disponibles':
    		handleGetAnosDisponibles();
    		break;

		case 'crear_usuario_manual':
			handleCrearUsuarioManual($data);
			break;

		case 'set_horas_semanales':
			handleSetHorasSemanales($data);
			break;

		case 'set_hora_cierre':
			handleSetHoraCierre($data);
			break;

		case 'obtener_ultimos_rfid_no_asignados':
    		handleObtenerUltimosRFIDNoAsignados($data);
    		break;

		case 'asignar_rfid':
    		handleAsignarRFID($data);
    		break;

		case 'obtener_asignaciones_rfid_hoy':
    		handleObtenerAsignacionesRFIDHoy($data);
    		break;

		case 'obtener_tarjetas_sin_asignar':
    		handleObtenerTarjetasSinAsignar($data);
    		break;

		case 'obtener_usuarios':
    		handleObtenerUsuarios($data);
    		break;
			
		case 'rfid_checkin':
    		handleRFIDCheckin($data);
    		break;

		case 'obtener_ultimos_rfid_no_asignados':
    		handleObtenerUltimosRFIDNoAsignados($data);
    		break;

		case 'asignar_rfid':
    		handleAsignarRFID($data);
    		break;

		case 'obtener_asignaciones_rfid_hoy':
    		handleObtenerAsignacionesRFIDHoy($data);
    		break;

		case 'obtener_tarjetas_sin_asignar':
    		handleObtenerTarjetasSinAsignar($data);
    		break;

		case 'obtener_listado_rfid':
    		handleObtenerListadoRFID($data);
    		break;

		case 'eliminar_rfid':
    		handleEliminarRFID($data);
    		break;

		case 'obtener_usuarios':
    		handleObtenerUsuarios($data);
    		break;
			
		case 'crear_ausencia':
    		requireAdmin();
    		handleCrearAusencia($data);
    		break;

		case 'get_ausencias_activas':
    		requireAdmin();
    		handleGetAusenciasActivas();
    		break;

		case 'finalizar_ausencia':
    		requireAdmin();
    		handleFinalizarAusencia($data);
    		break;

		case 'cancelar_ausencia':
    		requireAdmin();
    		handleCancelarAusencia($data);
    		break;

		case 'verificar_ausencia_usuario':
    		handleVerificarAusenciaUsuario($data);
    		break;
	
		case 'save_theme':
    		header('Content-Type: application/json');
    		if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        	http_response_code(403);
        	echo json_encode(['success' => false, 'error' => 'No autorizado']);
        	exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $theme = $data['theme'] ?? null;
    
    if (!$theme) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tema no válido']);
        exit;
    }
    
    // Guardar en archivo JSON (carpeta datos/, propia de cada cliente)
    if (!is_dir(__DIR__ . '/datos')) @mkdir(__DIR__ . '/datos', 0755, true);
    $themeFile = __DIR__ . '/datos/custom-theme.json';
    $result = file_put_contents($themeFile, json_encode($theme, JSON_PRETTY_PRINT));
    
    if ($result !== false) {
        // También generar un CSS estático
        $css = ":root {\n";
        foreach ($theme as $key => $value) {
            $css .= "    --{$key}: {$value};\n";
        }
        $css .= "}\n";
        
        file_put_contents(__DIR__ . '/datos/custom-theme.css', $css);
        
        echo json_encode(['success' => true, 'message' => 'Tema guardado']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al guardar']);
    }
    		break;
			
		case 'get_corporate_theme':
    		$pdo = getDBConnection();
    		$stmt = $pdo->query("SELECT tema_json FROM tema_corporativo ORDER BY id DESC LIMIT 1");
    		$row = $stmt->fetch();
    
    		if ($row) {
        		echo $row['tema_json'];
    		} else {
        // Tema por defecto si no hay ninguno guardado
        echo json_encode([
            'bg-grad-start' => '#667eea',
            'bg-grad-end' => '#764ba2',
            'bg-admin-start' => '#1e3c72',
            'bg-admin-end' => '#2a5298',
            'btn-in-start' => '#56ab2f',
            'btn-in-end' => '#a8e063',
            'btn-out-start' => '#eb3349',
            'btn-out-end' => '#f45c43',
            'btn-break-start' => '#f093fb',
            'btn-break-end' => '#f5576c',
            'text-main' => '#333333',
            'text-sub' => '#666666',
            'text-muted' => '#999999',
            'text-primary' => '#667eea'
        ]);
    }
    		break;

		case 'save_corporate_theme':
    		// SOLO ADMIN puede guardar tema corporativo
    		if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        	http_response_code(403);
        	echo json_encode(['success' => false, 'error' => 'No autorizado']);
        	exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $tema_json = json_encode($data['theme']);
    $admin_email = $_SESSION['admin_email'] ?? 'Admin';
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO tema_corporativo (id, tema_json, actualizado_por) 
        VALUES (1, ?, ?) 
        ON DUPLICATE KEY UPDATE tema_json = ?, actualizado_por = ?
    ");
    
    if ($stmt->execute([$tema_json, $admin_email, $tema_json, $admin_email])) {
        echo json_encode(['success' => true, 'message' => 'Tema corporativo guardado']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al guardar']);
    }
    break;

		case 'get_user_theme':
    		// Obtener tema personal del usuario autenticado
    		if (!isset($_SESSION['user_id'])) {
        	if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $themeFile = __DIR__ . '/datos/custom-theme.json';
            if (file_exists($themeFile)) {
                echo file_get_contents($themeFile);
            } else {
                echo json_encode(null);
            }
        } else {
            echo json_encode(null);
        }
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT tema_json FROM temas_usuarios WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    
    if ($row) {
        echo $row['tema_json'];
    } else {
        echo json_encode(null);
    }
    break;

		case 'save_user_theme':
    		// Guardar tema personal del usuario
    		if (!isset($_SESSION['user_id'])) {
        	http_response_code(401);
        	echo json_encode(['success' => false, 'error' => 'No autenticado']);
        	exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);
    $tema_json = json_encode($data['theme']);
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO temas_usuarios (user_id, tema_json) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE tema_json = ?
    ");
    
    if ($stmt->execute([$user_id, $tema_json, $tema_json])) {
        echo json_encode(['success' => true, 'message' => 'Tema personal guardado']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al guardar']);
    }
    			break;

		case 'delete_user_theme':
    		// Eliminar tema personal (volver al corporativo)
    		if (!isset($_SESSION['user_id'])) {
        	http_response_code(401);
        	echo json_encode(['success' => false, 'error' => 'No autenticado']);
        	exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM temas_usuarios WHERE user_id = ?");
    
    if ($stmt->execute([$user_id])) {
        echo json_encode(['success' => true, 'message' => 'Tema personal eliminado']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al eliminar']);
    }
    		break;

        
		case 'solicitar_ausencia':
    		requireAuth();
    		handleSolicitarAusencia($data);
    		break;

		case 'get_solicitudes_ausencia':
    		requireAdmin();
    		handleGetSolicitudesAusencia();
    		break;

		case 'responder_solicitud_ausencia':
    		requireAdmin();
    		handleResponderSolicitudAusencia($data);
    		break;

		case 'get_mis_solicitudes':
    		requireAuth();
    		handleGetMisSolicitudes();
    		break;

		case 'delete_fichajes_rango':
    		requireAdmin();
    		handleDeleteFichajesRango($data);
    		break;

		case 'delete_fichajes_rango_jibble':
    		requireAdmin();
    		handleDeleteFichajesRangoJibble($data);
    		break;


		case 'sync_users_zkteco':
    		requireAdmin();
    		handleSyncUsersZkteco($data);
    		break;

		case 'get_zkteco_commands':
    		handleGetZktecoCommands($data);
    		break;
			
		case 'preview_bulk_delete':
    		requireAdmin();
    		handlePreviewBulkDelete($data);
    		break;

		case 'execute_bulk_delete':
    		requireAdmin();
    		handleExecuteBulkDelete($data);
    		break;
			
		case 'preview_bulk_delete':
    		requireAdmin();
    		handlePreviewBulkDelete($data);
    		break;

		case 'execute_bulk_delete':
    		requireAdmin();
    		handleExecuteBulkDelete($data);
    		break;

// ============================================================
// HORARIOS Y CALENDARIO
// ============================================================
    // --- Admin y empleado (la propia función decide qué puede ver cada uno) ---
    	case 'get_horario_usuario':
        	handleGetHorarioUsuario($data);
        	break;

    	case 'save_horario_usuario':
        	handleSaveHorarioUsuario($data);
        	break;

    	case 'get_calendario_eventos':
        	handleGetCalendarioEventos($data);
        	break;

    	case 'get_festivos':
        	handleGetFestivos($data);
        	break;

		case 'importar_festivos':
        	requireAdmin();
        	handleImportarFestivos($data);
        	break;

    	case 'delete_festivo':
        	requireAdmin();
        	handleDeleteFestivo($data);
        	break;

    	case 'get_mi_horario_hoy':
        	handleGetMiHorarioHoy($data);
        	break;

    // --- Solo admin ---
    	case 'toggle_restriccion_horario':
        	requireAdmin();
        	handleToggleRestriccionHorario($data);
        	break;

    	case 'get_horarios_resumen':
        	requireAdmin();
        	handleGetHorariosResumen();
        	break;

    	case 'copiar_horario':
        	requireAdmin();
        	handleCopiarHorario($data);
        	break;

    	case 'save_config_horarios':
        	requireAdmin();
        	handleSaveConfigHorarios($data);
        	break;

    	case 'save_festivo':
        	requireAdmin();
        	handleSaveFestivo($data);
        	break;

    	case 'delete_festivo':
        	requireAdmin();
        	handleDeleteFestivo($data);
        	break;

    	case 'get_excepciones_horario':
        	requireAdmin();
        	handleGetExcepcionesHorario($data);
        	break;

    	case 'save_excepcion_horario':
        	requireAdmin();
        	handleSaveExcepcionHorario($data);
        	break;

    	case 'delete_excepcion_horario':
        	requireAdmin();
        	handleDeleteExcepcionHorario($data);
        	break;

    	case 'get_bloqueos_horario':
        	requireAdmin();
        	handleGetBloqueosHorario($data);
        	break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Action not found']);
            break;
    
    }
    
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// ============================================
// FUNCIONES DE EMPLEADOS
// ============================================

function handleLogin($data) {
    $email = trim($data['email'] ?? '');
    $pin = trim($data['pin'] ?? '');
    
    if (!$email || !$pin) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email y PIN requeridos']);
        return;
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, jibble_person_id, nombre, email
        FROM usuarios 
        WHERE LOWER(email) = ? AND pin = ? AND activo = 1
    ");
    $stmt->execute([strtolower($email), $pin]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sleep(1);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email o PIN incorrectos']);
        return;
    }
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['person_id'] = $user['jibble_person_id'];
    $_SESSION['nombre'] = $user['nombre'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['login_time'] = time();
    
    $stmt = $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    echo json_encode([
        'success' => true,
		'user_id' => $user['id'],
        'user' => ['id' => $user['id'],
				   'nombre' => $user['nombre']]
    ]);
}

function handleLogout() {
    session_destroy();
    echo json_encode(['success' => true]);
}

function handleCheckAuth() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        return;
    }
    
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_DURATION) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['authenticated' => false, 'reason' => 'expired']);
        return;
    }
    
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'nombre' => $_SESSION['nombre'],
            'person_id' => $_SESSION['person_id'],
            'email' => $_SESSION['email']
        ]
    ]);
}

// ============================================
// FUNCIONES DE ADMIN
// ============================================

function handleAdminLogin($data) {
    $username = trim($data['username'] ?? '');
    $password = trim($data['password'] ?? '');

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuario y contraseña requeridos']);
        return;
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admins WHERE username = ? AND activo = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            sleep(1);
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Usuario o contraseña incorrectos']);
            return;
        }

        // Actualizar ultimo login
        $pdo->prepare("UPDATE admins SET ultimo_login = NOW() WHERE id = ?")
            ->execute([$admin['id']]);

        $_SESSION['admin_logged_in']  = true;
        $_SESSION['admin_username']   = $admin['username'];
        $_SESSION['admin_id']         = $admin['id'];
        $_SESSION['admin_login_time'] = time();

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleAdminCheck() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        return;
    }

    echo json_encode([
        'authenticated' => true,
        'username'      => $_SESSION['admin_username']
    ]);
}

function requireAdmin() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
        exit;
    }
}

function requireAuth() {
    if (!isset($_SESSION['user_id']) && (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        exit;
    }
}

// ============================================
// FUNCIONES DE USUARIOS
// ============================================

function handleGetPin($data) {
    $email = trim(strtolower($data['email'] ?? ''));
    
    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email requerido']);
        return;
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT nombre, email, pin, activo
        FROM usuarios 
        WHERE LOWER(email) = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
        return;
    }
    
    if (!$user['activo']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cuenta desactivada']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'pin' => $user['pin'],
        'nombre' => $user['nombre'],
        'email' => $user['email']
    ]);
}

function handleGetUsers() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT id, jibble_person_id, nombre, email, pin, activo, ultimo_login, fecha_creacion
        FROM usuarios 
        ORDER BY activo DESC, nombre ASC
    ");
    $users = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'users' => $users,
        'total' => count($users)
    ]);
}

function handleSyncWorkers() {
    $pdo = getDBConnection();
    $jibble = new JibbleAPI();
    $response = $jibble->getPeople();
    
    if (!isset($response['value'])) {
        throw new Exception('Respuesta inválida de Jibble API');
    }
    
    $stats = ['nuevos' => 0, 'actualizados' => 0, 'nuevos_detalle' => []];
    
    foreach ($response['value'] as $person) {
        $personId = $person['id'] ?? null;
        $nombre = $person['name'] ?? $person['fullName'] ?? 'Sin nombre';
        $email = $person['emailAddress'] ?? $person['email'] ?? $personId . '@noemail.local';
        
        if (!$personId) continue;
        
        $stmt = $pdo->prepare("SELECT id, pin FROM usuarios WHERE jibble_person_id = ?");
        $stmt->execute([$personId]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, activo = 1 WHERE jibble_person_id = ?");
            $stmt->execute([$nombre, $email, $personId]);
            $stats['actualizados']++;
        } else {
            $pin = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE pin = ?");
            $stmt->execute([$pin]);
            while ($stmt->fetchColumn() > 0) {
                $pin = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $stmt->execute([$pin]);
            }
            
            $stmt = $pdo->prepare("INSERT INTO usuarios (jibble_person_id, nombre, email, pin, activo) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$personId, $nombre, $email, $pin]);
            $stats['nuevos']++;
            $stats['nuevos_detalle'][] = ['nombre' => $nombre, 'email' => $email, 'pin' => $pin];
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO sync_log (tipo, mensaje, usuarios_nuevos, usuarios_actualizados, success) VALUES ('auto_sync', 'Sincronización exitosa', ?, ?, 1)");
    $stmt->execute([$stats['nuevos'], $stats['actualizados']]);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'total_procesados' => count($response['value']),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function handleGetLogs() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT id, tipo, mensaje, usuarios_nuevos, usuarios_actualizados, success, fecha
        FROM sync_log 
        ORDER BY fecha DESC 
        LIMIT 50
    ");
    $logs = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => count($logs)
    ]);
}

// ============================================
// FUNCIONES DE FICHAJES
// ============================================

// ============================================
// FUNCIÓN ACTUALIZADA: handleClockIn
// ============================================
function handleClockIn($data) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $personId = $_SESSION['person_id'];
    $nombre = $_SESSION['nombre'];
    $note = $data['note'] ?? null;
	
// ============================================
// QUICK FIX: Prevenir duplicados (5 segundos)
// ============================================
try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT id, fecha_hora 
        FROM fichajes 
        WHERE usuario_id = ? 
        AND tipo = 'In'
        AND es_descanso = 0
        AND timestamp_unix > (UNIX_TIMESTAMP() - 5)
    ");
    $stmt->execute([$userId]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => '⚠️ Ya has fichado hace menos de 5 segundos. Espera un momento.'
        ]);
        return;
    }
} catch (Exception $e) {
    // Si falla la validación, continuar (no bloquear)
}
    
    // ============ RESTRICCIÓN DE HORARIO (solo entradas) ============
    try {
        $pdoH = getDBConnection();
        $chkH = comprobarHorarioFichaje($pdoH, $userId);
        if (!$chkH['permitido']) {
            horariosRegistrarBloqueo($pdoH, $userId, $nombre, 'web', $chkH['mensaje']);
            echo json_encode([
                'success'       => false,
                'error'         => $chkH['mensaje'],
                'fuera_horario' => true,
                'proximo_turno' => $chkH['proximo_turno'],
            ]);
            return;
        }
    } catch (Exception $e) {
        // Si falla la comprobación, no bloquear
    }
    
    // ============ GEOLOCALIZACIÓN ============
    $latitud = $data['latitude'] ?? null;
    $longitud = $data['longitude'] ?? null;
    $ubicacionValidada = null;
    $distanciaMetros = null;
    
    try {
        $pdo = getDBConnection();
        
        // Verificar configuración de geolocalización
        $stmt = $pdo->query("SELECT * FROM geolocation_settings WHERE id = 1");
        $geoSettings = $stmt->fetch();
        
        if ($geoSettings && $geoSettings['enabled']) {
            if ($geoSettings['required'] && (!$latitud || !$longitud)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Geolocalización requerida para fichar',
                    'geolocation_required' => true
                ]);
                return;
            }
            
            if ($latitud && $longitud) {
                $validationResult = validateLocationInternal($latitud, $longitud, $geoSettings);
                $ubicacionValidada = $validationResult['valid'] ? 1 : 0;
                $distanciaMetros = $validationResult['distance'];
                
                if ($geoSettings['required'] && !$validationResult['valid']) {
                    echo json_encode([
                        'success' => false,
                        'error' => $validationResult['message'],
                        'distance' => $distanciaMetros,
                        'allowed_radius' => $geoSettings['validation_radius']
                    ]);
                    return;
                }
            }
        }
        // =========================================
        
        // Usuarios manuales: saltar Jibble
        $esManual = (strpos($personId, 'manual_') === 0);
        if ($esManual) {
            $result = true;
        } else {
            $jibble = new JibbleAPI();
            $result = $jibble->clockIn($personId, $note);
        }
        
        if ($result) {
            $timestamp = time();
            
            // Guardar con coordenadas
            $stmt = $pdo->prepare("
                INSERT INTO fichajes (
                    usuario_id, jibble_person_id, nombre, tipo, 
                    fecha_hora, timestamp_unix, nota, dispositivo,
                    sincronizado_jibble,
                    latitud, longitud, ubicacion_validada, distancia_metros
                ) VALUES (?, ?, ?, 'In', NOW(), ?, ?, 'Web App', ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $personId, $nombre, $timestamp, $note,
                $esManual ? 0 : 1,
                $latitud, $longitud, $ubicacionValidada, $distanciaMetros
            ]);
            
            $fichajeId = $pdo->lastInsertId();
            
            actualizarResumenDiario($pdo, $userId, $personId, $nombre, date('Y-m-d'));
            
            echo json_encode([
                'success' => true,
                'message' => '¡Fichaje de entrada exitoso!',
                'timestamp' => date('Y-m-d H:i:s'),
                'fichaje_id' => $fichajeId,
                'location_validated' => $ubicacionValidada,
                'distance' => $distanciaMetros
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Error al registrar en Jibble'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al fichar entrada: ' . $e->getMessage()
        ]);
    }
}


// ============================================
// handleClockOut - VERSIÓN CORREGIDA
// ============================================
function handleClockOut($data) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $personId = $_SESSION['person_id'];
    $nombre = $_SESSION['nombre'];
	
	// ============================================
// QUICK FIX: Prevenir duplicados (5 segundos)
// ============================================
try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT id, fecha_hora 
        FROM fichajes 
        WHERE usuario_id = ? 
        AND tipo = 'Out'
        AND es_descanso = 0
        AND timestamp_unix > (UNIX_TIMESTAMP() - 5)
    ");
    $stmt->execute([$userId]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => '⚠️ Ya has fichado salida hace menos de 5 segundos. Espera un momento.'
        ]);
        return;
    }
} catch (Exception $e) {
    // Si falla la validación, continuar (no bloquear)
}
    
    // ============ GEOLOCALIZACIÓN ============
    $latitud = $data['latitude'] ?? null;
    $longitud = $data['longitude'] ?? null;
    $ubicacionValidada = null;
    $distanciaMetros = null;
    
    try {
        $pdo = getDBConnection();
        
        // Verificar configuración de geolocalización
        $stmt = $pdo->query("SELECT * FROM geolocation_settings WHERE id = 1");
        $geoSettings = $stmt->fetch();
        
        if ($geoSettings && $geoSettings['enabled']) {
            if ($geoSettings['required'] && (!$latitud || !$longitud)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Geolocalización requerida para fichar',
                    'geolocation_required' => true
                ]);
                return;
            }
            
            if ($latitud && $longitud) {
                $validationResult = validateLocationInternal($latitud, $longitud, $geoSettings);
                $ubicacionValidada = $validationResult['valid'] ? 1 : 0;
                $distanciaMetros = $validationResult['distance'];
                
                if ($geoSettings['required'] && !$validationResult['valid']) {
                    echo json_encode([
                        'success' => false,
                        'error' => $validationResult['message'],
                        'distance' => $distanciaMetros,
                        'allowed_radius' => $geoSettings['validation_radius']
                    ]);
                    return;
                }
            }
        }
        // =========================================
        
        // Usuarios manuales: saltar Jibble
        $esManual = (strpos($personId, 'manual_') === 0);
        if ($esManual) {
            $result = true;
        } else {
            try {
                $jibble = new JibbleAPI();
                $result = $jibble->clockOut($personId);
            } catch (Exception $e) {
                error_log("Jibble clockOut warning: " . $e->getMessage());
                $result = true;
            }
        }
        
        if ($result) {
            // Buscar última entrada sin salida del día
            $stmt = $pdo->prepare("
                SELECT id, timestamp_unix
                FROM fichajes 
                WHERE usuario_id = ? 
                AND tipo = 'In' 
                AND DATE(fecha_hora) = CURDATE()
                AND es_descanso = 0
                ORDER BY timestamp_unix DESC 
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $ultimaEntrada = $stmt->fetch();
            
            $horasTrabajadas = null;
            $minutosTrabajados = null;
            $entradaId = null;
            
            if ($ultimaEntrada) {
                $entradaId = $ultimaEntrada['id'];
                $segundosTrabajados = time() - $ultimaEntrada['timestamp_unix'];
                $minutosTrabajados = floor($segundosTrabajados / 60);
                $horasTrabajadas = round($minutosTrabajados / 60, 2);
                
                // Actualizar la entrada con las horas trabajadas
                $stmt = $pdo->prepare("
                    UPDATE fichajes 
                    SET horas_trabajadas = ?, minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$horasTrabajadas, $minutosTrabajados, $entradaId]);
            }
            
            // ============ CAMBIO AQUÍ: AGREGAR COLUMNAS DE UBICACIÓN ============
            $timestamp = time();
            $stmt = $pdo->prepare("
                INSERT INTO fichajes (
                    usuario_id, jibble_person_id, nombre, tipo, 
                    fecha_hora, timestamp_unix, dispositivo,
                    fichaje_entrada_id, horas_trabajadas, minutos_trabajados,
                    sincronizado_jibble,
                    latitud, longitud, ubicacion_validada, distancia_metros
                ) VALUES (?, ?, ?, 'Out', NOW(), ?, 'Web App', ?, ?, ?, 1, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $personId,
                $nombre,
                $timestamp,
                $entradaId,
                $horasTrabajadas,
                $minutosTrabajados,
                $latitud,
                $longitud,
                $ubicacionValidada,
                $distanciaMetros
            ]);
            // ====================================================================
            
            $fichajeId = $pdo->lastInsertId();
            
            // Actualizar resumen diario
            actualizarResumenDiario($pdo, $userId, $personId, $nombre, date('Y-m-d'));
            
            echo json_encode([
                'success' => true,
                'message' => '¡Fichaje de salida exitoso!',
                'timestamp' => date('Y-m-d H:i:s'),
                'horas_trabajadas' => $horasTrabajadas,
                'minutos_trabajados' => $minutosTrabajados,
                'fichaje_id' => $fichajeId,
                'location_validated' => $ubicacionValidada,
                'distance' => $distanciaMetros
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Error al registrar salida en Jibble'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al fichar salida: ' . $e->getMessage()
        ]);
    }
}

// ============================================
// handleGetStatus - VERSIÓN CORREGIDA
// ============================================
function handleGetStatus($data) {
    // Verificar autenticación
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $personId = $_SESSION['person_id'];
    $userId   = $_SESSION['user_id'];

    // Leer siempre el estado desde la BD — cubre usuarios manuales y fichajes RFID
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT tipo, fecha_hora FROM fichajes
            WHERE usuario_id = ? AND es_descanso = 0
            ORDER BY fecha_hora DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $ultimo    = $stmt->fetch();
        $status    = ($ultimo && $ultimo['tipo'] === 'In') ? 'in' : 'out';
        $fechaHora = ($status === 'in') ? str_replace(' ', 'T', $ultimo['fecha_hora']) : null;
        $lastEntry = $fechaHora ? ['time' => $fechaHora, 'type' => 'In'] : null;
        echo json_encode(['success' => true, 'status' => $status, 'lastEntry' => $lastEntry]);
    } catch (Exception $e) {
        // Fallback a Jibble si falla la BD
        try {
            $jibble = new JibbleAPI();
            $status = $jibble->getStatus($personId);
            echo json_encode(['success' => true, 'status' => $status['status'], 'lastEntry' => $status['lastEntry']]);
        } catch (Exception $e2) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e2->getMessage()]);
        }
    }
}


function handleClockOutAll() {
    try {
        $pdo = getDBConnection();
        $jibble = new JibbleAPI();
        
        // Obtener todos los usuarios activos
        $stmt = $pdo->query("SELECT jibble_person_id, nombre FROM usuarios WHERE activo = 1");
        $usuarios = $stmt->fetchAll();
        
        $desfichados = 0;
        $errores = [];
        
        foreach ($usuarios as $usuario) {
            try {
                $esManual = (strpos($usuario['jibble_person_id'], 'manual_') === 0);

                if ($esManual) {
                    $stmt2 = $pdo->prepare("
                        SELECT tipo FROM fichajes
                        WHERE usuario_id = ? AND es_descanso = 0
                        ORDER BY fecha_hora DESC LIMIT 1
                    ");
                    $stmt2->execute([$usuario['id']]);
                    $ul     = $stmt2->fetch();
                    $estaIn = ($ul && $ul['tipo'] === 'In');
                } else {
                    $status = $jibble->getStatus($usuario['jibble_person_id']);
                    $estaIn = ($status['status'] === 'in');
                }

                if ($estaIn) {
                    if (!$esManual) {
                        $jibble->clockOut($usuario['jibble_person_id']);
                    }
                    
                    // Registrar en BD local
                    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE jibble_person_id = ?");
                    $stmt->execute([$usuario['jibble_person_id']]);
                    $usuarioData = $stmt->fetch();
                    
                    if ($usuarioData) {
                        // Buscar última entrada sin salida
                        $stmt = $pdo->prepare("
                            SELECT id, timestamp_unix
                            FROM fichajes 
                            WHERE usuario_id = ? AND tipo = 'In' AND fichaje_entrada_id IS NULL
                            ORDER BY fecha_hora DESC 
                            LIMIT 1
                        ");
                        $stmt->execute([$usuarioData['id']]);
                        $ultimaEntrada = $stmt->fetch();
                        
                        $horasTrabajadas = null;
                        $minutosTrabajados = null;
                        $fichajeEntradaId = null;
                        
                        if ($ultimaEntrada) {
                            $segundos = time() - $ultimaEntrada['timestamp_unix'];
                            $minutosTrabajados = floor($segundos / 60);
                            $horasTrabajadas = round($minutosTrabajados / 60, 2);
                            $fichajeEntradaId = $ultimaEntrada['id'];
                        }
                        
                        // Registrar salida automática
                        $stmt = $pdo->prepare("
                            INSERT INTO fichajes (
                                usuario_id, jibble_person_id, nombre, tipo, 
                                fecha_hora, timestamp_unix, nota, dispositivo,
                                fichaje_entrada_id, horas_trabajadas, minutos_trabajados,
                                sincronizado_jibble
                            ) VALUES (?, ?, ?, 'Out', NOW(), ?, 'Desfichaje automático admin', 'Admin Panel', ?, ?, ?, true)
                        ");
                        
                        $stmt->execute([
                            $usuarioData['id'],
                            $usuario['jibble_person_id'],
                            $usuario['nombre'],
                            time(),
                            $fichajeEntradaId,
                            $horasTrabajadas,
                            $minutosTrabajados
                        ]);
                        
                        if ($fichajeEntradaId) {
                            $stmt = $pdo->prepare("UPDATE fichajes SET horas_trabajadas = ?, minutos_trabajados = ? WHERE id = ?");
                            $stmt->execute([$horasTrabajadas, $minutosTrabajados, $fichajeEntradaId]);
                            
                            actualizarResumenDiario($pdo, $usuarioData['id'], $usuario['jibble_person_id'], $usuario['nombre']);
                        }
                    }
                    
                    $desfichados++;
                }
            } catch (Exception $e) {
                $errores[] = $usuario['nombre'] . ': ' . $e->getMessage();
            }
        }
        
        echo json_encode([
            'success' => true,
            'count' => $desfichados,
            'total_usuarios' => count($usuarios),
            'errores' => $errores,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// ============================================
// NUEVA FUNCIÓN: actualizarResumenDiario
// ============================================
/**
 * Actualiza la tabla fichajes_resumen_diario para un usuario en una fecha específica
 * Calcula automáticamente totales, número de fichajes, primera entrada y última salida
 */
function actualizarResumenDiario($pdo, $usuarioId, $personId, $nombre, $fecha = null) {
    if ($fecha === null) {
        $fecha = date('Y-m-d');
    }
    
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
        
        // Verificar si el usuario está actualmente fichado (última entrada sin salida)
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
        
        // Insertar o actualizar resumen
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
                actualmente_fichado = VALUES(actualmente_fichado),
                fecha_actualizacion = NOW()
        ");
        
        $stmt->execute([
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
        
        return true;
    } catch (Exception $e) {
        error_log("Error al actualizar resumen diario: " . $e->getMessage());
        return false;
    }
}

// ============================================
// NUEVA FUNCIÓN: handleGetMisFichajes
// ============================================
/**
 * Obtener historial de fichajes del usuario autenticado
 */
function handleGetMisFichajes() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    
    try {
        $pdo = getDBConnection();
        
        // Obtener fichajes individuales
        $stmt = $pdo->prepare("
            SELECT 
                id, tipo, fecha_hora, nota, dispositivo,
                fichaje_entrada_id, horas_trabajadas, minutos_trabajados,
                DATE(fecha_hora) as fecha,
                TIME(fecha_hora) as hora
            FROM fichajes
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) BETWEEN ? AND ?
            ORDER BY fecha_hora DESC
        ");
        $stmt->execute([$userId, $fechaDesde, $fechaHasta]);
        $fichajes = $stmt->fetchAll();
        
        // Obtener resumen diario
        $stmt = $pdo->prepare("
            SELECT 
                fecha, total_horas, total_minutos,
                num_entradas, num_salidas,
                primer_entrada, ultima_salida,
                actualmente_fichado
            FROM fichajes_resumen_diario
            WHERE usuario_id = ? 
            AND fecha BETWEEN ? AND ?
            ORDER BY fecha DESC
        ");
        $stmt->execute([$userId, $fechaDesde, $fechaHasta]);
        $resumenDiario = $stmt->fetchAll();
        
        // Calcular totales del período
        $totalHoras = array_sum(array_column($resumenDiario, 'total_horas'));
        $totalMinutos = array_sum(array_column($resumenDiario, 'total_minutos'));
        
        echo json_encode([
            'success' => true,
            'fichajes' => $fichajes,
            'resumen_diario' => $resumenDiario,
            'totales' => [
                'total_horas' => $totalHoras,
                'total_minutos' => $totalMinutos,
                'dias_trabajados' => count($resumenDiario),
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function handleSyncFichajesJibble() {
    $dias = isset($_GET['dias']) ? intval($_GET['dias']) : 30;
    $usuarioId = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : null;
    
    try {
        $pdo = getDBConnection();
        $jibble = new JibbleAPI();
        
        $fechaHasta = date('Y-m-d');
        $fechaDesde = date('Y-m-d', strtotime("-$dias days"));
        
        // Obtener usuarios a sincronizar
        if ($usuarioId) {
            $stmt = $pdo->prepare("SELECT id, jibble_person_id, nombre FROM usuarios WHERE id = ? AND activo = 1");
            $stmt->execute([$usuarioId]);
            $usuarios = [$stmt->fetch()];
        } else {
            $stmt = $pdo->query("SELECT id, jibble_person_id, nombre FROM usuarios WHERE activo = 1");
            $usuarios = $stmt->fetchAll();
        }
        
        $stats = [
            'usuarios_procesados' => 0,
            'fichajes_nuevos' => 0,
            'fichajes_actualizados' => 0,
            'fichajes_omitidos' => 0,
            'dias_procesados' => [],
            'errores' => []
        ];
        
        foreach ($usuarios as $usuario) {
            try {
                // Obtener fichajes de Jibble
                $fichajesJibble = obtenerFichajesDeJibble(
                    $usuario['jibble_person_id'], 
                    $fechaDesde, 
                    $fechaHasta
                );
                
                foreach ($fichajesJibble as $fichaje) {
                    $resultado = guardarFichajeDeJibble($pdo, $usuario, $fichaje);
                    
                    if ($resultado['accion'] === 'insertado') {
                        $stats['fichajes_nuevos']++;
                    } elseif ($resultado['accion'] === 'actualizado') {
                        $stats['fichajes_actualizados']++;
                    } elseif ($resultado['accion'] === 'omitido') {
                        $stats['fichajes_omitidos']++;
                    }
                }
                
                // Calcular horas para cada día
                $stmt = $pdo->prepare("
                    SELECT DISTINCT DATE(fecha_hora) as fecha 
                    FROM fichajes 
                    WHERE usuario_id = ? 
                    AND DATE(fecha_hora) BETWEEN ? AND ?
                ");
                $stmt->execute([$usuario['id'], $fechaDesde, $fechaHasta]);
                $fechas = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($fechas as $fecha) {
                    calcularHorasYRelacionesDia($pdo, $usuario['id'], $fecha);
                    actualizarResumenDiario($pdo, $usuario['id'], $usuario['jibble_person_id'], $usuario['nombre'], $fecha);
                    
                    if (!in_array($fecha, $stats['dias_procesados'])) {
                        $stats['dias_procesados'][] = $fecha;
                    }
                }
                
                $stats['usuarios_procesados']++;
                
            } catch (Exception $e) {
                $stats['errores'][] = $usuario['nombre'] . ': ' . $e->getMessage();
            }
        }
        
        // Registrar en log
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, usuarios_nuevos, usuarios_actualizados, success)
            VALUES ('fichajes_sync', ?, ?, ?, 1)
        ");
        $mensaje = "Sincronizados {$stats['fichajes_nuevos']} fichajes nuevos del período $fechaDesde a $fechaHasta";
        $stmt->execute([$mensaje, $stats['fichajes_nuevos'], $stats['fichajes_actualizados']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Sincronización completada',
            'stats' => $stats,
            'periodo' => [
                'desde' => $fechaDesde,
                'hasta' => $fechaHasta
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function obtenerFichajesDeJibble($personId, $fechaDesde, $fechaHasta) {
    try {
        // Obtener token
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
            throw new Exception("Error obteniendo token: HTTP $httpCode");
        }
        
        $tokenData = json_decode($response, true);
        if (!isset($tokenData['access_token'])) {
            throw new Exception("Token no recibido");
        }
        
        $token = $tokenData['access_token'];
        
        // Construir URL (sin filtro de fechas, lo hacemos en PHP)
        $url = 'https://time-tracking.prod.jibble.io/v1/TimeEntries?'
             . '$filter=personId%20eq%20' . $personId
             . '&$orderby=time%20desc'
             . '&$top=1000';
        
        // Obtener fichajes
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
            throw new Exception("Error obteniendo fichajes: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        $allEntries = $data['value'] ?? [];
        
        // Filtrar por fechas en PHP (más confiable)
        $timestampDesde = strtotime($fechaDesde . ' 00:00:00');
        $timestampHasta = strtotime($fechaHasta . ' 23:59:59');
        
        $filtered = array_filter($allEntries, function($entry) use ($timestampDesde, $timestampHasta) {
            $entryTime = strtotime($entry['time']);
            return $entryTime >= $timestampDesde && $entryTime <= $timestampHasta;
        });
        
        return array_values($filtered);
        
    } catch (Exception $e) {
        error_log("Error en obtenerFichajesDeJibble: " . $e->getMessage());
        return [];
    }
}

function guardarFichajeDeJibble($pdo, $usuario, $fichaje) {
    try {
        $fechaHora = new DateTime($fichaje['time']);
        $fechaHoraStr = $fechaHora->format('Y-m-d H:i:s');
        $timestamp = $fechaHora->getTimestamp();
        $tipo = $fichaje['type'] ?? 'In';
        $nota = $fichaje['note'] ?? null;
        
        // Verificar si ya existe (con margen de 2 segundos)
        $stmt = $pdo->prepare("
            SELECT id FROM fichajes 
            WHERE usuario_id = ? 
            AND ABS(timestamp_unix - ?) <= 2
            AND tipo = ?
        ");
        $stmt->execute([$usuario['id'], $timestamp, $tipo]);
        
        if ($stmt->fetch()) {
            return ['accion' => 'omitido'];
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
        
        return ['accion' => 'insertado', 'id' => $pdo->lastInsertId()];
        
    } catch (Exception $e) {
        error_log("Error guardando fichaje: " . $e->getMessage());
        return ['accion' => 'error', 'mensaje' => $e->getMessage()];
    }
}

function calcularHorasYRelacionesDia($pdo, $usuarioId, $fecha) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, tipo, timestamp_unix
            FROM fichajes
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) = ?
            ORDER BY timestamp_unix ASC
        ");
        $stmt->execute([$usuarioId, $fecha]);
        $fichajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $entradaActual = null;
        
        foreach ($fichajes as $fichaje) {
            if ($fichaje['tipo'] === 'In') {
                $entradaActual = $fichaje;
            } elseif ($fichaje['tipo'] === 'Out' && $entradaActual) {
                $segundos = $fichaje['timestamp_unix'] - $entradaActual['timestamp_unix'];
                $minutos = floor($segundos / 60);
                $horas = round($minutos / 60, 2);
                
                // Actualizar salida
                $stmt = $pdo->prepare("
                    UPDATE fichajes SET
                        fichaje_entrada_id = ?,
                        horas_trabajadas = ?,
                        minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$entradaActual['id'], $horas, $minutos, $fichaje['id']]);
                
                // Actualizar entrada
                $stmt = $pdo->prepare("
                    UPDATE fichajes SET
                        horas_trabajadas = ?,
                        minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$horas, $minutos, $entradaActual['id']]);
                
                $entradaActual = null;
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error calculando horas: " . $e->getMessage());
        return false;
    }
}

function handleGetAllFichajes() {
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->query("
            SELECT 
                f.id,
                f.usuario_id,
                f.nombre,
                f.tipo,
                f.fecha_hora,
                f.nota,
                f.horas_trabajadas,
                f.minutos_trabajados,
                f.dispositivo,
                f.latitud,
                f.longitud,
                f.ubicacion_validada,
                f.distancia_metros
            FROM fichajes f
            ORDER BY f.fecha_hora DESC
            LIMIT 1000
        ");
        
        $fichajes = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'fichajes' => $fichajes,
            'total' => count($fichajes)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}


function handleBreakStartLocal($data) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $personId = $_SESSION['person_id'];
    $nombre = $_SESSION['nombre'];
    $nota = $data['note'] ?? 'Descanso';
    
    try {
        $pdo = getDBConnection();
        // Usuarios manuales: verificar desde BD; resto desde Jibble
        $esManual = (strpos($personId, 'manual_') === 0);
        if ($esManual) {
            $stmt = $pdo->prepare("
                SELECT tipo FROM fichajes
                WHERE usuario_id = ? AND es_descanso = 0
                ORDER BY fecha_hora DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $ul = $stmt->fetch();
            if (!$ul || $ul['tipo'] !== 'In') {
                echo json_encode(['success' => false, 'error' => 'Debes fichar entrada primero']);
                return;
            }
        } else {
            $jibble = new JibbleAPI();
            $status = $jibble->getStatus($personId);
            if ($status['status'] === 'out') {
                echo json_encode(['success' => false, 'error' => 'Debes fichar entrada primero']);
                return;
            }
        }
        // Verificar que no esté ya en descanso (sin restringir por fecha:
        // un descanso puede seguir abierto de un día para otro si quedó pillado)
        $stmt = $pdo->prepare("
            SELECT id FROM breaks
            WHERE usuario_id = ?
            AND fin IS NULL
        ");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Ya estás en descanso']);
            return;
        }
        
        // Registrar inicio de descanso (SOLO EN BD LOCAL)
        $stmt = $pdo->prepare("
            INSERT INTO breaks (
                usuario_id, jibble_person_id, nombre,
                inicio, inicio_timestamp, tipo, nota
            ) VALUES (?, ?, ?, NOW(), ?, 'manual', ?)
        ");
        $stmt->execute([
            $userId,
            $personId,
            $nombre,
            time(),
            $nota
        ]);
        
        $breakId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Descanso iniciado',
            'break_id' => $breakId,
            'time' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Terminar descanso (solo en BD local)
 */
function handleBreakEndLocal($data) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    try {
        $pdo = getDBConnection();
        
        // Buscar descanso activo (sin restringir por fecha, ver comentario arriba)
        $stmt = $pdo->prepare("
            SELECT id, inicio_timestamp
            FROM breaks
            WHERE usuario_id = ?
            AND fin IS NULL
            ORDER BY inicio DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $break = $stmt->fetch();
        
        if (!$break) {
            echo json_encode(['success' => false, 'error' => 'No estás en descanso']);
            return;
        }
        
        // Calcular duración del descanso
        $finTimestamp = time();
        $duracionSegundos = $finTimestamp - $break['inicio_timestamp'];
        $duracionMinutos = floor($duracionSegundos / 60);
        
        // Aviso: si el descanso llevaba abierto más de 12h, algo fue mal
        // (app cerrada, se olvidó cerrarlo, etc.). Se cierra igualmente
        // con la duración real, pero lo señalamos en la respuesta para
        // poder detectarlo/corregirlo desde el panel de admin.
        $anomalo = $duracionMinutos > 720;
        
        // Actualizar el break con hora de fin
        $stmt = $pdo->prepare("
            UPDATE breaks
            SET fin = NOW(),
                fin_timestamp = ?,
                duracion_minutos = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $finTimestamp,
            $duracionMinutos,
            $break['id']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Descanso finalizado',
            'duracion_minutos' => $duracionMinutos,
            'anomalo' => $anomalo,
            'time' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Obtener estado del descanso
 */
function handleGetBreakStatus() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    try {
        $pdo = getDBConnection();
        
        // Verificar si está en descanso actualmente (sin restringir por fecha)
        $stmt = $pdo->prepare("
            SELECT id, inicio, inicio_timestamp, nota
            FROM breaks
            WHERE usuario_id = ?
            AND fin IS NULL
            ORDER BY inicio DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $activeBreak = $stmt->fetch();
        
        // Obtener breaks del día (esta sí se queda filtrada a hoy, es solo
        // para el listado del día; el descanso activo ya se detecta arriba
        // aunque venga de un día anterior)
        $stmt = $pdo->prepare("
            SELECT inicio, fin, duracion_minutos, tipo, nota
            FROM breaks
            WHERE usuario_id = ?
            AND DATE(inicio) = CURDATE()
            ORDER BY inicio ASC
        ");
        $stmt->execute([$userId]);
        $breaksHoy = $stmt->fetchAll();
        
        // Calcular total de minutos en descanso
        $totalMinutosDescanso = array_sum(array_column($breaksHoy, 'duracion_minutos'));
        
        echo json_encode([
            'success' => true,
            'en_descanso' => $activeBreak ? true : false,
            'break_actual' => $activeBreak,
            'breaks_hoy' => $breaksHoy,
            'total_minutos_descanso' => $totalMinutosDescanso,
            'num_breaks' => count($breaksHoy)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}


// ============================================
// FUNCIONES PARA AUTO CLOCK-OUT (CORREGIDAS)
// ============================================

/**
 * Configurar hora de auto clock-out
 * Ahora también guarda la ubicación para usarla en el CRON
 */
function handleSetAutoClockout($data) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $enabled = $data['enabled'] ?? false;
    $time = $data['time'] ?? '18:00';
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    
    try {
        $pdo = getDBConnection();
        $userId = $_SESSION['user_id'];
        
        // Guardar enabled
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (usuario_id, setting_key, setting_value)
            VALUES (?, 'auto_clockout_enabled', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$userId, $enabled ? '1' : '0']);
        
        // Guardar hora
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (usuario_id, setting_key, setting_value)
            VALUES (?, 'auto_clockout_time', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$userId, $time]);
        
        // Guardar ubicación si se proporciona (para usar en el CRON)
        if ($latitude !== null) {
            $stmt = $pdo->prepare("
                INSERT INTO user_settings (usuario_id, setting_key, setting_value)
                VALUES (?, 'auto_clockout_latitude', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$userId, $latitude]);
        }
        
        if ($longitude !== null) {
            $stmt = $pdo->prepare("
                INSERT INTO user_settings (usuario_id, setting_key, setting_value)
                VALUES (?, 'auto_clockout_longitude', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$userId, $longitude]);
        }
        
        // Log para debug
        $locationMsg = ($latitude && $longitude) ? " con ubicación ($latitude, $longitude)" : " sin ubicación";
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuración guardada' . $locationMsg,
            'enabled' => $enabled,
            'time' => $time,
            'location_saved' => ($latitude !== null && $longitude !== null)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Obtener configuración de auto clock-out
 */
function handleGetAutoClockout() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        $userId = $_SESSION['user_id'];
        
        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value 
            FROM user_settings 
            WHERE usuario_id = ? AND setting_key LIKE 'auto_clockout_%'
        ");
        $stmt->execute([$userId]);
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        echo json_encode([
            'success' => true,
            'enabled' => ($settings['auto_clockout_enabled'] ?? '0') === '1',
            'time' => $settings['auto_clockout_time'] ?? '18:00'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Verificar y ejecutar auto clock-out
 */
function handleCheckAutoClockout() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['person_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        $jibble = new JibbleAPI();
        
        $userId = $_SESSION['user_id'];
        $personId = $_SESSION['person_id'];
        $nombre = $_SESSION['nombre'];
        
        // Obtener configuración
        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value 
            FROM user_settings 
            WHERE usuario_id = ? AND setting_key LIKE 'auto_clockout_%'
        ");
        $stmt->execute([$userId]);
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $enabled = ($settings['auto_clockout_enabled'] ?? '0') === '1';
        $configTime = $settings['auto_clockout_time'] ?? '18:00';
        
        if (!$enabled) {
            echo json_encode(['success' => true, 'action' => 'none', 'reason' => 'disabled']);
            return;
        }
        
        // Verificar si es hora de fichar salida
        $currentTime = date('H:i');
        if ($currentTime < $configTime) {
            echo json_encode(['success' => true, 'action' => 'none', 'reason' => 'not_time_yet']);
            return;
        }
        
        // Verificar si ya fichó salida hoy
        $stmt = $pdo->prepare("
            SELECT id FROM fichajes 
            WHERE usuario_id = ? 
            AND tipo = 'Out' 
            AND DATE(fecha_hora) = CURDATE()
            AND es_descanso = 0
            ORDER BY timestamp_unix DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => true, 'action' => 'none', 'reason' => 'already_clocked_out']);
            return;
        }
        
        // Verificar estado desde BD
        $esManual = (strpos($personId, 'manual_') === 0);
        $stmt = $pdo->prepare("
            SELECT tipo FROM fichajes
            WHERE usuario_id = ? AND es_descanso = 0
            ORDER BY fecha_hora DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $ul = $stmt->fetch();
        if (!$ul || $ul['tipo'] !== 'In') {
            echo json_encode(['success' => true, 'action' => 'none', 'reason' => 'already_out']);
            return;
        }

        // Ejecutar clock-out
        $result = false;
        try {
            $result = $esManual ? true : $jibble->clockOut($personId);
        } catch (Exception $e) {
            $result = true; // Si Jibble falla, igualmente registrar en BD
        }
        
        if ($result) {
            $fechaHora = date('Y-m-d H:i:s');
            $timestamp = time();
            
            $stmt = $pdo->prepare("
                SELECT id, timestamp_unix FROM fichajes 
                WHERE usuario_id = ? AND tipo = 'In' AND DATE(fecha_hora) = CURDATE()
                ORDER BY timestamp_unix DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $entrada = $stmt->fetch();
            
            $horasTrabajadas = null;
            $minutosTrabajados = null;
            
            if ($entrada) {
                $segundos = $timestamp - $entrada['timestamp_unix'];
                $minutosTrabajados = floor($segundos / 60);
                $horasTrabajadas = round($minutosTrabajados / 60, 2);
                
                $stmt = $pdo->prepare("
                    UPDATE fichajes SET horas_trabajadas = ?, minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$horasTrabajadas, $minutosTrabajados, $entrada['id']]);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO fichajes (
                    usuario_id, jibble_person_id, nombre, tipo, fecha_hora, 
                    timestamp_unix, nota, dispositivo, fichaje_entrada_id,
                    horas_trabajadas, minutos_trabajados, auto_clockout
                )
                VALUES (?, ?, ?, 'Out', ?, ?, 'Auto Clock-Out', 'Sistema', ?, ?, ?, 1)
            ");
            $stmt->execute([
                $userId, $personId, $nombre, 
                $fechaHora, $timestamp, $entrada['id'] ?? null,
                $horasTrabajadas, $minutosTrabajados
            ]);
            
            actualizarResumenDiario($pdo, $userId, $personId, $nombre, date('Y-m-d'));
            
            echo json_encode([
                'success' => true,
                'action' => 'clocked_out',
                'time' => $fechaHora,
                'hours_worked' => $horasTrabajadas
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al ejecutar auto clock-out']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}



// ============================================
// FUNCIÓN: Generar Reporte
// ============================================

function handleGenerarReporte() {
    $fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    $usuarioId = $_GET['usuario_id'] ?? 'todos';
    
    try {
        $pdo = getDBConnection();
        
        // Query base
        $whereClause = "DATE(f.fecha_hora) BETWEEN ? AND ?";
        $params = [$fechaDesde, $fechaHasta];
        
        if ($usuarioId !== 'todos') {
            $whereClause .= " AND f.usuario_id = ?";
            $params[] = $usuarioId;
        }
        
        // Obtener fichajes
        $stmt = $pdo->prepare("
            SELECT 
                f.id,
                f.usuario_id,
                f.nombre,
                f.tipo,
                f.fecha_hora,
                f.nota,
                f.horas_trabajadas,
                f.minutos_trabajados,
                f.dispositivo,
                f.es_descanso,
                f.auto_clockout
            FROM fichajes f
            WHERE $whereClause
            ORDER BY f.fecha_hora DESC
        ");
        $stmt->execute($params);
        $fichajes = $stmt->fetchAll();
        
        // Calcular resumen (solo sumar horas de salidas)
        $totalFichajes = count($fichajes);
        $totalHoras = 0;
        foreach ($fichajes as $f) {
            if ($f['tipo'] === 'Out' && $f['horas_trabajadas']) {
                $totalHoras += floatval($f['horas_trabajadas']);
            }
        }
        
        // Usuarios únicos
        $usuariosUnicos = array_unique(array_column($fichajes, 'usuario_id'));
        $totalUsuarios = count($usuariosUnicos);
        
        // Días laborables (días con al menos un fichaje)
        $diasConFichajes = array_unique(array_map(function($f) {
            return date('Y-m-d', strtotime($f['fecha_hora']));
        }, $fichajes));
        $diasLaborables = count($diasConFichajes);
        
        // Resumen por usuario
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.nombre,
                COUNT(f.id) as total_fichajes,
                SUM(CASE WHEN f.tipo = 'Out' THEN f.horas_trabajadas ELSE 0 END) as total_horas,
                COUNT(DISTINCT DATE(f.fecha_hora)) as dias_trabajados
            FROM usuarios u
            LEFT JOIN fichajes f ON u.id = f.usuario_id
            WHERE " . ($usuarioId !== 'todos' ? "u.id = ? AND " : "") . "
                DATE(f.fecha_hora) BETWEEN ? AND ?
            GROUP BY u.id, u.nombre
            HAVING total_fichajes > 0
            ORDER BY total_horas DESC
        ");
        
        $porUsuarioParams = $usuarioId !== 'todos' ? [$usuarioId, $fechaDesde, $fechaHasta] : [$fechaDesde, $fechaHasta];
        $stmt->execute($porUsuarioParams);
        $porUsuario = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'fichajes' => $fichajes,
            'periodo' => [
                'desde' => $fechaDesde,
                'hasta' => $fechaHasta
            ],
            'resumen' => [
                'total_fichajes' => $totalFichajes,
                'total_usuarios' => $totalUsuarios,
                'total_horas' => round($totalHoras, 2),
                'dias_laborables' => $diasLaborables
            ],
            'por_usuario' => $porUsuario
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// ============================================
// FUNCIÓN: Estadísticas Generales
// ============================================
function handleGetEstadisticasGenerales() {
    try {
        $pdo = getDBConnection();
        
        // ==========================================
        // OBTENER FILTROS
        // ==========================================
        
        $periodo = $_GET['periodo'] ?? 'month';
        $usuarioFiltro = $_GET['usuario'] ?? 'all';
        $fechaInicio = $_GET['fecha_inicio'] ?? null;
        $fechaFin = $_GET['fecha_fin'] ?? null;
        
        // ==========================================
        // CALCULAR FECHAS SEGÚN PERÍODO
        // ==========================================
        
        $hoy = new DateTime();
        
        switch($periodo) {
            case 'today':
                $inicioCalculo = new DateTime();
                $inicioCalculo->setTime(0, 0, 0);
                break;
                
            case 'week':
                $diaSemana = $hoy->format('N');
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-' . ($diaSemana - 1) . ' days');
                $inicioCalculo->setTime(0, 0, 0);
                break;
                
            case 'month':
                $inicioCalculo = new DateTime();
                $inicioCalculo->modify('first day of this month');
                $inicioCalculo->setTime(0, 0, 0);
                break;
                
            case 'custom':
                if ($fechaInicio && $fechaFin) {
                    $inicioCalculo = new DateTime($fechaInicio);
                    $finCalculo = new DateTime($fechaFin);
                    $finCalculo->setTime(23, 59, 59);
                } else {
                    $inicioCalculo = new DateTime();
                    $inicioCalculo->modify('first day of this month');
                    $inicioCalculo->setTime(0, 0, 0);
                }
                break;
                
            case 'last7':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-7 days');
                break;
                
            case 'last30':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-30 days');
                break;
                
            case 'last90':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-90 days');
                break;
                
            default:
                $inicioCalculo = new DateTime();
                $inicioCalculo->modify('first day of this month');
                $inicioCalculo->setTime(0, 0, 0);
        }
        
        // Fecha de inicio de semana (para cálculo de "semana en vigor")
        $diaSemana = $hoy->format('N');
        $inicioSemana = clone $hoy;
        $inicioSemana->modify('-' . ($diaSemana - 1) . ' days');
        $inicioSemana->setTime(0, 0, 0);
        
        // Fecha de inicio de mes (para cálculo de "mes en vigor")
        $inicioMes = new DateTime();
        $inicioMes->modify('first day of this month');
        $inicioMes->setTime(0, 0, 0);
        
        // ==========================================
        // ESTADÍSTICAS GENERALES (SIN FILTROS)
        // ==========================================
        
        // Total fichajes (este mes por defecto)
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM fichajes 
            WHERE YEAR(fecha_hora) = YEAR(CURDATE()) 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
        ");
        $totalFichajes = $stmt->fetch()['total'];
        
        // Fichajes hoy
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM fichajes WHERE DATE(fecha_hora) = CURDATE()");
        $fichajesHoy = $stmt->fetch()['total'];
        
        // Usuarios activos
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1");
        $usuariosActivos = $stmt->fetch()['total'];
        
        // Horas este mes
        $stmt = $pdo->query("
            SELECT SUM(CASE WHEN tipo = 'Out' THEN horas_trabajadas ELSE 0 END) as total 
            FROM fichajes 
            WHERE YEAR(fecha_hora) = YEAR(CURDATE()) 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
        ");
        $horasMes = $stmt->fetch()['total'] ?? 0;
        
        // ==========================================
        // ESTADÍSTICAS POR USUARIO (CON FILTROS)
        // ==========================================
        
        $whereConditions = [];
        $params = [];
        
        // Filtro de fecha (según período seleccionado)
        if ($periodo !== 'all') {
            $whereConditions[] = "f.fecha_hora >= ?";
            $params[] = $inicioCalculo->format('Y-m-d H:i:s');
            
            if (isset($finCalculo)) {
                $whereConditions[] = "f.fecha_hora <= ?";
                $params[] = $finCalculo->format('Y-m-d H:i:s');
            }
        }
        
        // Filtro de usuario
        if ($usuarioFiltro !== 'all') {
            $whereConditions[] = "u.id = ?";
            $params[] = $usuarioFiltro;
        }
        
        $whereClause = count($whereConditions) > 0 ? 'AND ' . implode(' AND ', $whereConditions) : '';
        
        // Query con placeholders
        $sql = "SELECT 
            u.id,
            u.nombre,
            COUNT(f.id) as total_fichajes,
            COALESCE(SUM(CASE WHEN f.tipo = 'Out' THEN f.horas_trabajadas ELSE 0 END), 0) as total_horas,
            
            -- Horas de la SEMANA ACTUAL
            COALESCE(SUM(
                CASE 
                    WHEN f.fecha_hora >= ? AND f.tipo = 'Out'
                    THEN f.horas_trabajadas
                    ELSE 0 
                END
            ), 0) as horas_semana,
            
            -- Horas del MES ACTUAL
            COALESCE(SUM(
                CASE 
                    WHEN f.fecha_hora >= ?
                    THEN f.horas_trabajadas
                    ELSE 0 
                END
            ), 0) as horas_mes_usuario
            
        FROM usuarios u
        LEFT JOIN fichajes f ON u.id = f.usuario_id
        WHERE u.activo = 1 " . $whereClause . "
        GROUP BY u.id, u.nombre
        HAVING total_fichajes > 0
        ORDER BY total_horas DESC";
        
        // Combinar parámetros: primero semana y mes, luego filtros
        $allParams = array_merge(
            [$inicioSemana->format('Y-m-d H:i:s'), $inicioMes->format('Y-m-d H:i:s')],
            $params
        );
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        
        $porUsuario = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $porUsuario[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre'],
                'total_fichajes' => (int)$row['total_fichajes'],
                'total_horas' => number_format($row['total_horas'], 1),
                'horas_semana' => number_format($row['horas_semana'], 1),
                'horas_mes' => number_format($row['horas_mes_usuario'], 1)
            ];
        }
        
        // ==========================================
        // FICHAJES POR DÍA (últimos 30 días)
        // ==========================================
        
        $stmt = $pdo->query("
            SELECT 
                DATE(fecha_hora) as fecha,
                COUNT(*) as total
            FROM fichajes
            WHERE fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(fecha_hora)
            ORDER BY fecha DESC
        ");
        $porDia = $stmt->fetchAll();
        
        // ==========================================
        // RESPUESTA
        // ==========================================
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'total_fichajes' => $totalFichajes,
                'fichajes_hoy' => $fichajesHoy,
                'usuarios_activos' => $usuariosActivos,
                'horas_mes' => round($horasMes, 1),
                'por_usuario' => $porUsuario,
                'por_dia' => $porDia
            ],
            'filtros_aplicados' => [
                'periodo' => $periodo,
                'usuario' => $usuarioFiltro,
                'fecha_inicio' => $inicioCalculo->format('Y-m-d'),
                'fecha_fin' => isset($finCalculo) ? $finCalculo->format('Y-m-d') : $hoy->format('Y-m-d')
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// ============================================
// FUNCIÓN: Exportar Datos
// ============================================

function handleExportarDatos() {
    $formato = $_GET['formato'] ?? 'csv';
    
    try {
        $pdo = getDBConnection();
        
        // Obtener todos los fichajes
        $stmt = $pdo->query("
            SELECT 
                f.id,
                f.nombre as usuario,
                f.tipo,
                f.fecha_hora,
                f.horas_trabajadas,
                f.minutos_trabajados,
                f.dispositivo,
                f.nota,
                f.es_descanso,
                f.auto_clockout
            FROM fichajes f
            ORDER BY f.fecha_hora DESC
        ");
        $fichajes = $stmt->fetchAll();
        
        if ($formato === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="fichajes_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // BOM para UTF-8
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Cabeceras
            fputcsv($output, ['ID', 'Usuario', 'Tipo', 'Fecha', 'Hora', 'Horas Trabajadas', 'Dispositivo', 'Nota', 'Descanso', 'Auto Clock-Out']);
            
            // Datos
            foreach ($fichajes as $f) {
                $fecha = new DateTime($f['fecha_hora']);
                fputcsv($output, [
                    $f['id'],
                    $f['usuario'],
                    $f['tipo'],
                    $fecha->format('Y-m-d'),
                    $fecha->format('H:i:s'),
                    $f['horas_trabajadas'] ?? '',
                    $f['dispositivo'] ?? '',
                    $f['nota'] ?? '',
                    $f['es_descanso'] ? 'Sí' : 'No',
                    $f['auto_clockout'] ? 'Sí' : 'No'
                ]);
            }
            
            fclose($output);
            exit;
            
        } elseif ($formato === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="fichajes_' . date('Y-m-d') . '.json"');
            
            echo json_encode([
                'exportado_en' => date('Y-m-d H:i:s'),
                'total_registros' => count($fichajes),
                'fichajes' => $fichajes
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
            
        } elseif ($formato === 'xlsx') {
            // Para Excel necesitarías una librería como PhpSpreadsheet
            // Por ahora, devolver CSV que Excel puede abrir
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="fichajes_' . date('Y-m-d') . '.xls"');
            
            echo '<table border="1">';
            echo '<tr>';
            echo '<th>ID</th><th>Usuario</th><th>Tipo</th><th>Fecha</th><th>Hora</th>';
            echo '<th>Horas</th><th>Dispositivo</th><th>Nota</th><th>Descanso</th>';
            echo '</tr>';
            
            foreach ($fichajes as $f) {
                $fecha = new DateTime($f['fecha_hora']);
                echo '<tr>';
                echo '<td>' . $f['id'] . '</td>';
                echo '<td>' . htmlspecialchars($f['usuario']) . '</td>';
                echo '<td>' . $f['tipo'] . '</td>';
                echo '<td>' . $fecha->format('Y-m-d') . '</td>';
                echo '<td>' . $fecha->format('H:i:s') . '</td>';
                echo '<td>' . ($f['horas_trabajadas'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($f['dispositivo'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($f['nota'] ?? '') . '</td>';
                echo '<td>' . ($f['es_descanso'] ? 'Sí' : 'No') . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            exit;
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}


function handleGetUsersClockedIn() {
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->query("
            SELECT id, jibble_person_id, nombre, email
            FROM usuarios
            WHERE activo = 1
            ORDER BY nombre ASC
        ");
        $usuarios = $stmt->fetchAll();
        
        $usuariosFichados = [];
        $jibble = null;

        foreach ($usuarios as $user) {
            try {
                $esManual = (strpos($user['jibble_person_id'], 'manual_') === 0);

                if ($esManual) {
                    $stmt2 = $pdo->prepare("
                        SELECT tipo FROM fichajes
                        WHERE usuario_id = ? AND es_descanso = 0
                        ORDER BY fecha_hora DESC LIMIT 1
                    ");
                    $stmt2->execute([$user['id']]);
                    $ul = $stmt2->fetch();
                    $estaIn = ($ul && $ul['tipo'] === 'In');
                } else {
                    if ($jibble === null) $jibble = new JibbleAPI();
                    $status = $jibble->getStatus($user['jibble_person_id']);
                    $estaIn = ($status['status'] === 'in');
                }

                if ($estaIn) {
                    $stmt3 = $pdo->prepare("
                        SELECT fecha_hora, nota
                        FROM fichajes
                        WHERE usuario_id = ?
                        AND tipo = 'In'
                        AND es_descanso = 0
                        ORDER BY fecha_hora DESC
                        LIMIT 1
                    ");
                    $stmt3->execute([$user['id']]);
                    $ultimaEntrada = $stmt3->fetch();

                    $usuariosFichados[] = [
                        'usuario_id' => $user['id'],
                        'person_id'  => $user['jibble_person_id'],
                        'nombre'     => $user['nombre'],
                        'email'      => $user['email'],
                        'entrada'    => $ultimaEntrada ? $ultimaEntrada['fecha_hora'] : null,
                        'nota_entrada' => $ultimaEntrada ? $ultimaEntrada['nota'] : null
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }

        echo json_encode([
            'success' => true,
            'usuarios' => $usuariosFichados,
            'total'    => count($usuariosFichados)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function handleManualClockoutUser($data) {
    $usuarioId = $data['usuario_id'] ?? null;
    $nota = $data['nota'] ?? 'Desfichaje manual por admin';
    
    if (!$usuarioId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'usuario_id requerido']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Obtener datos del usuario
        $stmt = $pdo->prepare("
            SELECT id, jibble_person_id, nombre
            FROM usuarios
            WHERE id = ?
        ");
        $stmt->execute([$usuarioId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            return;
        }
        
        $esManual = (strpos($user['jibble_person_id'], 'manual_') === 0);
        $result = false;

        if ($esManual) {
            // Usuario manual — verificar estado en BD local
            $stmt2 = $pdo->prepare("
                SELECT tipo FROM fichajes
                WHERE usuario_id = ? AND es_descanso = 0
                ORDER BY fecha_hora DESC LIMIT 1
            ");
            $stmt2->execute([$user['id']]);
            $ul = $stmt2->fetch();
            if (!$ul || $ul['tipo'] !== 'In') {
                echo json_encode(['success' => false, 'error' => 'El usuario no está fichado']);
                return;
            }
            $result = true;
        } else {
            // Usuario Jibble — verificar y desfichar en Jibble
            $jibble = new JibbleAPI();
            $status = $jibble->getStatus($user['jibble_person_id']);
            if ($status['status'] !== 'in') {
                echo json_encode(['success' => false, 'error' => 'El usuario no está fichado']);
                return;
            }
            $result = $jibble->clockOut($user['jibble_person_id']);
        }
        
        if ($result) {
            // Buscar última entrada sin salida
            $stmt = $pdo->prepare("
                SELECT id, timestamp_unix
                FROM fichajes
                WHERE usuario_id = ?
                AND tipo = 'In'
                AND es_descanso = 0
                ORDER BY fecha_hora DESC
                LIMIT 1
            ");
            $stmt->execute([$usuarioId]);
            $ultimaEntrada = $stmt->fetch();
            
            $horasTrabajadas = null;
            $minutosTrabajados = null;
            $entradaId = null;
            
            if ($ultimaEntrada) {
                $entradaId = $ultimaEntrada['id'];
                $segundosTrabajados = time() - $ultimaEntrada['timestamp_unix'];
                $minutosTrabajados = floor($segundosTrabajados / 60);
                $horasTrabajadas = round($minutosTrabajados / 60, 2);
                
                $stmt = $pdo->prepare("
                    UPDATE fichajes
                    SET horas_trabajadas = ?, minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$horasTrabajadas, $minutosTrabajados, $entradaId]);
            }
            
            // Registrar fichaje de salida
            $timestamp = time();
            $stmt = $pdo->prepare("
                INSERT INTO fichajes (
                    usuario_id, jibble_person_id, nombre, tipo,
                    fecha_hora, timestamp_unix, dispositivo, nota,
                    fichaje_entrada_id, horas_trabajadas, minutos_trabajados,
                    sincronizado_jibble
                ) VALUES (?, ?, ?, 'Out', NOW(), ?, 'Admin Manual', ?, ?, ?, ?, 0)
            ");
            
            $stmt->execute([
                $usuarioId,
                $user['jibble_person_id'],
                $user['nombre'],
                $timestamp,
                $nota,
                $entradaId,
                $horasTrabajadas,
                $minutosTrabajados
            ]);
            
            $fichajeId = $pdo->lastInsertId();
            
            actualizarResumenDiario($pdo, $usuarioId, $user['jibble_person_id'], $user['nombre'], date('Y-m-d'));
            
            echo json_encode([
                'success' => true,
                'message' => "Usuario {$user['nombre']} desfichado correctamente",
                'horas_trabajadas' => $horasTrabajadas,
                'timestamp' => date('Y-m-d H:i:s'),
                'fichaje_id' => $fichajeId
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Error al desfichar en Jibble'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}


function handleGetAllBreaks() {
    $fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    $usuarioId = $_GET['usuario_id'] ?? 'todos';
    
    try {
        $pdo = getDBConnection();
        
        $whereClause = "DATE(b.inicio) BETWEEN ? AND ?";
        $params = [$fechaDesde, $fechaHasta];
        
        if ($usuarioId !== 'todos') {
            $whereClause .= " AND b.usuario_id = ?";
            $params[] = $usuarioId;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.usuario_id,
                b.nombre,
                b.inicio,
                b.fin,
                b.duracion_minutos,
                b.tipo,
                b.nota,
                DATE(b.inicio) as fecha,
                TIME(b.inicio) as hora_inicio,
                TIME(b.fin) as hora_fin
            FROM breaks b
            WHERE $whereClause
            ORDER BY b.inicio DESC
        ");
        $stmt->execute($params);
        $breaks = $stmt->fetchAll();
        
        // Calcular resumen
        $totalBreaks = count($breaks);
        $totalMinutos = array_sum(array_column($breaks, 'duracion_minutos'));
        
        echo json_encode([
            'success' => true,
            'breaks' => $breaks,
            'total' => $totalBreaks,
            'total_minutos' => $totalMinutos,
            'periodo' => [
                'desde' => $fechaDesde,
                'hasta' => $fechaHasta
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Obtener descansos por usuario (resumen)
 */
function handleGetBreaksByUser() {
    $fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                b.usuario_id,
                b.nombre,
                COUNT(*) as num_breaks,
                SUM(b.duracion_minutos) as total_minutos,
                AVG(b.duracion_minutos) as promedio_minutos,
                COUNT(DISTINCT DATE(b.inicio)) as dias_con_breaks
            FROM breaks b
            WHERE DATE(b.inicio) BETWEEN ? AND ?
            GROUP BY b.usuario_id, b.nombre
            ORDER BY total_minutos DESC
        ");
        $stmt->execute([$fechaDesde, $fechaHasta]);
        $resumen = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'resumen' => $resumen,
            'periodo' => [
                'desde' => $fechaDesde,
                'hasta' => $fechaHasta
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Generar reporte completo (fichajes + descansos)
 */
function handleGenerarReporteConBreaks() {
    $fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    $usuarioId = $_GET['usuario_id'] ?? 'todos';
    
    try {
        $pdo = getDBConnection();
        
        // Query base para fichajes
        $whereClause = "DATE(f.fecha_hora) BETWEEN ? AND ?";
        $params = [$fechaDesde, $fechaHasta];
        
        if ($usuarioId !== 'todos') {
            $whereClause .= " AND f.usuario_id = ?";
            $params[] = $usuarioId;
        }
        
        // Obtener fichajes
        $stmt = $pdo->prepare("
            SELECT 
                f.id,
                f.usuario_id,
                f.nombre,
                f.tipo,
                f.fecha_hora,
                f.nota,
                f.horas_trabajadas,
                f.minutos_trabajados,
                f.dispositivo
            FROM fichajes f
            WHERE $whereClause
            ORDER BY f.fecha_hora DESC
        ");
        $stmt->execute($params);
        $fichajes = $stmt->fetchAll();
        
        // Obtener descansos
        $breakWhereClause = "DATE(b.inicio) BETWEEN ? AND ?";
        $breakParams = [$fechaDesde, $fechaHasta];
        
        if ($usuarioId !== 'todos') {
            $breakWhereClause .= " AND b.usuario_id = ?";
            $breakParams[] = $usuarioId;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                b.usuario_id,
                b.nombre,
                b.inicio,
                b.fin,
                b.duracion_minutos,
                DATE(b.inicio) as fecha
            FROM breaks b
            WHERE $breakWhereClause
            ORDER BY b.inicio ASC
        ");
        $stmt->execute($breakParams);
        $breaks = $stmt->fetchAll();
        
        // Agrupar breaks por usuario y fecha
        $breaksPorUsuarioFecha = [];
        foreach ($breaks as $break) {
            $key = $break['usuario_id'] . '_' . $break['fecha'];
            if (!isset($breaksPorUsuarioFecha[$key])) {
                $breaksPorUsuarioFecha[$key] = [
                    'usuario_id' => $break['usuario_id'],
                    'fecha' => $break['fecha'],
                    'breaks' => [],
                    'total_minutos' => 0
                ];
            }
            $breaksPorUsuarioFecha[$key]['breaks'][] = $break;
            $breaksPorUsuarioFecha[$key]['total_minutos'] += $break['duracion_minutos'];
        }
        
        // Calcular resumen general (solo sumar horas de salidas)
        $totalFichajes = count($fichajes);
        $totalHorasFichadas = 0;
        foreach ($fichajes as $f) {
            if ($f['tipo'] === 'Out' && $f['horas_trabajadas']) {
                $totalHorasFichadas += floatval($f['horas_trabajadas']);
            }
        }
        $totalMinutosBreaks = 0;
        foreach ($breaks as $break) {
            if ($break['fin'] && $break['inicio']) {
                $inicio = strtotime($break['inicio']);
                $fin = strtotime($break['fin']);
                $totalMinutosBreaks += floor(($fin - $inicio) / 60);
            }
        }
        $totalHorasNetas = $totalHorasFichadas - ($totalMinutosBreaks / 60);
        
        // Resumen por usuario (CORREGIDO: usando subconsultas para evitar producto cartesiano)
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.nombre,
                COALESCE(fichajes_data.total_fichajes, 0) as total_fichajes,
                COALESCE(fichajes_data.horas_fichadas, 0) as horas_fichadas,
                COALESCE(breaks_data.minutos_breaks, 0) as minutos_breaks,
                COALESCE(fichajes_data.horas_fichadas, 0) - (COALESCE(breaks_data.minutos_breaks, 0) / 60) as horas_netas,
                COALESCE(fichajes_data.dias_trabajados, 0) as dias_trabajados
            FROM usuarios u
            LEFT JOIN (
                SELECT 
                    usuario_id,
                    COUNT(*) as total_fichajes,
                    SUM(CASE WHEN tipo = 'Out' THEN horas_trabajadas ELSE 0 END) as horas_fichadas,
                    COUNT(DISTINCT DATE(fecha_hora)) as dias_trabajados
                FROM fichajes
                WHERE DATE(fecha_hora) BETWEEN ? AND ?
                GROUP BY usuario_id
            ) fichajes_data ON u.id = fichajes_data.usuario_id
            LEFT JOIN (
                SELECT 
                    usuario_id,
                    SUM(TIMESTAMPDIFF(MINUTE, inicio, fin)) as minutos_breaks
                FROM breaks
                WHERE DATE(inicio) BETWEEN ? AND ?
                  AND fin IS NOT NULL
                GROUP BY usuario_id
            ) breaks_data ON u.id = breaks_data.usuario_id
            WHERE " . ($usuarioId !== 'todos' ? "u.id = ? AND " : "") . "
                fichajes_data.total_fichajes IS NOT NULL
                AND fichajes_data.total_fichajes > 0
            ORDER BY horas_netas DESC
        ");
        
        $porUsuarioParams = $usuarioId !== 'todos' 
            ? [$fechaDesde, $fechaHasta, $fechaDesde, $fechaHasta, $usuarioId]
            : [$fechaDesde, $fechaHasta, $fechaDesde, $fechaHasta];
        
        $stmt->execute($porUsuarioParams);
        $porUsuario = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'fichajes' => $fichajes,
            'breaks' => $breaks,
            'breaks_por_usuario_fecha' => array_values($breaksPorUsuarioFecha),
            'periodo' => [
                'desde' => $fechaDesde,
                'hasta' => $fechaHasta
            ],
            'resumen' => [
                'total_fichajes' => $totalFichajes,
                'total_usuarios' => count($porUsuario),
                'horas_fichadas' => round($totalHorasFichadas, 2),
                'minutos_breaks' => $totalMinutosBreaks,
                'horas_breaks' => round($totalMinutosBreaks / 60, 2),
                'horas_netas' => round($totalHorasNetas, 2)
            ],
            'por_usuario' => $porUsuario
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}


function handleSetGeolocationSettings($data) {
    $enabled = $data['enabled'] ?? false;
    $required = $data['required'] ?? false;
    $allowedLocations = $data['allowed_locations'] ?? null;
    $validationRadius = $data['validation_radius'] ?? 100;
    $showMap = $data['show_map'] ?? true;
    
    try {
        $pdo = getDBConnection();
        
        if ($allowedLocations !== null) {
            if (is_array($allowedLocations)) {
                $allowedLocations = json_encode($allowedLocations);
            }
        }
        
        $stmt = $pdo->prepare("
            UPDATE geolocation_settings
            SET enabled = ?,
                required = ?,
                allowed_locations = ?,
                validation_radius = ?,
                show_map = ?
            WHERE id = 1
        ");
        
        $stmt->execute([
            $enabled ? 1 : 0,
            $required ? 1 : 0,
            $allowedLocations,
            $validationRadius,
            $showMap ? 1 : 0
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuración de geolocalización actualizada'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Obtener configuración de geolocalización
 */
function handleGetGeolocationSettings() {
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->query("SELECT * FROM geolocation_settings WHERE id = 1");
        $settings = $stmt->fetch();
        
        if ($settings) {
            if ($settings['allowed_locations']) {
                $settings['allowed_locations'] = json_decode($settings['allowed_locations'], true);
            }
            
            echo json_encode([
                'success' => true,
                'settings' => $settings
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'settings' => [
                    'enabled' => false,
                    'required' => false,
                    'allowed_locations' => null,
                    'validation_radius' => 100,
                    'show_map' => true
                ]
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Validar ubicación
 */
function handleValidateLocation($data) {
    $latitud = $data['latitude'] ?? null;
    $longitud = $data['longitude'] ?? null;
    
    if (!$latitud || !$longitud) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Coordenadas requeridas'
        ]);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->query("SELECT * FROM geolocation_settings WHERE id = 1");
        $settings = $stmt->fetch();
        
        if (!$settings || !$settings['enabled']) {
            echo json_encode([
                'success' => true,
                'valid' => true,
                'message' => 'Geolocalización desactivada'
            ]);
            return;
        }
        
        if (!$settings['allowed_locations']) {
            echo json_encode([
                'success' => true,
                'valid' => true,
                'message' => 'Ubicación aceptada',
                'distance' => null
            ]);
            return;
        }
        
        $allowedLocations = json_decode($settings['allowed_locations'], true);
        $radius = $settings['validation_radius'];
        
        $minDistance = PHP_INT_MAX;
        $nearestLocation = null;
        
        foreach ($allowedLocations as $location) {
            $distance = calculateDistance(
                $latitud, $longitud,
                $location['latitude'], $location['longitude']
            );
            
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestLocation = $location;
            }
        }
        
        $valid = $minDistance <= $radius;
        
        echo json_encode([
            'success' => true,
            'valid' => $valid,
            'distance' => round($minDistance),
            'nearest_location' => $nearestLocation['name'] ?? 'Ubicación principal',
            'allowed_radius' => $radius,
            'message' => $valid ? 
                "Ubicación válida (a {$minDistance}m de {$nearestLocation['name']})" : 
                "Ubicación no válida (a {$minDistance}m, máximo {$radius}m permitido)"
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Calcular distancia entre dos puntos (fórmula Haversine)
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}

/**
 * Validar ubicación interna
 */
function validateLocationInternal($lat, $lon, $settings) {
    // Si no hay ubicaciones configuradas, ACEPTAR cualquier ubicación
    if (!$settings['allowed_locations'] || $settings['allowed_locations'] === 'null' || $settings['allowed_locations'] === '[]') {
        return [
            'valid' => true, 
            'distance' => null, 
            'message' => 'Ubicación aceptada (sin restricciones configuradas)'
        ];
    }
    
    $allowedLocations = json_decode($settings['allowed_locations'], true);
    
    // Si el JSON está vacío, aceptar
    if (empty($allowedLocations)) {
        return [
            'valid' => true, 
            'distance' => null, 
            'message' => 'Ubicación aceptada (sin restricciones configuradas)'
        ];
    }
    
    $radius = $settings['validation_radius'];
    
    $minDistance = PHP_INT_MAX;
    $nearestLocation = null;
    
    foreach ($allowedLocations as $location) {
        $distance = calculateDistance(
            $lat, $lon,
            $location['latitude'], $location['longitude']
        );
        
        if ($distance < $minDistance) {
            $minDistance = $distance;
            $nearestLocation = $location;
        }
    }
    
    $valid = $minDistance <= $radius;
    
    return [
        'valid' => $valid,
        'distance' => round($minDistance),
        'nearest_location' => $nearestLocation['name'] ?? 'Ubicación principal',
        'message' => $valid ? 
            "Ubicación válida" : 
            "Fuera del área permitida ({$minDistance}m, máximo {$radius}m)"
    ];
}

// ============================================
// FUNCIÓN: Borrar Fichaje (BD + Jibble)
// ============================================

function handleDeleteFichaje($data) {
    $fichajeId = $data['fichaje_id'] ?? null;
    
    if (!$fichajeId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'ID de fichaje requerido'
        ]);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        $jibble = new JibbleAPI();
        
        // Obtener datos del fichaje
        $stmt = $pdo->prepare("
            SELECT 
                id, usuario_id, jibble_person_id, nombre, 
                tipo, fecha_hora, timestamp_unix, fichaje_entrada_id
            FROM fichajes 
            WHERE id = ?
        ");
        $stmt->execute([$fichajeId]);
        $fichaje = $stmt->fetch();
        
        if (!$fichaje) {
            echo json_encode([
                'success' => false,
                'error' => 'Fichaje no encontrado'
            ]);
            return;
        }
        
        // ============================================
        // DETERMINAR FICHAJES A ELIMINAR (entrada + salida)
        // ============================================
        
        $fichajesToDelete = [];
        $fichajesToDelete[] = $fichaje; // El fichaje principal
        
        if ($fichaje['tipo'] === 'Out') {
            // Es una salida -> buscar su entrada asociada
            if ($fichaje['fichaje_entrada_id']) {
                $stmt = $pdo->prepare("SELECT * FROM fichajes WHERE id = ?");
                $stmt->execute([$fichaje['fichaje_entrada_id']]);
                $entradaAsociada = $stmt->fetch();
                if ($entradaAsociada) {
                    $fichajesToDelete[] = $entradaAsociada;
                }
            }
        } else {
            // Es una entrada -> buscar su salida asociada
            $stmt = $pdo->prepare("
                SELECT * FROM fichajes 
                WHERE fichaje_entrada_id = ? 
                OR (usuario_id = ? AND tipo = 'Out' AND DATE(fecha_hora) = DATE(?) AND timestamp_unix > ? AND es_descanso = 0)
                ORDER BY timestamp_unix ASC
                LIMIT 1
            ");
            $stmt->execute([
                $fichaje['id'],
                $fichaje['usuario_id'],
                $fichaje['fecha_hora'],
                $fichaje['timestamp_unix']
            ]);
            $salidaAsociada = $stmt->fetch();
            if ($salidaAsociada) {
                $fichajesToDelete[] = $salidaAsociada;
            }
        }
        
        // ============================================
        // BORRAR DE JIBBLE TODOS LOS FICHAJES
        // ============================================
        
        $resultadosJibble = [];
        
        foreach ($fichajesToDelete as $fich) {
            $borradoJibble = false;
            $mensajeJibble = 'No se intentó borrar de Jibble';
            
            try {
                $fechaFichaje = date('Y-m-d', strtotime($fich['fecha_hora']));
                
                $entries = $jibble->getTimeEntriesByDate(
                    $fich['jibble_person_id'], 
                    $fechaFichaje
                );
                
                if ($entries && count($entries) > 0) {
                    $timestampBuscar = $fich['timestamp_unix'];
                    
                    foreach ($entries as $entry) {
                        $entryTime = $entry['time'] ?? null;
                        
                        if ($entryTime) {
                            $entryTimestamp = strtotime($entryTime);
                            
                            // Margen de 60 segundos
                            if (abs($entryTimestamp - $timestampBuscar) <= 60) {
                                if (isset($entry['id'])) {
                                    $borradoJibble = $jibble->deleteTimeEntry($entry['id']);
                                    $mensajeJibble = $borradoJibble ? 
                                        '✅ Eliminado de Jibble' : 
                                        '⚠️ No se pudo eliminar de Jibble';
                                    break;
                                }
                            }
                        }
                    }
                    
                    if (!$borradoJibble && $mensajeJibble === 'No se intentó borrar de Jibble') {
                        $mensajeJibble = '⚠️ No encontrado en Jibble';
                    }
                } else {
                    $mensajeJibble = '⚠️ No hay registros en Jibble para esa fecha';
                }
                
            } catch (Exception $e) {
                $mensajeJibble = '❌ Error: ' . $e->getMessage();
            }
            
            $resultadosJibble[] = [
                'id' => $fich['id'],
                'tipo' => $fich['tipo'],
                'jibble' => $mensajeJibble
            ];
        }
        
        // ============================================
        // BORRAR DE BASE DE DATOS LOCAL
        // ============================================
        
        $idsEliminados = [];
        
        foreach ($fichajesToDelete as $fich) {
            // Limpiar referencias
            $stmt = $pdo->prepare("
                UPDATE fichajes 
                SET horas_trabajadas = NULL, minutos_trabajados = NULL, fichaje_entrada_id = NULL
                WHERE fichaje_entrada_id = ?
            ");
            $stmt->execute([$fich['id']]);
            
            // Borrar el fichaje
            $stmt = $pdo->prepare("DELETE FROM fichajes WHERE id = ?");
            $stmt->execute([$fich['id']]);
            
            $idsEliminados[] = $fich['id'];
        }
        
        // Log
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, success) 
            VALUES (?, ?, 1)
        ");
        $logMessage = sprintf(
            "Fichajes eliminados - IDs: %s, Usuario: %s, Tipos: %s",
            implode(', ', $idsEliminados),
            $fichaje['nombre'],
            implode(' + ', array_column($fichajesToDelete, 'tipo'))
        );
        $stmt->execute(['delete_fichaje', $logMessage]);
        
        // Recalcular resumen
        try {
            $fecha = date('Y-m-d', strtotime($fichaje['fecha_hora']));
            actualizarResumenDiario($pdo, $fichaje['usuario_id'], $fichaje['jibble_person_id'], $fichaje['nombre'], $fecha);
        } catch (Exception $e) {
            error_log("Error actualizando resumen: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => count($idsEliminados) > 1 
                ? 'Entrada y salida eliminadas correctamente' 
                : 'Fichaje eliminado correctamente',
            'fichajes_eliminados' => $idsEliminados,
            'resultados_jibble' => $resultadosJibble
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage()
        ]);
    }
}

// ============================================
// FUNCIÓN: Editar Fichaje (cambiar hora)
// Recalcula automáticamente las horas trabajadas
// ============================================

function handleEditFichaje($data) {
    $fichajeId = $data['fichaje_id'] ?? null;
    $nuevaHora = $data['nueva_hora'] ?? null;
    $entradaSeleccionada = $data['entrada_seleccionada'] ?? null; // Para resolución manual
    $salidaSeleccionada = $data['salida_seleccionada'] ?? null;   // Para resolución manual
    
    if (!$fichajeId || !$nuevaHora) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'ID de fichaje y nueva hora son requeridos'
        ]);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Obtener datos del fichaje
        $stmt = $pdo->prepare("
            SELECT id, usuario_id, jibble_person_id, nombre, 
                   tipo, fecha_hora, timestamp_unix, fichaje_entrada_id
            FROM fichajes WHERE id = ?
        ");
        $stmt->execute([$fichajeId]);
        $fichaje = $stmt->fetch();
        
        if (!$fichaje) {
            echo json_encode(['success' => false, 'error' => 'Fichaje no encontrado']);
            return;
        }
        
        // Procesar la nueva fecha/hora
        $fechaOriginal = date('Y-m-d', strtotime($fichaje['fecha_hora']));
        
        if (preg_match('/^\d{2}:\d{2}$/', $nuevaHora)) {
            $nuevaFechaHora = $fechaOriginal . ' ' . $nuevaHora . ':00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $nuevaHora)) {
            $nuevaFechaHora = $nuevaHora . ':00';
        } else {
            echo json_encode(['success' => false, 'error' => 'Formato de hora inválido']);
            return;
        }
        
        $nuevoTimestamp = strtotime($nuevaFechaHora);
        $nuevaFecha = date('Y-m-d', $nuevoTimestamp);
        
        // ============================================
        // PASO 1: Actualizar la hora del fichaje
        // ============================================
        $stmt = $pdo->prepare("UPDATE fichajes SET fecha_hora = ?, timestamp_unix = ? WHERE id = ?");
        $stmt->execute([$nuevaFechaHora, $nuevoTimestamp, $fichajeId]);
        
        // ============================================
        // PASO 2: Detectar conflictos (múltiples fichajes)
        // ============================================
        
        if ($fichaje['tipo'] === 'Out') {
            // Es una SALIDA - buscar entradas del mismo día
            $stmt = $pdo->prepare("
                SELECT id, TIME(fecha_hora) as hora, timestamp_unix
                FROM fichajes 
                WHERE usuario_id = ? 
                AND tipo = 'In' 
                AND DATE(fecha_hora) = ?
                AND timestamp_unix < ?
                AND es_descanso = 0
                ORDER BY timestamp_unix DESC
            ");
            $stmt->execute([$fichaje['usuario_id'], $nuevaFecha, $nuevoTimestamp]);
            $entradasPosibles = $stmt->fetchAll();
            
            // Si hay múltiples entradas Y NO se especificó cuál elegir → Pedir al admin
            if (count($entradasPosibles) > 1 && $entradaSeleccionada === null) {
                echo json_encode([
                    'success' => false,
                    'requiere_seleccion' => true,
                    'tipo_conflicto' => 'multiples_entradas',
                    'fichaje_editado' => [
                        'id' => $fichajeId,
                        'tipo' => 'Out',
                        'fecha' => $nuevaFecha,
                        'hora' => date('H:i', $nuevoTimestamp)
                    ],
                    'opciones' => array_map(function($e) {
                        return [
                            'id' => $e['id'],
                            'hora' => $e['hora'],
                            'timestamp' => $e['timestamp_unix']
                        ];
                    }, $entradasPosibles),
                    'mensaje' => 'Hay múltiples entradas ese día. ¿Con cuál quieres asociar esta salida?'
                ]);
                return;
            }
            
            // Si solo hay 1 entrada O ya se seleccionó una
            if (count($entradasPosibles) === 1) {
                $entradaId = $entradasPosibles[0]['id'];
                $entradaTimestamp = $entradasPosibles[0]['timestamp_unix'];
            } elseif ($entradaSeleccionada !== null) {
                // Verificar que la entrada seleccionada sea válida
                $entradaEncontrada = array_filter($entradasPosibles, function($e) use ($entradaSeleccionada) {
                    return $e['id'] == $entradaSeleccionada;
                });
                
                if (empty($entradaEncontrada)) {
                    echo json_encode(['success' => false, 'error' => 'Entrada seleccionada no válida']);
                    return;
                }
                
                $entrada = reset($entradaEncontrada);
                $entradaId = $entrada['id'];
                $entradaTimestamp = $entrada['timestamp_unix'];
            } else {
                // No hay entradas ese día
                echo json_encode([
                    'success' => true,
                    'message' => 'Fichaje actualizado (sin entrada asociada ese día)',
                    'horas_recalculadas' => null
                ]);
                return;
            }
            
            // Calcular y actualizar horas
            $minutosRecalculados = floor(($nuevoTimestamp - $entradaTimestamp) / 60);
            $horasRecalculadas = round($minutosRecalculados / 60, 2);
            
            // Actualizar SALIDA
            $pdo->prepare("
                UPDATE fichajes 
                SET horas_trabajadas = ?, minutos_trabajados = ?, fichaje_entrada_id = ? 
                WHERE id = ?
            ")->execute([$horasRecalculadas, $minutosRecalculados, $entradaId, $fichajeId]);
            
            // Actualizar ENTRADA
            $pdo->prepare("
                UPDATE fichajes 
                SET horas_trabajadas = ?, minutos_trabajados = ? 
                WHERE id = ?
            ")->execute([$horasRecalculadas, $minutosRecalculados, $entradaId]);
            
        } else {
            // Es una ENTRADA - buscar salidas del mismo día
            $stmt = $pdo->prepare("
                SELECT id, TIME(fecha_hora) as hora, timestamp_unix
                FROM fichajes 
                WHERE usuario_id = ? 
                AND tipo = 'Out' 
                AND DATE(fecha_hora) = ?
                AND timestamp_unix > ?
                AND es_descanso = 0
                ORDER BY timestamp_unix ASC
            ");
            $stmt->execute([$fichaje['usuario_id'], $nuevaFecha, $nuevoTimestamp]);
            $salidasPosibles = $stmt->fetchAll();
            
            // Si hay múltiples salidas Y NO se especificó cuál elegir → Pedir al admin
            if (count($salidasPosibles) > 1 && $salidaSeleccionada === null) {
                echo json_encode([
                    'success' => false,
                    'requiere_seleccion' => true,
                    'tipo_conflicto' => 'multiples_salidas',
                    'fichaje_editado' => [
                        'id' => $fichajeId,
                        'tipo' => 'In',
                        'fecha' => $nuevaFecha,
                        'hora' => date('H:i', $nuevoTimestamp)
                    ],
                    'opciones' => array_map(function($s) {
                        return [
                            'id' => $s['id'],
                            'hora' => $s['hora'],
                            'timestamp' => $s['timestamp_unix']
                        ];
                    }, $salidasPosibles),
                    'mensaje' => 'Hay múltiples salidas ese día. ¿Con cuál quieres asociar esta entrada?'
                ]);
                return;
            }
            
            // Si solo hay 1 salida O ya se seleccionó una
            if (count($salidasPosibles) === 1) {
                $salidaId = $salidasPosibles[0]['id'];
                $salidaTimestamp = $salidasPosibles[0]['timestamp_unix'];
            } elseif ($salidaSeleccionada !== null) {
                // Verificar que la salida seleccionada sea válida
                $salidaEncontrada = array_filter($salidasPosibles, function($s) use ($salidaSeleccionada) {
                    return $s['id'] == $salidaSeleccionada;
                });
                
                if (empty($salidaEncontrada)) {
                    echo json_encode(['success' => false, 'error' => 'Salida seleccionada no válida']);
                    return;
                }
                
                $salida = reset($salidaEncontrada);
                $salidaId = $salida['id'];
                $salidaTimestamp = $salida['timestamp_unix'];
            } else {
                // No hay salidas ese día
                echo json_encode([
                    'success' => true,
                    'message' => 'Fichaje actualizado (sin salida asociada ese día)',
                    'horas_recalculadas' => null
                ]);
                return;
            }
            
            // Calcular y actualizar horas
            $minutosRecalculados = floor(($salidaTimestamp - $nuevoTimestamp) / 60);
            $horasRecalculadas = round($minutosRecalculados / 60, 2);
            
            // Actualizar ENTRADA
            $pdo->prepare("
                UPDATE fichajes 
                SET horas_trabajadas = ?, minutos_trabajados = ? 
                WHERE id = ?
            ")->execute([$horasRecalculadas, $minutosRecalculados, $fichajeId]);
            
            // Actualizar SALIDA
            $pdo->prepare("
                UPDATE fichajes 
                SET horas_trabajadas = ?, minutos_trabajados = ?, fichaje_entrada_id = ? 
                WHERE id = ?
            ")->execute([$horasRecalculadas, $minutosRecalculados, $fichajeId, $salidaId]);
        }
        
        // ============================================
        // PASO 3: Actualizar resumen diario
        // ============================================
        actualizarResumenDiario($pdo, $fichaje['usuario_id'], $fichaje['jibble_person_id'], $fichaje['nombre'], $nuevaFecha);
        
        // Si cambió de fecha, actualizar también el día anterior
        if ($fechaOriginal !== $nuevaFecha) {
            actualizarResumenDiario($pdo, $fichaje['usuario_id'], $fichaje['jibble_person_id'], $fichaje['nombre'], $fechaOriginal);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Fichaje actualizado correctamente',
            'fichaje_id' => $fichajeId,
            'nueva_fecha_hora' => $nuevaFechaHora,
            'horas_recalculadas' => $horasRecalculadas ?? null
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}

// ============================================
// FUNCIÓN: Añadir Fichaje Manual
// Permite crear entradas/salidas manualmente desde admin
// ============================================

function handleAddFichajeManual($data) {
    requireAdmin(); // Solo admins pueden añadir fichajes
    
    $usuarioId = $data['usuario_id'] ?? null;
    $tipo = $data['tipo'] ?? null; // 'In' o 'Out'
    $fechaHora = $data['fecha_hora'] ?? null;
    $nota = $data['nota'] ?? 'Fichaje manual por admin';
    $latitud = $data['latitud'] ?? null;
    $longitud = $data['longitud'] ?? null;
    $ubicacionValidada = $data['ubicacion_validada'] ?? null;
    $distanciaMetros = $data['distancia_metros'] ?? null;
    
    if (!$usuarioId || !$tipo || !$fechaHora) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Usuario, tipo y fecha/hora son requeridos'
        ]);
        return;
    }
    
    // Validar tipo
    if (!in_array($tipo, ['In', 'Out'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Tipo debe ser "In" o "Out"'
        ]);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Obtener datos del usuario
        $stmt = $pdo->prepare("
            SELECT id, jibble_person_id, nombre, email
            FROM usuarios 
            WHERE id = ?
        ");
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            return;
        }
        
        // Validar formato de fecha
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $fechaHora)) {
            $fechaHoraCompleta = $fechaHora . ':00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fechaHora)) {
            $fechaHoraCompleta = $fechaHora;
        } else {
            echo json_encode(['success' => false, 'error' => 'Formato de fecha/hora inválido. Use: YYYY-MM-DD HH:MM']);
            return;
        }
        
        $timestamp = strtotime($fechaHoraCompleta);
        $fecha = date('Y-m-d', $timestamp);
        
        // ============================================
        // VALIDACIONES ANTES DE INSERTAR
        // ============================================
        
        // 1. Verificar que no haya un fichaje idéntico (mismo usuario, tipo, y tiempo cercano)
        $stmt = $pdo->prepare("
            SELECT id 
            FROM fichajes 
            WHERE usuario_id = ? 
            AND tipo = ?
            AND ABS(timestamp_unix - ?) <= 60
        ");
        $stmt->execute([$usuarioId, $tipo, $timestamp]);
        
        if ($stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'error' => 'Ya existe un fichaje similar (mismo tipo en el mismo minuto)'
            ]);
            return;
        }
        
        // 2. Si es una salida, verificar que haya una entrada previa ese día
        if ($tipo === 'Out') {
            $stmt = $pdo->prepare("
                SELECT id, timestamp_unix
                FROM fichajes 
                WHERE usuario_id = ? 
                AND tipo = 'In'
                AND DATE(fecha_hora) = ?
                AND timestamp_unix < ?
                AND es_descanso = 0
                ORDER BY timestamp_unix DESC
                LIMIT 1
            ");
            $stmt->execute([$usuarioId, $fecha, $timestamp]);
            $entradaPrevia = $stmt->fetch();
            
            if (!$entradaPrevia) {
                // No hay entrada previa, pero permitir de todas formas (será sin asociar)
                $entradaId = null;
                $horasTrabajadas = null;
                $minutosTrabajados = null;
            } else {
                $entradaId = $entradaPrevia['id'];
                $segundos = $timestamp - $entradaPrevia['timestamp_unix'];
                $minutosTrabajados = floor($segundos / 60);
                $horasTrabajadas = round($minutosTrabajados / 60, 2);
            }
        } else {
            // Es una entrada
            $entradaId = null;
            $horasTrabajadas = null;
            $minutosTrabajados = null;
        }
        
        // ============================================
        // INSERTAR FICHAJE
        // ============================================
        
        $stmt = $pdo->prepare("
            INSERT INTO fichajes (
                usuario_id, jibble_person_id, nombre, tipo,
                fecha_hora, timestamp_unix, nota, dispositivo,
                fichaje_entrada_id, horas_trabajadas, minutos_trabajados,
                latitud, longitud, ubicacion_validada, distancia_metros,
                sincronizado_jibble, es_descanso, auto_clockout
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Admin Manual', ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)
        ");
        
        $stmt->execute([
            $usuarioId,
            $usuario['jibble_person_id'],
            $usuario['nombre'],
            $tipo,
            $fechaHoraCompleta,
            $timestamp,
            $nota,
            $entradaId,
            $horasTrabajadas,
            $minutosTrabajados,
            $latitud,
            $longitud,
            $ubicacionValidada,
            $distanciaMetros
        ]);
        
        $fichajeId = $pdo->lastInsertId();
        
        // ============================================
        // SI ES UNA SALIDA, actualizar la entrada asociada
        // ============================================
        
        if ($tipo === 'Out' && $entradaId) {
            $stmt = $pdo->prepare("
                UPDATE fichajes 
                SET horas_trabajadas = ?, minutos_trabajados = ?
                WHERE id = ?
            ");
            $stmt->execute([$horasTrabajadas, $minutosTrabajados, $entradaId]);
        }
        
        // ============================================
        // SI ES UNA ENTRADA, buscar si hay una salida posterior sin asociar
        // ============================================
        
        if ($tipo === 'In') {
            $stmt = $pdo->prepare("
                SELECT id, timestamp_unix
                FROM fichajes 
                WHERE usuario_id = ? 
                AND tipo = 'Out'
                AND DATE(fecha_hora) = ?
                AND timestamp_unix > ?
                AND (fichaje_entrada_id IS NULL OR fichaje_entrada_id = 0)
                AND es_descanso = 0
                ORDER BY timestamp_unix ASC
                LIMIT 1
            ");
            $stmt->execute([$usuarioId, $fecha, $timestamp]);
            $salidaPosterior = $stmt->fetch();
            
            if ($salidaPosterior) {
                $segundos = $salidaPosterior['timestamp_unix'] - $timestamp;
                $minutos = floor($segundos / 60);
                $horas = round($minutos / 60, 2);
                
                // Asociar salida con esta entrada
                $stmt = $pdo->prepare("
                    UPDATE fichajes 
                    SET fichaje_entrada_id = ?, horas_trabajadas = ?, minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$fichajeId, $horas, $minutos, $salidaPosterior['id']]);
                
                // Actualizar también esta entrada
                $stmt = $pdo->prepare("
                    UPDATE fichajes 
                    SET horas_trabajadas = ?, minutos_trabajados = ?
                    WHERE id = ?
                ");
                $stmt->execute([$horas, $minutos, $fichajeId]);
            }
        }
        
        // ============================================
        // Actualizar resumen diario
        // ============================================
        
        actualizarResumenDiario($pdo, $usuarioId, $usuario['jibble_person_id'], $usuario['nombre'], $fecha);
        
        // Registrar en log
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, success) 
            VALUES ('manual_add', ?, 1)
        ");
        $logMsg = sprintf(
            "Fichaje manual añadido - Usuario: %s, Tipo: %s, Fecha: %s",
            $usuario['nombre'],
            $tipo,
            $fechaHoraCompleta
        );
        $stmt->execute([$logMsg]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Fichaje añadido correctamente',
            'fichaje_id' => $fichajeId,
            'tipo' => $tipo,
            'fecha_hora' => $fechaHoraCompleta,
            'horas_calculadas' => $horasTrabajadas,
            'entrada_asociada' => $entradaId
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al añadir fichaje: ' . $e->getMessage()
        ]);
    }
}

/**
 * Maneja fichajes mediante tarjeta RFID
 */
function handleRFIDCheckin($data) {
    header('Content-Type: application/json');
    
    // Extraer datos
    $deviceSerial = trim($data['device_serial'] ?? '');
    $rfidCardId = trim($data['rfid_card_id'] ?? '');
    $eventTime = $data['event_time'] ?? date('Y-m-d H:i:s');
    $rawData = json_encode($data['raw'] ?? $data);
    
    // Validar
    if (empty($rfidCardId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'RFID card ID required']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Buscar usuario por RFID
        $stmt = $pdo->prepare("
            SELECT id, jibble_person_id, nombre, email, pin
            FROM usuarios 
            WHERE (rfid_card_id = ? OR CAST(pin AS CHAR) = ?) AND activo = 1
        ");
        $stmt->execute([$rfidCardId, $rfidCardId]);
        $user = $stmt->fetch();
        
        // Usuario no encontrado
        if (!$user) {
            // Log de intento fallido
            $stmt = $pdo->prepare("
                INSERT INTO rfid_logs 
                (device_serial, rfid_card_id, fecha_hora, tipo, estado, error_message, raw_data)
                VALUES (?, ?, ?, 'Unknown', 'NotFound', 'Usuario no encontrado', ?)
            ");
            $stmt->execute([$deviceSerial, $rfidCardId, $eventTime, $rawData]);
            
            http_response_code(404);
            echo json_encode([
                'success' => false, 
                'error' => 'Tarjeta RFID no registrada',
                'rfid' => $rfidCardId
            ]);
            return;
        }
        
        // Verificar último fichaje (solo de asistencia, no descansos)
        $stmt = $pdo->prepare("
            SELECT id, tipo, fecha_hora, timestamp_unix
            FROM fichajes
            WHERE usuario_id = ? AND es_descanso = 0
            ORDER BY fecha_hora DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $lastFichaje = $stmt->fetch();

        // Determinar tipo (In/Out)
        $tipo = 'In';
        if ($lastFichaje && $lastFichaje['tipo'] === 'In') {
            $tipo = 'Out';
        }

        // ============ RESTRICCIÓN DE HORARIO (solo entradas) ============
        // También cubre el ZKteco: su listener reenvía cada marcaje a esta acción.
        // Se responde 200 para que el listener no lo cuente como error de red.
        if ($tipo === 'In') {
            $tsMarcaje = strtotime($eventTime) ?: time();
            $chkH = comprobarHorarioFichaje($pdo, $user['id'], $tsMarcaje);
            if (!$chkH['permitido']) {
                horariosRegistrarBloqueo($pdo, $user['id'], $user['nombre'], $deviceSerial ? 'zkteco' : 'rfid', $chkH['mensaje'], $tsMarcaje);
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO rfid_logs
                        (device_serial, rfid_card_id, usuario_id, fecha_hora, tipo, estado, error_message, raw_data)
                        VALUES (?, ?, ?, ?, 'In', 'Error', ?, ?)
                    ");
                    $stmt->execute([$deviceSerial, $rfidCardId, $user['id'], $eventTime, 'Fuera de horario', $rawData]);
                } catch (Exception $e) {}
                echo json_encode([
                    'success'       => false,
                    'error'         => $chkH['mensaje'],
                    'fuera_horario' => true,
                    'usuario'       => $user['nombre'],
                ]);
                return;
            }
        }

        // Calcular horas y buscar entrada si es salida
        $horasTrabajadas   = null;
        $minutosTrabajados = null;
        $entradaId         = null;

        if ($tipo === 'Out' && $lastFichaje) {
            $entradaId          = $lastFichaje['id'];
            $tsEntrada          = $lastFichaje['timestamp_unix'] ?: strtotime($lastFichaje['fecha_hora']);
            $tsSalida           = time(); // usar tiempo actual del servidor, más fiable que strtotime del dispositivo
            $segundos           = $tsSalida - $tsEntrada;
            $minutosTrabajados  = floor($segundos / 60);
            $horasTrabajadas    = round($minutosTrabajados / 60, 2);

            // Actualizar la entrada con las horas trabajadas
            $pdo->prepare("UPDATE fichajes SET horas_trabajadas = ?, minutos_trabajados = ? WHERE id = ?")
                ->execute([$horasTrabajadas, $minutosTrabajados, $entradaId]);
        }

        $dispositivo = 'RFID-ZKteco-' . ($deviceSerial ?: 'Unknown');
        $nota        = 'Fichaje con tarjeta RFID';
        $esManual    = (strpos($user['jibble_person_id'], 'manual_') === 0);

        // Registrar fichaje
        $stmt = $pdo->prepare("
            INSERT INTO fichajes
            (usuario_id, jibble_person_id, nombre, tipo, fecha_hora,
             timestamp_unix, horas_trabajadas, minutos_trabajados,
             fichaje_entrada_id, dispositivo, nota, sincronizado_jibble, es_descanso)
            VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP(?), ?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->execute([
            $user['id'],
            $user['jibble_person_id'],
            $user['nombre'],
            $tipo,
            $eventTime,
            $eventTime,
            $horasTrabajadas,
            $minutosTrabajados,
            $entradaId,
            $dispositivo,
            $nota
        ]);

        $fichajeId = $pdo->lastInsertId();

        // Actualizar resumen diario
        $fecha = substr($eventTime, 0, 10);
        actualizarResumenDiario($pdo, $user['id'], $user['jibble_person_id'], $user['nombre'], $fecha);
        
        // Log exitoso
        $stmt = $pdo->prepare("
            INSERT INTO rfid_logs 
            (device_serial, rfid_card_id, usuario_id, fecha_hora, tipo, estado, raw_data)
            VALUES (?, ?, ?, ?, ?, 'Success', ?)
        ");
        $stmt->execute([$deviceSerial, $rfidCardId, $user['id'], $eventTime, $tipo, $rawData]);
        
        // Actualizar última conexión del dispositivo
        if ($deviceSerial) {
            $stmt = $pdo->prepare("
                UPDATE anviz_devices 
                SET ultima_conexion = NOW() 
                WHERE device_serial = ?
            ");
            $stmt->execute([$deviceSerial]);
        }
        
        // Respuesta exitosa
        echo json_encode([
            'success' => true,
            'message' => 'Fichaje registrado correctamente',
            'data' => [
                'usuario' => $user['nombre'],
                'tipo' => $tipo,
                'hora' => $eventTime,
                'fichaje_id' => $fichajeId,
                'horas_trabajadas' => $horasTrabajadas
            ]
        ]);
        
    } catch (Exception $e) {
        // Log de error
        try {
            $stmt = $pdo->prepare("
                INSERT INTO rfid_logs 
                (device_serial, rfid_card_id, fecha_hora, tipo, estado, error_message, raw_data)
                VALUES (?, ?, ?, 'Unknown', 'Error', ?, ?)
            ");
            $stmt->execute([$deviceSerial, $rfidCardId, $eventTime, $e->getMessage(), $rawData]);
        } catch (Exception $logError) {
            // Ignorar error de log
        }
        
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
// ================================================
// ENDPOINT 1: Obtener últimos RFID no asignados
// ================================================
function handleObtenerUltimosRFIDNoAsignados($data) {
    header('Content-Type: application/json');
    
    $limit = intval($data['limit'] ?? 10);
    
    try {
        $pdo = getDBConnection();
        
        // Buscar tarjetas detectadas en los últimos 30 segundos que NO estén asignadas
        $stmt = $pdo->prepare("
            SELECT DISTINCT rfid_card_id, MAX(fecha_hora) as ultima_fecha
            FROM rfid_logs
            WHERE rfid_card_id NOT IN (
                SELECT rfid_card_id 
                FROM usuarios 
                WHERE rfid_card_id IS NOT NULL
            )
            AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
            GROUP BY rfid_card_id
            ORDER BY ultima_fecha DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $tarjetas = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'tarjetas' => $tarjetas
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ================================================
// ENDPOINT 2: Asignar RFID a usuario
// ================================================
function handleAsignarRFID($data) {
    header('Content-Type: application/json');
    
    $usuarioId = intval($data['usuario_id'] ?? 0);
    $rfidCardId = trim($data['rfid_card_id'] ?? '');
    
    if (!$usuarioId || !$rfidCardId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuario y tarjeta RFID requeridos']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Verificar si la tarjeta ya está asignada
        $stmt = $pdo->prepare("
            SELECT nombre 
            FROM usuarios 
            WHERE rfid_card_id = ? AND id != ?
        ");
        $stmt->execute([$rfidCardId, $usuarioId]);
        $yaAsignado = $stmt->fetch();
        
        if ($yaAsignado) {
            http_response_code(409);
            echo json_encode([
                'success' => false, 
                'error' => "Esta tarjeta ya está asignada a {$yaAsignado['nombre']}"
            ]);
            return;
        }
        
        // Asignar tarjeta
        $stmt = $pdo->prepare("
            UPDATE usuarios 
            SET rfid_card_id = ? 
            WHERE id = ?
        ");
        $stmt->execute([$rfidCardId, $usuarioId]);
        
        // Log de asignación
        $stmt = $pdo->prepare("
            INSERT INTO rfid_logs 
            (device_serial, rfid_card_id, usuario_id, fecha_hora, tipo, estado, error_message)
            VALUES ('ADMIN', ?, ?, NOW(), 'Unknown', 'Success', 'Asignación manual desde admin')
        ");
        $stmt->execute([$rfidCardId, $usuarioId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Tarjeta RFID asignada correctamente'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ================================================
// ENDPOINT 3: Obtener asignaciones RFID de hoy
// ================================================
function handleObtenerAsignacionesRFIDHoy($data) {
    header('Content-Type: application/json');
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                u.nombre,
                u.rfid_card_id,
                DATE_FORMAT(rl.fecha_hora, '%H:%i') as hora
            FROM rfid_logs rl
            JOIN usuarios u ON rl.usuario_id = u.id
            WHERE DATE(rl.fecha_hora) = CURDATE()
              AND rl.estado = 'Success'
              AND rl.error_message LIKE '%Asignación manual%'
            ORDER BY rl.fecha_hora DESC
        ");
        $stmt->execute();
        $asignaciones = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'asignaciones' => $asignaciones
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ================================================
// ENDPOINT 4: Obtener tarjetas sin asignar
// ================================================
function handleObtenerTarjetasSinAsignar($data) {
    header('Content-Type: application/json');
    
    try {
        $pdo = getDBConnection();
        
        // Tarjetas detectadas en los últimos 7 días que no están asignadas
        $stmt = $pdo->prepare("
            SELECT 
                rfid_card_id,
                COUNT(*) as veces_escaneada,
                MAX(fecha_hora) as ultima_fecha
            FROM rfid_logs
            WHERE rfid_card_id NOT IN (
                SELECT rfid_card_id 
                FROM usuarios 
                WHERE rfid_card_id IS NOT NULL
            )
            AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY rfid_card_id
            ORDER BY ultima_fecha DESC
        ");
        $stmt->execute();
        $tarjetas = $stmt->fetchAll();
        
        // Formatear fechas
        foreach ($tarjetas as &$tarjeta) {
            $tarjeta['ultima_fecha'] = date('d/m/Y H:i', strtotime($tarjeta['ultima_fecha']));
        }
        
        echo json_encode([
            'success' => true,
            'tarjetas' => $tarjetas
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ================================================
// ENDPOINT 5: Obtener lista de usuarios
// ================================================
function handleObtenerUsuarios($data) {
    header('Content-Type: application/json');
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT id, nombre, email, rfid_card_id
            FROM usuarios
            WHERE activo = 1
            ORDER BY nombre ASC
        ");
        $stmt->execute();
        $usuarios = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'usuarios' => $usuarios
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
// ================================================
// ENDPOINT 6: RFID
// ===============================================
function handleObtenerListadoRFID($data) {
    header('Content-Type: application/json');
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.nombre,
                u.rfid_card_id,
                (SELECT MAX(fecha_hora) 
                 FROM rfid_logs 
                 WHERE usuario_id = u.id AND estado = 'Success') as fecha_asignacion
            FROM usuarios u
            WHERE u.rfid_card_id IS NOT NULL
            ORDER BY fecha_asignacion DESC
        ");
        $stmt->execute();
        $listado = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'listado' => $listado
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleEliminarRFID($data) {
    header('Content-Type: application/json');
    
    $usuarioId = intval($data['usuario_id'] ?? 0);
    
    if (!$usuarioId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuario ID requerido']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("UPDATE usuarios SET rfid_card_id = NULL WHERE id = ?");
        $stmt->execute([$usuarioId]);
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

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

// ============================================
// GESTIÓN DE AUSENCIAS
// ============================================

/**
 * Crear una nueva ausencia
 */
function handleCrearAusencia($data) {
    $usuarioId = $data['usuario_id'] ?? null;
    $tipo = $data['tipo'] ?? null;
    $fechaInicio = $data['fecha_inicio'] ?? null;
    $fechaFin = $data['fecha_fin'] ?? null;
    $motivo = $data['motivo'] ?? '';
    $notas = $data['notas'] ?? '';
    
    if (!$usuarioId || !$tipo) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuario y tipo son requeridos']);
        return;
    }
    
    if ($tipo !== 'suspension_indefinida' && (!$fechaInicio || !$fechaFin)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Fechas requeridas para este tipo de ausencia']);
        return;
    }
    
    if ($tipo === 'suspension_indefinida' && !$fechaInicio) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Fecha de inicio requerida']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Obtener datos del usuario
        $stmt = $pdo->prepare("SELECT id, jibble_person_id, nombre FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            return;
        }
        
        // Verificar si ya existe una ausencia activa para este usuario en estas fechas
        if ($tipo !== 'suspension_indefinida') {
            $stmt = $pdo->prepare("
                SELECT id FROM ausencias 
                WHERE usuario_id = ? 
                AND estado = 'activa'
                AND (
                    (fecha_inicio <= ? AND fecha_fin >= ?)
                    OR (fecha_inicio <= ? AND fecha_fin >= ?)
                    OR (fecha_inicio >= ? AND fecha_fin <= ?)
                )
            ");
            $stmt->execute([$usuarioId, $fechaInicio, $fechaInicio, $fechaFin, $fechaFin, $fechaInicio, $fechaFin]);
            
            if ($stmt->fetch()) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'Ya existe una ausencia activa para este usuario en el período seleccionado'
                ]);
                return;
            }
        } else {
            // Para suspensión indefinida, verificar si ya tiene una activa
            $stmt = $pdo->prepare("
                SELECT id FROM ausencias 
                WHERE usuario_id = ? 
                AND tipo = 'suspension_indefinida'
                AND estado = 'activa'
            ");
            $stmt->execute([$usuarioId]);
            
            if ($stmt->fetch()) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'Este usuario ya tiene una suspensión indefinida activa'
                ]);
                return;
            }
        }
        
        // Insertar ausencia
        $stmt = $pdo->prepare("
            INSERT INTO ausencias (
                usuario_id, jibble_person_id, nombre, tipo,
                fecha_inicio, fecha_fin, motivo, notas,
                estado, creado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activa', 'admin')
        ");
        
        $stmt->execute([
            $usuarioId,
            $usuario['jibble_person_id'],
            $usuario['nombre'],
            $tipo,
            $fechaInicio,
            $fechaFin,
            $motivo,
            $notas
        ]);
        
        $ausenciaId = $pdo->lastInsertId();
        
        // Log
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, success)
            VALUES ('ausencia_creada', ?, 1)
        ");
        $logMsg = sprintf(
            "Ausencia creada - Usuario: %s, Tipo: %s, Desde: %s, Hasta: %s",
            $usuario['nombre'],
            $tipo,
            $fechaInicio,
            $fechaFin ?: 'INDEFINIDO'
        );
        $stmt->execute([$logMsg]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Ausencia registrada correctamente',
            'ausencia_id' => $ausenciaId
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Obtener ausencias activas
 */
function handleGetAusenciasActivas() {
    $usuarioId = $_GET['usuario_id'] ?? 'all';
    
    try {
        $pdo = getDBConnection();
        
        $whereClause = "estado = 'activa'";
        $params = [];
        
        if ($usuarioId !== 'all') {
            $whereClause .= " AND usuario_id = ?";
            $params[] = $usuarioId;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                id, usuario_id, nombre, tipo,
                fecha_inicio, fecha_fin, motivo, notas,
                estado, fecha_creacion
            FROM ausencias
            WHERE $whereClause
            ORDER BY fecha_inicio DESC
        ");
        $stmt->execute($params);
        $ausencias = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'ausencias' => $ausencias,
            'total' => count($ausencias)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Finalizar una ausencia
 */
function handleFinalizarAusencia($data) {
    $ausenciaId = $data['ausencia_id'] ?? null;
    
    if (!$ausenciaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de ausencia requerido']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Obtener datos de la ausencia
        $stmt = $pdo->prepare("SELECT * FROM ausencias WHERE id = ?");
        $stmt->execute([$ausenciaId]);
        $ausencia = $stmt->fetch();
        
        if (!$ausencia) {
            echo json_encode(['success' => false, 'error' => 'Ausencia no encontrada']);
            return;
        }
        
        // Actualizar estado
        $stmt = $pdo->prepare("
            UPDATE ausencias 
            SET estado = 'finalizada',
                fecha_fin = CURDATE()
            WHERE id = ?
        ");
        $stmt->execute([$ausenciaId]);
        
        // Log
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, success)
            VALUES ('ausencia_finalizada', ?, 1)
        ");
        $logMsg = sprintf(
            "Ausencia finalizada - Usuario: %s, Tipo: %s",
            $ausencia['nombre'],
            $ausencia['tipo']
        );
        $stmt->execute([$logMsg]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Ausencia finalizada correctamente'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Cancelar una ausencia
 */
function handleCancelarAusencia($data) {
    $ausenciaId = $data['ausencia_id'] ?? null;
    
    if (!$ausenciaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de ausencia requerido']);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // Obtener datos de la ausencia
        $stmt = $pdo->prepare("SELECT * FROM ausencias WHERE id = ?");
        $stmt->execute([$ausenciaId]);
        $ausencia = $stmt->fetch();
        
        if (!$ausencia) {
            echo json_encode(['success' => false, 'error' => 'Ausencia no encontrada']);
            return;
        }
        
        // Actualizar estado
        $stmt = $pdo->prepare("
            UPDATE ausencias 
            SET estado = 'cancelada'
            WHERE id = ?
        ");
        $stmt->execute([$ausenciaId]);
        
        // Log
        $stmt = $pdo->prepare("
            INSERT INTO sync_log (tipo, mensaje, success)
            VALUES ('ausencia_cancelada', ?, 1)
        ");
        $logMsg = sprintf(
            "Ausencia cancelada - Usuario: %s, Tipo: %s",
            $ausencia['nombre'],
            $ausencia['tipo']
        );
        $stmt->execute([$logMsg]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Ausencia cancelada correctamente'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Verificar si un usuario tiene ausencia activa (para bloquear fichaje)
 */
function handleVerificarAusenciaUsuario($data) {
    $usuarioId = $data['usuario_id'] ?? null;
    
    if (!$usuarioId) {
        echo json_encode(['ausencia_activa' => false]);
        return;
    }
    
    try {
        $pdo = getDBConnection();
        
        // 🔧 CONSULTA MEJORADA CON LOGS
        $stmt = $pdo->prepare("
            SELECT 
                id, tipo, fecha_inicio, fecha_fin, motivo,
                CURDATE() as hoy
            FROM ausencias
            WHERE usuario_id = ?
            AND estado = 'activa'
            AND (
                -- Suspensión indefinida
                fecha_fin IS NULL
                OR
                -- Ausencia con fechas (incluye hoy)
                (fecha_inicio <= CURDATE() AND fecha_fin >= CURDATE())
            )
            LIMIT 1
        ");
        $stmt->execute([$usuarioId]);
        $ausencia = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 🔍 LOG PARA DEBUG (puedes comentar después)
        error_log("Verificando ausencia para usuario $usuarioId");
        error_log("Ausencia encontrada: " . json_encode($ausencia));
        
        if ($ausencia) {
            echo json_encode([
                'ausencia_activa' => true,
                'tipo' => $ausencia['tipo'],
                'motivo' => $ausencia['motivo'],
                'fecha_inicio' => $ausencia['fecha_inicio'],
                'fecha_fin' => $ausencia['fecha_fin']
            ]);
        } else {
            echo json_encode(['ausencia_activa' => false]);
        }
        
    } catch (Exception $e) {
        error_log("Error verificando ausencia: " . $e->getMessage());
        echo json_encode(['ausencia_activa' => false, 'error' => $e->getMessage()]);
    }
}

function handleGetMyStats($data) {
    try {
        // Verificar autenticación
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            return;
        }
        
        $pdo = getDBConnection();
        $userId = $_SESSION['user_id'];
        $periodo = $_GET['periodo'] ?? 'month';
        $ano = $_GET['ano'] ?? date('Y'); // Año actual por defecto
        
        // ============================================
        // CALCULAR FECHAS SEGÚN PERÍODO
        // ============================================
        
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0);
        
        switch($periodo) {
            case 'today':
                $inicioCalculo = clone $hoy;
                break;
                
            case 'week':
                $diaSemana = $hoy->format('N');
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-' . ($diaSemana - 1) . ' days');
                break;
                
            case 'month':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('first day of this month');
                break;
                
            case 'last7':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-7 days');
                break;
                
            case 'last30':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-30 days');
                break;
                
            case 'last90':
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('-90 days');
                break;
                
            case 'all':
                // "Todo" = todo el año seleccionado (no desde 2000)
                $inicioCalculo = new DateTime("$ano-01-01");
                $hoy = new DateTime("$ano-12-31");
                break;
                
            default:
                $inicioCalculo = clone $hoy;
                $inicioCalculo->modify('first day of this month');
        }
        
        $fechaInicio = $inicioCalculo->format('Y-m-d H:i:s');
        $fechaFin = $hoy->format('Y-m-d 23:59:59');
        
        // ============================================
        // OBTENER DATOS DEL USUARIO
        // ============================================
        
        $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            return;
        }
        
        // ============================================
        // ESTADÍSTICAS: TOTAL FICHAJES
        // ============================================
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM fichajes 
            WHERE usuario_id = ? 
            AND fecha_hora >= ? 
            AND fecha_hora <= ?
        ");
        $stmt->execute([$userId, $fechaInicio, $fechaFin]);
        $totalFichajes = $stmt->fetch()['total'];
        
        // ============================================
        // ESTADÍSTICAS: HORAS TOTALES
        // ============================================
        
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN tipo = 'Out' THEN horas_trabajadas ELSE 0 END), 0) as total 
            FROM fichajes 
            WHERE usuario_id = ? 
            AND fecha_hora >= ? 
            AND fecha_hora <= ?
        ");
        $stmt->execute([$userId, $fechaInicio, $fechaFin]);
        $result = $stmt->fetch();
        $totalHoras = floatval($result['total']);
        $horas = intval($totalHoras);
        $minutos = intval(($totalHoras - $horas) * 60);
        
        // ============================================
        // ESTADÍSTICAS: DÍAS TRABAJADOS
        // ============================================
        
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT DATE(fecha_hora)) as dias 
            FROM fichajes 
            WHERE usuario_id = ? 
            AND fecha_hora >= ? 
            AND fecha_hora <= ?
        ");
        $stmt->execute([$userId, $fechaInicio, $fechaFin]);
        $diasTrabajados = $stmt->fetch()['dias'];
        
        // ============================================
        // ESTADÍSTICAS: PROMEDIO DIARIO
        // ============================================
        
        $promedioDiario = $diasTrabajados > 0 ? $totalHoras / $diasTrabajados : 0;
        
        // ============================================
        // DETALLES POR DÍA (según período seleccionado)
        // ============================================
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE(fecha_hora) as fecha,
                COUNT(*) as fichajes,
                COALESCE(SUM(CASE WHEN tipo = 'Out' THEN horas_trabajadas ELSE 0 END), 0) as horas
            FROM fichajes
            WHERE usuario_id = ?
            AND DATE(fecha_hora) >= ?
            AND DATE(fecha_hora) <= ?
            GROUP BY DATE(fecha_hora)
            ORDER BY fecha ASC
        ");
        $stmt->execute([$userId, $inicioCalculo->format('Y-m-d'), $hoy->format('Y-m-d')]);
        $detalesPorDia = $stmt->fetchAll();
        
        // Rellenar días sin datos con ceros
        $datosCompletos = [];
        
        // Calcular número de días en el rango
        $inicio = clone $inicioCalculo;
        $fin = clone $hoy;
        $fin->modify('+1 day'); // Incluir el día actual
        
        $interval = $inicio->diff($fin);
        $diasEnRango = $interval->days;
        
        for ($i = 0; $i < $diasEnRango; $i++) {
            $fecha = clone $inicioCalculo;
            $fecha->modify("+$i days");
            $fechaStr = $fecha->format('Y-m-d');
            
            $encontrado = false;
            foreach ($detalesPorDia as $dia) {
                if ($dia['fecha'] === $fechaStr) {
                    $datosCompletos[] = [
                        'fecha' => $fechaStr,
                        'fichajes' => intval($dia['fichajes']),
                        'horas' => floatval($dia['horas'])
                    ];
                    $encontrado = true;
                    break;
                }
            }
            
            if (!$encontrado) {
                $datosCompletos[] = [
                    'fecha' => $fechaStr,
                    'fichajes' => 0,
                    'horas' => 0
                ];
            }
        }
        
        // ============================================
        // RESPUESTA
        // ============================================
        
        echo json_encode([
            'success' => true,
            'usuario' => $usuario['nombre'],
            'periodo' => $periodo,
            'ano' => $ano,
            'resumen' => [
                'totalFichajes' => $totalFichajes,
                'totalHoras' => $totalHoras,
                'totalHorasFormato' => sprintf('%d:%02d', $horas, $minutos),
                'diasTrabajados' => $diasTrabajados,
                'promedioDiario' => round($promedioDiario, 1)
            ],
            'detallesPorDia' => $datosCompletos
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
// ============================================
// FUNCIÓN: Obtener años disponibles
// ============================================

function handleGetAnosDisponibles() {
    try {
        // Verificar autenticación
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            return;
        }
        
        $pdo = getDBConnection();
        $userId = $_SESSION['user_id'];
        
        // Obtener todos los años donde el usuario tiene fichajes
        $stmt = $pdo->prepare("
            SELECT DISTINCT YEAR(fecha_hora) as ano
            FROM fichajes
            WHERE usuario_id = ?
            AND fecha_hora IS NOT NULL
            ORDER BY ano DESC
        ");
        $stmt->execute([$userId]);
        $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convertir array de objetos a array simple de años
        $anos = array_map(function($row) {
            return intval($row['ano']);
        }, $resultado);
        
        // Si no hay años, devolver año actual
        if (empty($anos)) {
            $anos = [date('Y')];
        }
        
        echo json_encode([
            'success' => true,
            'anos' => $anos,
            'total_anos' => count($anos)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================
// CREAR USUARIO MANUAL
// ============================================
function handleCrearUsuarioManual($data) {
    try {
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            return;
        }
        $nombre  = trim($data['nombre'] ?? '');
        $email   = strtolower(trim($data['email'] ?? ''));
        $pin     = trim($data['pin'] ?? '');
        if (!$nombre || !$email || !$pin) {
            echo json_encode(['success' => false, 'error' => 'Nombre, email y PIN son obligatorios']);
            return;
        }
        if (!preg_match('/^\d{4}$/', $pin)) {
            echo json_encode(['success' => false, 'error' => 'El PIN debe tener exactamente 4 dígitos']);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Email no válido']);
            return;
        }
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe un usuario con ese email']);
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO usuarios (jibble_person_id, nombre, email, pin, activo) VALUES (?, ?, ?, ?, 1)");
        $jibbleId = 'manual_' . uniqid();
        $stmt->execute([$jibbleId, $nombre, $email, $pin]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================
// SET HORAS SEMANALES
// ============================================
function handleSetHorasSemanales($data) {
    try {
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            return;
        }
        $horas     = intval($data['horas'] ?? 0);
        $global    = (bool)($data['global'] ?? false);
        $usuarioId = intval($data['usuario_id'] ?? 0);
        if ($horas < 1 || $horas > 80) {
            echo json_encode(['success' => false, 'error' => 'Las horas deben estar entre 1 y 80']);
            return;
        }
        $pdo = getDBConnection();
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN horas_semanales_max INT NULL DEFAULT NULL"); } catch (Exception $e) {}
        if ($global) {
            $stmt = $pdo->prepare("UPDATE usuarios SET horas_semanales_max = ? WHERE activo = 1");
            $stmt->execute([$horas]);
        } else {
            if (!$usuarioId) { echo json_encode(['success' => false, 'error' => 'Usuario no especificado']); return; }
            $stmt = $pdo->prepare("UPDATE usuarios SET horas_semanales_max = ? WHERE id = ?");
            $stmt->execute([$horas, $usuarioId]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================
// SET HORA DE CIERRE
// ============================================
function handleSetHoraCierre($data) {
    try {
        if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            return;
        }
        $hora      = trim($data['hora'] ?? '');
        $global    = (bool)($data['global'] ?? false);
        $quitar    = (bool)($data['quitar'] ?? false);
        $usuarioId = intval($data['usuario_id'] ?? 0);
        if (!$quitar && !preg_match('/^\d{2}:\d{2}$/', $hora)) {
            echo json_encode(['success' => false, 'error' => 'Formato de hora no válido (HH:MM)']);
            return;
        }
        $pdo = getDBConnection();
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN hora_cierre_auto VARCHAR(5) NULL DEFAULT NULL"); } catch (Exception $e) {}
        $valor = $quitar ? null : $hora;
        if ($global) {
            $stmt = $pdo->prepare("UPDATE usuarios SET hora_cierre_auto = ? WHERE activo = 1");
            $stmt->execute([$valor]);
        } else {
            if (!$usuarioId) { echo json_encode(['success' => false, 'error' => 'Usuario no especificado']); return; }
            $stmt = $pdo->prepare("UPDATE usuarios SET hora_cierre_auto = ? WHERE id = ?");
            $stmt->execute([$valor, $usuarioId]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================
// SOLICITUDES DE AUSENCIA (empleado → admin)
// ============================================================

function handleSolicitarAusencia($data) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    $usuarioId   = $_SESSION['user_id'];
    $tipo        = $data['tipo']         ?? null;
    $fechaInicio = $data['fecha_inicio'] ?? null;
    $fechaFin    = $data['fecha_fin']    ?? null;
    $motivo      = $data['motivo']       ?? '';
    if (!$tipo || !$fechaInicio || !$fechaFin) {
        echo json_encode(['success' => false, 'error' => 'Tipo, fecha inicio y fecha fin son obligatorios']);
        return;
    }
    if ($fechaFin < $fechaInicio) {
        echo json_encode(['success' => false, 'error' => 'La fecha fin debe ser posterior a la fecha inicio']);
        return;
    }
    try {
        $pdo = getDBConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitudes_ausencia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            nombre VARCHAR(255),
            tipo VARCHAR(50) NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE,
            motivo TEXT,
            estado ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
            respuesta_admin TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch();
        $stmt = $pdo->prepare("INSERT INTO solicitudes_ausencia (usuario_id, nombre, tipo, fecha_inicio, fecha_fin, motivo) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$usuarioId, $u['nombre'] ?? '', $tipo, $fechaInicio, $fechaFin, $motivo]);
        echo json_encode(['success' => true, 'message' => 'Solicitud enviada. El administrador la revisará pronto.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleGetSolicitudesAusencia() {
    try {
        $pdo = getDBConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitudes_ausencia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            nombre VARCHAR(255),
            tipo VARCHAR(50) NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE,
            motivo TEXT,
            estado ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
            respuesta_admin TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->query("SELECT * FROM solicitudes_ausencia ORDER BY FIELD(estado,'pendiente','aprobada','rechazada'), created_at DESC");
        $solicitudes = $stmt->fetchAll();
        echo json_encode(['success' => true, 'solicitudes' => $solicitudes]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleResponderSolicitudAusencia($data) {
    $solicitudId = $data['solicitud_id'] ?? null;
    $accion      = $data['accion']       ?? null;
    $respuesta   = $data['respuesta']    ?? '';
    if (!$solicitudId || !in_array($accion, ['aprobar', 'rechazar'])) {
        echo json_encode(['success' => false, 'error' => 'Parametros invalidos']);
        return;
    }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM solicitudes_ausencia WHERE id = ?");
        $stmt->execute([$solicitudId]);
        $sol = $stmt->fetch();
        if (!$sol) { echo json_encode(['success' => false, 'error' => 'Solicitud no encontrada']); return; }
        $nuevoEstado = $accion === 'aprobar' ? 'aprobada' : 'rechazada';
        $pdo->prepare("UPDATE solicitudes_ausencia SET estado = ?, respuesta_admin = ? WHERE id = ?")->execute([$nuevoEstado, $respuesta, $solicitudId]);
        if ($accion === 'aprobar') {
            $stmt = $pdo->prepare("SELECT id, jibble_person_id, nombre FROM usuarios WHERE id = ?");
            $stmt->execute([$sol['usuario_id']]);
            $usuario = $stmt->fetch();
            if ($usuario) {
                $pdo->prepare("INSERT INTO ausencias (usuario_id, jibble_person_id, nombre, tipo, fecha_inicio, fecha_fin, motivo, estado, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?, 'activa', 'solicitud_empleado')")
                    ->execute([$sol['usuario_id'], $usuario['jibble_person_id'], $usuario['nombre'], $sol['tipo'], $sol['fecha_inicio'], $sol['fecha_fin'], $sol['motivo']]);
            }
        }
        echo json_encode(['success' => true, 'message' => $accion === 'aprobar' ? 'Solicitud aprobada y ausencia creada' : 'Solicitud rechazada']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleGetMisSolicitudes() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    try {
        $pdo = getDBConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS solicitudes_ausencia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            nombre VARCHAR(255),
            tipo VARCHAR(50) NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE,
            motivo TEXT,
            estado ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
            respuesta_admin TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->prepare("SELECT * FROM solicitudes_ausencia WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$_SESSION['user_id']]);
        $solicitudes = $stmt->fetchAll();
        echo json_encode(['success' => true, 'solicitudes' => $solicitudes]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================
// BORRAR FICHAJES POR RANGO
// ============================================================

function handleDeleteFichajesRango($data) {
    $fechaInicio = $data['fecha_inicio'] ?? null;
    $fechaFin    = $data['fecha_fin']    ?? null;
    $usuarioId   = $data['usuario_id']   ?? null;
    if (!$fechaInicio || !$fechaFin) {
        echo json_encode(['success' => false, 'error' => 'Fechas obligatorias']); return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
        echo json_encode(['success' => false, 'error' => 'Formato invalido (YYYY-MM-DD)']); return;
    }
    if ($fechaFin < $fechaInicio) {
        echo json_encode(['success' => false, 'error' => 'Fecha fin debe ser posterior']); return;
    }
    try {
        $pdo = getDBConnection();
        if ($usuarioId) {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM fichajes WHERE fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY) AND usuario_id = ?");
            $stmtCount->execute([$fechaInicio, $fechaFin, $usuarioId]);
        } else {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM fichajes WHERE fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY)");
            $stmtCount->execute([$fechaInicio, $fechaFin]);
        }
        $total = $stmtCount->fetchColumn();
        if ($total === 0) { echo json_encode(['success' => false, 'error' => 'No hay fichajes en ese rango']); return; }
        if ($usuarioId) {
            $stmt = $pdo->prepare("DELETE FROM fichajes WHERE fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY) AND usuario_id = ?");
            $stmt->execute([$fechaInicio, $fechaFin, $usuarioId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM fichajes WHERE fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY)");
            $stmt->execute([$fechaInicio, $fechaFin]);
        }
        $borrados = $stmt->rowCount();
        echo json_encode(['success' => true, 'message' => "Se han eliminado {$borrados} fichajes", 'borrados' => $borrados]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleDeleteFichajesRangoJibble($data) {
    $fechaInicio  = $data['fecha_inicio']  ?? null;
    $fechaFin     = $data['fecha_fin']     ?? null;
    $usuarioId    = $data['usuario_id']    ?? null;
    $borrarJibble = $data['borrar_jibble'] ?? false;
    if (!$fechaInicio || !$fechaFin) {
        echo json_encode(['success' => false, 'error' => 'Fechas obligatorias']); return;
    }
    try {
        $pdo = getDBConnection();
        if ($usuarioId) {
            $stmt = $pdo->prepare("SELECT id, jibble_person_id, nombre, tipo, fecha_hora FROM fichajes WHERE fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY) AND usuario_id = ? AND es_descanso = 0 ORDER BY fecha_hora ASC");
            $stmt->execute([$fechaInicio, $fechaFin, $usuarioId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, jibble_person_id, nombre, tipo, fecha_hora FROM fichajes WHERE fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY) AND es_descanso = 0 ORDER BY fecha_hora ASC");
            $stmt->execute([$fechaInicio, $fechaFin]);
        }
        $fichajes = $stmt->fetchAll();
        if (empty($fichajes)) { echo json_encode(['success' => false, 'error' => 'No hay fichajes en ese rango']); return; }
        $total = count($fichajes); $borrados = 0; $errores = [];
        $jibble = $borrarJibble ? new JibbleAPI() : null;
        foreach ($fichajes as $f) {
            if ($borrarJibble && $jibble) {
                try { $jibble->deleteTimesheet($f['jibble_person_id'], $f['fecha_hora']); } catch (Exception $e) { $errores[] = 'Jibble error: ' . $e->getMessage(); }
                usleep(1000000);
            }
            $pdo->prepare("DELETE FROM fichajes WHERE id = ?")->execute([$f['id']]);
            $borrados++;
        }
        echo json_encode(['success' => true, 'message' => "Eliminados {$borrados} de {$total} fichajes", 'borrados' => $borrados, 'total' => $total, 'errores' => $errores]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================
// IMPORTAR USUARIOS AL ZKTECO
// ============================================================

function handleSyncUsersZkteco($data) {
    $usuarioIds = $data['usuario_ids'] ?? null;
    try {
        $pdo = getDBConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS zkteco_commands (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sn VARCHAR(50) NOT NULL,
            command TEXT NOT NULL,
            estado ENUM('pendiente','enviado','error') DEFAULT 'pendiente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL
        )");
        if ($usuarioIds) {
            $placeholders = implode(',', array_fill(0, count($usuarioIds), '?'));
            $stmt = $pdo->prepare("SELECT id, nombre, pin, rfid_card_id FROM usuarios WHERE activo = 1 AND id IN ($placeholders)");
            $stmt->execute($usuarioIds);
        } else {
            $stmt = $pdo->query("SELECT id, nombre, pin, rfid_card_id FROM usuarios WHERE activo = 1");
        }
        $usuarios = $stmt->fetchAll();
        $sn = $data['sn'] ?? 'NWJ1261500076';
        $inserted = 0;
        foreach ($usuarios as $u) {
            $userId = $u['pin'];
            $nombre = substr($u['nombre'], 0, 24);
            $card   = $u['rfid_card_id'] ?? '';
            $tab = "	";
            $cmd = "DATA UPDATE USERINFO PIN={$userId}{$tab}Name={$nombre}{$tab}Pri=0{$tab}Passwd={$tab}Card={$card}{$tab}Grp=1{$tab}TZ=0000000100000000";
            $pdo->prepare("INSERT INTO zkteco_commands (sn, command) VALUES (?, ?)")->execute([$sn, $cmd]);
            $inserted++;
        }
        echo json_encode(['success' => true, 'message' => "$inserted usuarios encolados para sincronizar con ZKteco", 'total' => $inserted]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleGetZktecoCommands($data) {
    $sn = $data['sn'] ?? $_GET['sn'] ?? $_GET['SN'] ?? '';
    if (!$sn) { echo json_encode([]); return; }
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, command FROM zkteco_commands WHERE sn = ? AND estado = 'pendiente' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$sn]);
        $cmd = $stmt->fetch();
        if ($cmd) {
            $pdo->prepare("UPDATE zkteco_commands SET estado = 'enviado', sent_at = NOW() WHERE id = ?")->execute([$cmd['id']]);
            echo json_encode(['command' => $cmd['command'], 'id' => $cmd['id']]);
        } else {
            echo json_encode([]);
        }
    } catch (Exception $e) {
        echo json_encode([]);
    }
}
// ============================================================
// BORRADO BULK DE FICHAJES (con previsualización de pares)
// ============================================================

function handlePreviewBulkDelete($data) {
    $fechaInicio = $data['fecha_inicio'] ?? null;
    $fechaFin    = $data['fecha_fin']    ?? null;
    $usuarioId   = $data['usuario_id']   ?? null; // null = todos

    if (!$fechaInicio || !$fechaFin) {
        echo json_encode(['success' => false, 'error' => 'Fechas obligatorias']);
        return;
    }

    try {
        $pdo = getDBConnection();

        // Obtener todas las entradas del rango
        $sql = "
            SELECT 
                f_in.id          AS entrada_id,
                f_in.nombre,
                f_in.fecha_hora  AS entrada_hora,
                f_in.dispositivo AS entrada_disp,
                f_out.id         AS salida_id,
                f_out.fecha_hora AS salida_hora,
                f_out.horas_trabajadas,
                f_out.dispositivo AS salida_disp
            FROM fichajes f_in
            LEFT JOIN fichajes f_out 
                ON f_out.fichaje_entrada_id = f_in.id
                AND f_out.tipo = 'Out'
                AND f_out.es_descanso = 0
            WHERE f_in.tipo = 'In'
            AND f_in.es_descanso = 0
            AND DATE(f_in.fecha_hora) BETWEEN ? AND ?
        ";
        $params = [$fechaInicio, $fechaFin];

        if ($usuarioId) {
            $sql .= " AND f_in.usuario_id = ?";
            $params[] = $usuarioId;
        }

        $sql .= " ORDER BY f_in.fecha_hora ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pares = $stmt->fetchAll();

        // Buscar también salidas sin entrada asociada en el rango
        $sql2 = "
            SELECT 
                NULL            AS entrada_id,
                f.nombre,
                NULL            AS entrada_hora,
                NULL            AS entrada_disp,
                f.id            AS salida_id,
                f.fecha_hora    AS salida_hora,
                f.horas_trabajadas,
                f.dispositivo   AS salida_disp
            FROM fichajes f
            WHERE f.tipo = 'Out'
            AND f.es_descanso = 0
            AND (f.fichaje_entrada_id IS NULL OR f.fichaje_entrada_id = 0)
            AND DATE(f.fecha_hora) BETWEEN ? AND ?
        ";
        $params2 = [$fechaInicio, $fechaFin];

        if ($usuarioId) {
            $sql2 .= " AND f.usuario_id = ?";
            $params2[] = $usuarioId;
        }

        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($params2);
        $soloSalidas = $stmt2->fetchAll();

        $todos = array_merge($pares, $soloSalidas);

        echo json_encode([
            'success' => true,
            'pares'   => $todos,
            'total'   => count($todos)
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handleExecuteBulkDelete($data) {
    // ids es array de objetos {entrada_id, salida_id} — cualquiera puede ser null
    $ids = $data['ids'] ?? [];

    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'No se seleccionaron fichajes']);
        return;
    }

    try {
        $pdo = getDBConnection();
        $borrados = 0;

        foreach ($ids as $par) {
            $entradaId = $par['entrada_id'] ?? null;
            $salidaId  = $par['salida_id']  ?? null;

            // Limpiar referencias antes de borrar
            if ($entradaId) {
                $pdo->prepare("
                    UPDATE fichajes 
                    SET fichaje_entrada_id = NULL, horas_trabajadas = NULL, minutos_trabajados = NULL 
                    WHERE fichaje_entrada_id = ?
                ")->execute([$entradaId]);
            }

            // Borrar salida primero (por si tiene FK a entrada)
            if ($salidaId) {
                $pdo->prepare("DELETE FROM fichajes WHERE id = ?")->execute([$salidaId]);
                $borrados++;
            }

            // Borrar entrada
            if ($entradaId) {
                $pdo->prepare("DELETE FROM fichajes WHERE id = ?")->execute([$entradaId]);
                $borrados++;
            }
        }

        echo json_encode([
            'success'  => true,
            'message'  => "Eliminados $borrados registros (" . count($ids) . " pares)",
            'borrados' => $borrados
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================================
// HORARIOS Y CALENDARIO (lógica de bloqueo en horarios_helper.php)
// ============================================================================

/** [esAdmin, userIdSesion]. scope='me' fuerza modo empleado aunque haya sesión admin abierta. */
function horariosContexto($data) {
    $sesionAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    $scope       = $data['scope'] ?? '';
    // Desde el panel de admin (scope=admin) nunca se cae en silencio a la vista de empleado:
    // si la sesión de admin ya no existe, se corta con 403 y el panel avisa.
    if ($scope === 'admin' && !$sesionAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Tu sesión de administrador ha caducado. Vuelve a iniciar sesión.', 'sesion_admin' => false]);
        exit;
    }
    $esAdmin = $sesionAdmin && $scope !== 'me';
    $userId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    return [$esAdmin, $userId];
}


function horariosFail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
}

function horariosFechaValida($f) {
    return is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;
}

/** Valida una lista de tramos [{dia_semana, hora_inicio, hora_fin}]. Devuelve [tramosLimpios, error|null] */
function horariosValidarTramos($tramos, $conDia = true) {
    if (!is_array($tramos)) return [null, 'Formato de tramos no válido'];
    $limpios = [];
    $porDia  = [];
    foreach ($tramos as $t) {
        $dia = $conDia ? (int)($t['dia_semana'] ?? 0) : 0;
        if ($conDia && ($dia < 1 || $dia > 7)) return [null, 'Día de la semana no válido'];
        $ini = horariosNormalizarHora($t['hora_inicio'] ?? '');
        $fin = horariosNormalizarHora($t['hora_fin'] ?? '');
        if (!$ini || !$fin) return [null, 'Hora no válida (usa HH:MM)'];
        if ($ini === $fin) return [null, 'Un tramo no puede empezar y acabar a la misma hora'];
        $porDia[$dia][] = [$ini, $fin];
        $limpios[] = ['dia_semana' => $dia, 'hora_inicio' => $ini, 'hora_fin' => $fin];
    }
    $nombres = [0 => 'ese día', 1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    foreach ($porDia as $dia => $lista) {
        if (count($lista) > 4) return [null, "Máximo 4 tramos por día ({$nombres[$dia]})"];
        // Solape: convertir a minutos (fin < inicio => +1440)
        $rangos = [];
        foreach ($lista as $r) {
            $a = (int)substr($r[0], 0, 2) * 60 + (int)substr($r[0], 3, 2);
            $rangos[] = [$a, $a + horariosMinutosTramo($r[0], $r[1])];
        }
        usort($rangos, function ($x, $y) { return $x[0] - $y[0]; });
        for ($i = 1; $i < count($rangos); $i++) {
            if ($rangos[$i][0] < $rangos[$i - 1][1]) return [null, "Hay tramos que se solapan el {$nombres[$dia]}"];
        }
    }
    return [$limpios, null];
}

// ----------------------------------------------------------------------------
// HORARIO SEMANAL
// ----------------------------------------------------------------------------

function handleGetHorarioUsuario($data) {
    list($esAdmin, $sesionId) = horariosContexto($data);
    $uid = $esAdmin ? (int)($data['usuario_id'] ?? 0) : $sesionId;
    if (!$uid) return horariosFail($esAdmin ? 'usuario_id requerido' : 'No autenticado', $esAdmin ? 400 : 401);

    try {
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$uid]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) return horariosFail('Usuario no encontrado', 404);

        $stmt = $pdo->prepare("
            SELECT dia_semana, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
            FROM horarios_usuario WHERE usuario_id = ? ORDER BY dia_semana, orden, hora_inicio
        ");
        $stmt->execute([$uid]);
        $tramos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $min = 0;
        foreach ($tramos as $t) $min += horariosMinutosTramo($t['hora_inicio'], $t['hora_fin']);

        $cfg = horariosGetConfig($pdo);
        $restringido = (int)($u['restringir_horario'] ?? 0) === 1;

        echo json_encode([
            'success'            => true,
            'usuario'            => ['id' => (int)$u['id'], 'nombre' => $u['nombre']],
            'tramos'             => $tramos,
            'restringir_horario' => $restringido,
            'minutos_semana'     => $min,
            'horas_semanales_max'=> isset($u['horas_semanales_max']) ? $u['horas_semanales_max'] : null,
            'puede_editar'       => $esAdmin || ($cfg['usuarios_editan_horario'] && !$restringido),
            'motivo_no_editable' => $esAdmin ? null : (!$cfg['usuarios_editan_horario']
                                     ? 'El administrador gestiona los horarios'
                                     : ($restringido ? 'Tu horario está bloqueado por el administrador' : null)),
        ]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

function handleSaveHorarioUsuario($data) {
    list($esAdmin, $sesionId) = horariosContexto($data);
    $uid = $esAdmin ? (int)($data['usuario_id'] ?? 0) : $sesionId;
    if (!$uid) return horariosFail($esAdmin ? 'usuario_id requerido' : 'No autenticado', $esAdmin ? 400 : 401);

    list($tramos, $err) = horariosValidarTramos($data['tramos'] ?? []);
    if ($err) return horariosFail($err);

    try {
        $pdo = getDBConnection();

        if (!$esAdmin) {
            $cfg  = horariosGetConfig($pdo);
            $stmt = $pdo->prepare("SELECT restringir_horario FROM usuarios WHERE id = ?");
            $stmt->execute([$uid]);
            if (!$cfg['usuarios_editan_horario'] || (int)$stmt->fetchColumn() === 1) {
                return horariosFail('No tienes permiso para modificar tu horario', 403);
            }
        }

        horariosGuardarSemana($pdo, $uid, $tramos);
        horariosLog($pdo, 'horario_guardado', "Horario semanal actualizado (usuario $uid) por " . ($esAdmin ? 'admin' : 'empleado'));

        echo json_encode(['success' => true, 'message' => 'Horario guardado', 'tramos' => count($tramos)]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

function horariosGuardarSemana($pdo, $uid, $tramos) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM horarios_usuario WHERE usuario_id = ?")->execute([$uid]);
        $ins = $pdo->prepare("INSERT INTO horarios_usuario (usuario_id, dia_semana, hora_inicio, hora_fin, orden) VALUES (?, ?, ?, ?, ?)");
        $orden = [];
        foreach ($tramos as $t) {
            $d = $t['dia_semana'];
            $orden[$d] = ($orden[$d] ?? 0) + 1;
            $ins->execute([$uid, $d, $t['hora_inicio'], $t['hora_fin'], $orden[$d]]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function horariosLog($pdo, $tipo, $msg) {
    try {
        $pdo->prepare("INSERT INTO sync_log (tipo, mensaje, success) VALUES (?, ?, 1)")->execute([$tipo, $msg]);
    } catch (Exception $e) { /* el log nunca debe romper la acción */ }
}

/** Admin: copia el horario semanal de un usuario a otros (sobrescribe). */
function handleCopiarHorario($data) {
    $origen  = (int)($data['origen_id'] ?? 0);
    $destino = array_values(array_filter(array_map('intval', (array)($data['destino_ids'] ?? []))));
    $destino = array_diff($destino, [$origen]);
    if (!$origen || !$destino) return horariosFail('Indica usuario origen y al menos un destino');

    try {
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT dia_semana, hora_inicio, hora_fin FROM horarios_usuario WHERE usuario_id = ? ORDER BY dia_semana, orden");
        $stmt->execute([$origen]);
        $tramos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($destino as $d) horariosGuardarSemana($pdo, $d, $tramos);
        horariosLog($pdo, 'horario_copiado', "Horario de $origen copiado a: " . implode(',', $destino));
        echo json_encode(['success' => true, 'message' => 'Horario copiado a ' . count($destino) . ' usuario(s)']);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

// ----------------------------------------------------------------------------
// ACTIVADOR DE RESTRICCIÓN (solo admin)
// ----------------------------------------------------------------------------

function handleToggleRestriccionHorario($data) {
    $activo = !empty($data['activo']) ? 1 : 0;
    $todos  = !empty($data['todos']);
    $uid    = (int)($data['usuario_id'] ?? 0);
    if (!$todos && !$uid) return horariosFail('usuario_id requerido');

    try {
        $pdo = getDBConnection();
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN restringir_horario TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

        if ($todos) {
            $pdo->prepare("UPDATE usuarios SET restringir_horario = ? WHERE activo = 1")->execute([$activo]);
        } else {
            $pdo->prepare("UPDATE usuarios SET restringir_horario = ? WHERE id = ?")->execute([$activo, $uid]);
        }

        // Aviso: usuarios con restricción activa pero SIN horario => no podrían fichar nunca
        $sinHorario = [];
        if ($activo) {
            $sql = "SELECT u.id, u.nombre FROM usuarios u
                    WHERE u.restringir_horario = 1 AND u.activo = 1
                    AND NOT EXISTS (SELECT 1 FROM horarios_usuario h WHERE h.usuario_id = u.id)";
            $params = [];
            if (!$todos) { $sql .= " AND u.id = ?"; $params[] = $uid; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $sinHorario = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        horariosLog($pdo, 'restriccion_horario', ($activo ? 'Activada' : 'Desactivada') . ' restricción de horario para ' . ($todos ? 'todos' : "usuario $uid"));

        echo json_encode([
            'success'     => true,
            'activo'      => (bool)$activo,
            'sin_horario' => $sinHorario,
            'message'     => $activo ? 'Restricción de horario activada' : 'Restricción de horario desactivada',
        ]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

/** Admin: lista de usuarios con su estado de restricción y horas planificadas/semana. */
function handleGetHorariosResumen() {
    try {
        $pdo = getDBConnection();
        $usuarios = $pdo->query("SELECT * FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
        $tramos   = $pdo->query("SELECT usuario_id, hora_inicio, hora_fin FROM horarios_usuario")->fetchAll(PDO::FETCH_ASSOC);

        $min = []; $num = [];
        foreach ($tramos as $t) {
            $id = (int)$t['usuario_id'];
            $min[$id] = ($min[$id] ?? 0) + horariosMinutosTramo($t['hora_inicio'], $t['hora_fin']);
            $num[$id] = ($num[$id] ?? 0) + 1;
        }

        $out = [];
        foreach ($usuarios as $u) {
            $id = (int)$u['id'];
            $out[] = [
                'id'                  => $id,
                'nombre'              => $u['nombre'],
                'restringir_horario'  => (int)($u['restringir_horario'] ?? 0) === 1,
                'num_tramos'          => $num[$id] ?? 0,
                'minutos_semana'      => $min[$id] ?? 0,
                'horas_semanales_max' => $u['horas_semanales_max'] ?? null,
                'color'               => horariosColorUsuario($id),
            ];
        }
        echo json_encode(['success' => true, 'usuarios' => $out, 'config' => horariosGetConfig($pdo)]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

function handleSaveConfigHorarios($data) {
    $margen = max(0, min(240, (int)($data['margen_antes_min'] ?? 15)));
    $fest   = !empty($data['bloquear_festivos']) ? 1 : 0;
    $edit   = !empty($data['usuarios_editan_horario']) ? 1 : 0;
    try {
        $pdo = getDBConnection();
        $pdo->prepare("
            INSERT INTO horarios_config (id, margen_antes_min, bloquear_festivos, usuarios_editan_horario)
            VALUES (1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE margen_antes_min = VALUES(margen_antes_min),
                bloquear_festivos = VALUES(bloquear_festivos), usuarios_editan_horario = VALUES(usuarios_editan_horario)
        ")->execute([$margen, $fest, $edit]);
        echo json_encode(['success' => true, 'message' => 'Configuración guardada']);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

// ----------------------------------------------------------------------------
// FESTIVOS
// ----------------------------------------------------------------------------

function handleGetFestivos($data) {
    $ano = (int)($data['ano'] ?? date('Y'));
    try {
        $pdo = getDBConnection();
        horariosAutoImportarFestivos($pdo, [$ano]);
        $stmt = $pdo->prepare("SELECT id, fecha, nombre, origen FROM festivos WHERE YEAR(fecha) = ? ORDER BY fecha");
        $stmt->execute([$ano]);
        echo json_encode([
            'success'  => true,
            'festivos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'region'   => horariosRegionFestivos($pdo),
            'regiones' => horariosRegionesES(),
        ]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}
 
/** Admin: importa (o vuelve a sincronizar) nacionales + autonómicos de un año y guarda la región. */
function handleImportarFestivos($data) {
    $ano    = (int)($data['ano'] ?? date('Y'));
    $region = (string)($data['region'] ?? '');
    if ($ano < 2000 || $ano > (int)date('Y') + 2) return horariosFail('Año no válido');
    if (!array_key_exists($region, horariosRegionesES())) return horariosFail('Comunidad autónoma no válida');
    try {
        $pdo = getDBConnection();
        horariosAsegurarColumnasFestivos($pdo);
        $pdo->prepare("UPDATE horarios_config SET region_festivos = ? WHERE id = 1")->execute([$region]);
        $r = horariosImportarFestivos($pdo, $ano, $region);
        horariosLog($pdo, 'festivos_importados', "Festivos $ano importados ($region): {$r['nuevos']} nuevos, {$r['actualizados']} actualizados");
        echo json_encode([
            'success' => true,
            'message' => "Festivos $ano: {$r['nuevos']} nuevos" . ($r['actualizados'] ? ", {$r['actualizados']} actualizados" : '') . " ({$r['total']} en total)",
        ] + $r);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 502);
    }
}


function handleSaveFestivo($data) {
    $fecha  = $data['fecha'] ?? '';
    $nombre = trim($data['nombre'] ?? '');
    if (!horariosFechaValida($fecha) || $nombre === '') return horariosFail('Fecha y nombre son obligatorios');
    try {
        $pdo = getDBConnection();
        $pdo->prepare("INSERT INTO festivos (fecha, nombre) VALUES (?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)")
            ->execute([$fecha, mb_substr($nombre, 0, 120)]);
        echo json_encode(['success' => true, 'message' => 'Festivo guardado']);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

function handleDeleteFestivo($data) {
    $id = (int)($data['id'] ?? 0);
    if (!$id) return horariosFail('id requerido');
    try {
        getDBConnection()->prepare("DELETE FROM festivos WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

// ----------------------------------------------------------------------------
// EXCEPCIONES DE UN DÍA (admin): día libre o turno distinto
// ----------------------------------------------------------------------------

function handleGetExcepcionesHorario($data) {
    $uid   = (int)($data['usuario_id'] ?? 0);
    $fecha = $data['fecha'] ?? '';
    if (!$uid || !horariosFechaValida($fecha)) return horariosFail('usuario_id y fecha requeridos');
    try {
        $pdo = getDBConnection();
        $dia = horariosTramosDelDia($pdo, $uid, $fecha);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM horarios_excepciones WHERE usuario_id = ? AND fecha = ?");
        $stmt->execute([$uid, $fecha]);
        $tramos = [];
        foreach ($dia['tramos'] as $t) $tramos[] = ['hora_inicio' => substr($t['inicio'], 0, 5), 'hora_fin' => substr($t['fin'], 0, 5)];
        echo json_encode([
            'success'        => true,
            'tiene_excepcion'=> (int)$stmt->fetchColumn() > 0,
            'motivo'         => $dia['motivo'],
            'nota'           => $dia['nota'],
            'tramos'         => $tramos,
            'festivo'        => horariosFestivo($pdo, $fecha),
        ]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

/**
 * Sustituye las excepciones de (usuario|todos, fecha).
 * tipo = 'libre'    → sin tramos
 * tipo = 'especial' → tramos: [{hora_inicio, hora_fin}]
 */
function handleSaveExcepcionHorario($data) {
    $fecha = $data['fecha'] ?? '';
    $tipo  = ($data['tipo'] ?? '') === 'libre' ? 'libre' : 'especial';
    $nota  = mb_substr(trim($data['nota'] ?? ''), 0, 255);
    $todos = ($data['usuario_id'] ?? '') === 'todos';
    $uid   = (int)($data['usuario_id'] ?? 0);
    if (!horariosFechaValida($fecha) || (!$todos && !$uid)) return horariosFail('Usuario y fecha requeridos');

    $tramos = [];
    if ($tipo === 'especial') {
        list($tramos, $err) = horariosValidarTramos($data['tramos'] ?? [], false);
        if ($err) return horariosFail($err);
        if (!$tramos) return horariosFail('Añade al menos un tramo o marca el día como libre');
    }

    try {
        $pdo = getDBConnection();
        $ids = $todos
            ? $pdo->query("SELECT id FROM usuarios WHERE activo = 1")->fetchAll(PDO::FETCH_COLUMN)
            : [$uid];

        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM horarios_excepciones WHERE usuario_id = ? AND fecha = ?");
        $ins = $pdo->prepare("INSERT INTO horarios_excepciones (usuario_id, fecha, tipo, hora_inicio, hora_fin, nota, creado_por) VALUES (?, ?, ?, ?, ?, ?, 'admin')");
        foreach ($ids as $id) {
            $del->execute([$id, $fecha]);
            if ($tipo === 'libre') {
                $ins->execute([$id, $fecha, 'libre', null, null, $nota]);
            } else {
                foreach ($tramos as $t) $ins->execute([$id, $fecha, 'especial', $t['hora_inicio'], $t['hora_fin'], $nota]);
            }
        }
        $pdo->commit();
        horariosLog($pdo, 'horario_excepcion', "Excepción $tipo el $fecha para " . ($todos ? 'todos' : "usuario $uid"));
        echo json_encode(['success' => true, 'message' => 'Cambio del día guardado']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        horariosFail($e->getMessage(), 500);
    }
}

function handleDeleteExcepcionHorario($data) {
    $fecha = $data['fecha'] ?? '';
    $uid   = (int)($data['usuario_id'] ?? 0);
    if (!$uid || !horariosFechaValida($fecha)) return horariosFail('usuario_id y fecha requeridos');
    try {
        getDBConnection()->prepare("DELETE FROM horarios_excepciones WHERE usuario_id = ? AND fecha = ?")->execute([$uid, $fecha]);
        echo json_encode(['success' => true, 'message' => 'Se ha restaurado el horario habitual']);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

// ----------------------------------------------------------------------------
// EMPLEADO: mi turno de hoy (banner en el panel de fichaje)
// ----------------------------------------------------------------------------

function handleGetMiHorarioHoy($data) {
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if (!$uid) return horariosFail('No autenticado', 401);
    try {
        $pdo = getDBConnection();
        $hoy = date('Y-m-d');
        $dia = horariosTramosDelDia($pdo, $uid, $hoy);
        $tramos = [];
        foreach ($dia['tramos'] as $t) $tramos[] = substr($t['inicio'], 0, 5) . '–' . substr($t['fin'], 0, 5);
        $chk = comprobarHorarioFichaje($pdo, $uid);
        $stmt = $pdo->prepare("SELECT restringir_horario FROM usuarios WHERE id = ?");
        $stmt->execute([$uid]);
        echo json_encode([
            'success'       => true,
            'tramos'        => $tramos,
            'motivo'        => $dia['motivo'],
            'festivo'       => horariosFestivo($pdo, $hoy),
            'restringido'   => (int)$stmt->fetchColumn() === 1,
            'puede_fichar'  => $chk['permitido'],
            'mensaje'       => $chk['mensaje'],
        ]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

// ----------------------------------------------------------------------------
// ADMIN: intentos de fichaje bloqueados
// ----------------------------------------------------------------------------

function handleGetBloqueosHorario($data) {
    $limite = max(1, min(200, (int)($data['limite'] ?? 50)));
    try {
        $stmt = getDBConnection()->prepare("SELECT id, usuario_id, nombre, fecha_hora, origen, motivo FROM horarios_bloqueos ORDER BY fecha_hora DESC LIMIT $limite");
        $stmt->execute();
        echo json_encode(['success' => true, 'bloqueos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}

// ----------------------------------------------------------------------------
// EVENTOS DEL CALENDARIO (admin: todos o un usuario · empleado: solo él)
// data: start, end (YYYY-MM-DD, end exclusivo — lo manda FullCalendar),
//       usuario_id ('all' | id), mostrar_reales (bool), scope ('me' desde panel empleado)
// ----------------------------------------------------------------------------

function handleGetCalendarioEventos($data) {
    list($esAdmin, $sesionId) = horariosContexto($data);
    if (!$esAdmin && !$sesionId) return horariosFail('No autenticado', 401);

    $start = substr((string)($data['start'] ?? ''), 0, 10);
    $end   = substr((string)($data['end'] ?? ''), 0, 10);
    if (!horariosFechaValida($start)) $start = date('Y-m-01');
    if (!horariosFechaValida($end) || $end <= $start) $end = date('Y-m-d', strtotime("$start +1 month"));
    // Límite de rango para no reventar la consulta
    if ((strtotime($end) - strtotime($start)) / 86400 > 100) $end = date('Y-m-d', strtotime("$start +100 days"));

    $mostrarReales = !empty($data['mostrar_reales']);
    $filtro = $esAdmin ? ($data['usuario_id'] ?? 'all') : $sesionId;

    try {
        $pdo = getDBConnection();
        $cfg = horariosGetConfig($pdo);

        // --- Usuarios ---
        if ($filtro === 'all' || $filtro === '' || $filtro === null) {
            $usuarios = $pdo->query("SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE id = ?");
            $stmt->execute([(int)$filtro]);
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!$usuarios) { echo json_encode(['success' => true, 'events' => [], 'resumen' => []]); return; }

        $nombres = [];
        foreach ($usuarios as $u) $nombres[(int)$u['id']] = $u['nombre'];
        $ids = array_keys($nombres);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $varios = count($ids) > 1;

        // --- Horario semanal ---
        $semanal = [];
        $stmt = $pdo->prepare("SELECT usuario_id, dia_semana, hora_inicio, hora_fin FROM horarios_usuario WHERE usuario_id IN ($in) ORDER BY orden, hora_inicio");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $semanal[(int)$r['usuario_id']][(int)$r['dia_semana']][] = $r;
        }

        // --- Excepciones ---
        $excep = [];
        $stmt = $pdo->prepare("SELECT usuario_id, fecha, tipo, hora_inicio, hora_fin, nota FROM horarios_excepciones WHERE usuario_id IN ($in) AND fecha >= ? AND fecha < ? ORDER BY hora_inicio");
        $stmt->execute(array_merge($ids, [$start, $end]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $excep[(int)$r['usuario_id']][$r['fecha']][] = $r;
        }

        // --- Festivos ---
        $festivos = [];
        $events   = [];
        $stmt = $pdo->prepare("SELECT fecha, nombre FROM festivos WHERE fecha >= ? AND fecha < ?");
        $stmt->execute([$start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $festivos[$f['fecha']] = $f['nombre'];
            $events[] = [
                'id' => 'fest-' . $f['fecha'], 'start' => $f['fecha'], 'allDay' => true,
                'display' => 'background', 'title' => '🎉 ' . $f['nombre'],
                'classNames' => ['hc-festivo'],
                'extendedProps' => ['tipo' => 'festivo', 'nombre_festivo' => $f['nombre']],
            ];
        }

        // --- Ausencias CONFIRMADAS ---
        // Solo existen en `ausencias` las creadas por el admin o las solicitudes ya aprobadas;
        // las pendientes viven en `solicitudes_ausencia` y por tanto no salen aquí.
        $ausente = [];
        $iconos  = ['vacaciones' => '🏖️', 'baja_laboral' => '🏥', 'baja' => '🏥', 'suspension_indefinida' => '⏸️', 'asuntos_propios' => '📌', 'otro' => '📝'];
        try {
            $stmt = $pdo->prepare("
                SELECT id, usuario_id, nombre, tipo, fecha_inicio, fecha_fin, motivo, estado
                FROM ausencias
                WHERE usuario_id IN ($in) AND estado <> 'cancelada'
                  AND fecha_inicio < ? AND (fecha_fin IS NULL OR fecha_fin >= ?)
            ");
            $stmt->execute(array_merge($ids, [$end, $start]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $uid = (int)$a['usuario_id'];
                $finIncl = $a['fecha_fin'] ?: date('Y-m-d', strtotime("$end -1 day"));
                $tipoTxt = ucfirst(str_replace('_', ' ', $a['tipo']));
                $events[] = [
                    'id'     => 'aus-' . $a['id'],
                    'title'  => ($iconos[$a['tipo']] ?? '📝') . ' ' . ($varios ? $nombres[$uid] . ' · ' : '') . $tipoTxt,
                    'start'  => $a['fecha_inicio'],
                    'end'    => date('Y-m-d', strtotime("$finIncl +1 day")), // allDay: fin exclusivo
                    'allDay' => true,
                    'classNames' => ['hc-ausencia', 'hc-aus-' . preg_replace('/[^a-z_]/', '', $a['tipo'])],
                    'extendedProps' => [
                        'tipo' => 'ausencia', 'usuario_id' => $uid, 'nombre' => $nombres[$uid],
                        'tipo_ausencia' => $a['tipo'], 'motivo' => $a['motivo'],
                        'desde' => $a['fecha_inicio'], 'hasta' => $a['fecha_fin'], 'estado' => $a['estado'],
                    ],
                ];
                for ($d = max($a['fecha_inicio'], $start); $d <= $finIncl && $d < $end; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                    $ausente[$uid][$d] = true;
                }
            }
        } catch (Exception $e) {
            error_log('[horarios] ausencias: ' . $e->getMessage());
        }

        // --- Turnos planificados ---
        $resumen = [];
        foreach ($ids as $uid) {
            $resumen[$uid] = ['usuario_id' => $uid, 'nombre' => $nombres[$uid], 'color' => horariosColorUsuario($uid), 'min_plan' => 0, 'min_real' => 0, 'dias_ausencia' => 0];
        }

        for ($d = $start; $d < $end; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $dow = (int)date('N', strtotime($d));
            foreach ($ids as $uid) {
                if (!empty($ausente[$uid][$d])) { $resumen[$uid]['dias_ausencia']++; continue; }

                $color  = horariosColorUsuario($uid);
                $tramos = [];
                $clase  = 'hc-turno';
                if (isset($excep[$uid][$d])) {
                    $libre = false;
                    foreach ($excep[$uid][$d] as $e) if ($e['tipo'] === 'libre') $libre = true;
                    if ($libre) {
                        $nota = $excep[$uid][$d][0]['nota'];
                        $events[] = [
                            'id' => "libre-$uid-$d", 'start' => $d, 'allDay' => true,
                            'title' => '🌙 ' . ($varios ? $nombres[$uid] . ' · ' : '') . 'Día libre',
                            'classNames' => ['hc-libre'],
                            'extendedProps' => ['tipo' => 'libre', 'usuario_id' => $uid, 'nombre' => $nombres[$uid], 'fecha' => $d, 'nota' => $nota],
                        ];
                        continue;
                    }
                    $tramos = $excep[$uid][$d];
                    $clase  = 'hc-especial';
                } else {
                    if ($cfg['bloquear_festivos'] && isset($festivos[$d])) continue;
                    $tramos = $semanal[$uid][$dow] ?? [];
                }

                foreach ($tramos as $i => $t) {
                    $ini = substr($t['hora_inicio'], 0, 5);
                    $fin = substr($t['hora_fin'], 0, 5);
                    $finFecha = $fin <= $ini ? date('Y-m-d', strtotime("$d +1 day")) : $d;
                    $resumen[$uid]['min_plan'] += horariosMinutosTramo($ini, $fin);
                    $events[] = [
                        'id'    => "turno-$uid-$d-$i",
                        'title' => ($varios ? $nombres[$uid] : 'Mi turno') . ($clase === 'hc-especial' ? ' ★' : ''),
                        'start' => "{$d}T{$ini}:00",
                        'end'   => "{$finFecha}T{$fin}:00",
                        'backgroundColor' => $color,
                        'borderColor'     => $color,
                        'classNames'      => [$clase],
                        'extendedProps'   => [
                            'tipo' => $clase === 'hc-especial' ? 'especial' : 'turno',
                            'usuario_id' => $uid, 'nombre' => $nombres[$uid], 'fecha' => $d,
                            'nota' => $t['nota'] ?? null,
                        ],
                    ];
                }
            }
        }

        // --- Fichajes reales (opcional) ---
        if ($mostrarReales) {
            $desde = date('Y-m-d', strtotime("$start -1 day")); // por si hay turnos nocturnos
            $stmt = $pdo->prepare("
                SELECT usuario_id, tipo, fecha_hora
                FROM fichajes
                WHERE usuario_id IN ($in) AND es_descanso = 0 AND fecha_hora >= ? AND fecha_hora < ?
                ORDER BY usuario_id, fecha_hora
            ");
            $stmt->execute(array_merge($ids, [$desde, $end]));
            $abiertos = [];
            $n = 0;
            $cerrar = function ($uid, $ini, $finTs, $enCurso) use (&$events, &$resumen, &$n, $nombres, $varios, $start) {
                $iniTs = strtotime($ini);
                $dur = $finTs - $iniTs;
                if ($dur <= 0 || $dur > 16 * 3600) return;   // sanity cap: descarta pares corruptos
                if ($finTs < strtotime($start)) return;
                $resumen[$uid]['min_real'] += (int)floor($dur / 60);
                $events[] = [
                    'id'    => 'real-' . (++$n),
                    'title' => ($varios ? $nombres[$uid] . ' · ' : '') . ($enCurso ? 'Fichado (en curso)' : 'Fichado'),
                    'start' => str_replace(' ', 'T', $ini),
                    'end'   => date('Y-m-d\TH:i:s', $finTs),
                    'classNames' => ['hc-real'] ,
                    'extendedProps' => ['tipo' => 'real', 'usuario_id' => $uid, 'nombre' => $nombres[$uid], 'en_curso' => $enCurso],
                ];
            };
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $uid = (int)$f['usuario_id'];
                if ($f['tipo'] === 'In') {
                    $abiertos[$uid] = $f['fecha_hora'];
                } elseif ($f['tipo'] === 'Out' && isset($abiertos[$uid])) {
                    $cerrar($uid, $abiertos[$uid], strtotime($f['fecha_hora']), false);
                    unset($abiertos[$uid]);
                }
            }
            foreach ($abiertos as $uid => $ini) {
                if (time() - strtotime($ini) < 16 * 3600) $cerrar($uid, $ini, time(), true);
            }

            // Intentos bloqueados (solo admin)
            if ($esAdmin) {
                try {
                    $stmt = $pdo->prepare("SELECT id, usuario_id, fecha_hora, origen, motivo FROM horarios_bloqueos WHERE usuario_id IN ($in) AND fecha_hora >= ? AND fecha_hora < ?");
                    $stmt->execute(array_merge($ids, [$start, $end]));
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
                        $uid = (int)$b['usuario_id'];
                        $events[] = [
                            'id' => 'bloq-' . $b['id'],
                            'title' => '⛔ ' . ($nombres[$uid] ?? '') . ' (' . $b['origen'] . ')',
                            'start' => str_replace(' ', 'T', $b['fecha_hora']),
                            'end'   => date('Y-m-d\TH:i:s', strtotime($b['fecha_hora']) + 900),
                            'classNames' => ['hc-bloqueo'],
                            'extendedProps' => ['tipo' => 'bloqueo', 'usuario_id' => $uid, 'nombre' => $nombres[$uid] ?? '', 'motivo' => $b['motivo'], 'origen' => $b['origen']],
                        ];
                    }
                } catch (Exception $e) { /* tabla aún no creada */ }
            }
        }

        echo json_encode([
            'success' => true,
            'events'  => $events,
            'resumen' => array_values($resumen),
            'rango'   => ['start' => $start, 'end' => $end],
        ]);
    } catch (Exception $e) {
        horariosFail($e->getMessage(), 500);
    }
}
?>