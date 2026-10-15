<?php
/* ============================================================
   Oficia Clock · Historial de cambios de fichajes (auditoría)

   Cómo funciona
   - Tres triggers en la tabla `fichajes` (alta, modificación y borrado)
     guardan en `fichajes_historial` una copia del fichaje antes y después
     de cada cambio. Al ser triggers, se registra TODO: panel de admin,
     crons, sincronizaciones e incluso cambios hechos a mano desde phpMyAdmin.
   - api.php indica en cada petición quién actúa, desde qué IP, con qué
     acción y con qué motivo (variables de sesión MySQL @aud_*).
     Si un cambio llega sin ese contexto, queda como "bd:" = base de datos directa.
   - Cada registro lleva un hash SHA-256 encadenado con el anterior:
     si alguien altera o borra un registro del historial, la verificación
     lo detecta.

   Requiere permisos TRIGGER y CREATE ROUTINE en la BD (los tiene el usuario
   normal de Plesk). Se instala desde el panel: Auditoría → Activar.
   ============================================================ */

// Acciones que exigen motivo (cambios manuales sobre fichajes)
const AUD_ACCIONES_CON_MOTIVO = [
    'edit_fichaje', 'delete_fichaje', 'add_fichaje_manual',
    'delete_fichajes_rango', 'delete_fichajes_rango_jibble', 'execute_bulk_delete',
    'auditoria_restaurar',
];

/* ---------------------------------------------------------------- contexto
   Se llama en api.php ANTES del switch. Devuelve false (y responde) si falta
   el motivo en una acción que lo exige. */
function auditoriaContexto($action, $data) {
    $motivo = trim((string)($data['motivo'] ?? ''));

    if (in_array($action, AUD_ACCIONES_CON_MOTIVO, true) && mb_strlen($motivo) < 3) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Indica el motivo del cambio (queda registrado en el historial).',
            'motivo_requerido' => true,
        ]);
        return false;
    }

    try {
        if (!empty($_SESSION['admin_logged_in'])) {
            $actor = 'admin:' . ($_SESSION['admin_username'] ?? ('#' . ($_SESSION['admin_id'] ?? '?')));
        } elseif (!empty($_SESSION['user_id'])) {
            $actor = 'empleado:' . (int)$_SESSION['user_id'];
        } elseif ($action === 'rfid_checkin') {
            $serial = trim((string)($data['device_serial'] ?? ''));
            $actor = 'dispositivo:' . ($serial !== '' ? $serial : 'rfid');
        } else {
            $actor = 'sistema';
        }
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null; // Cloudflare delante

        $pdo = getDBConnection();
        $st = $pdo->prepare("SET @aud_actor = ?, @aud_origen = ?, @aud_motivo = ?, @aud_ip = ?");
        $st->execute([mb_substr($actor, 0, 120), mb_substr($action, 0, 60), $motivo !== '' ? mb_substr($motivo, 0, 500) : null, $ip]);
    } catch (Exception $e) {
        error_log('[auditoria] contexto: ' . $e->getMessage());   // nunca bloquea el fichaje
    }
    return true;
}

// Para crons y scripts sueltos: auditoriaContextoSistema($pdo, 'cron-auto-clockout');
function auditoriaContextoSistema($pdo, $origen) {
    try {
        $pdo->prepare("SET @aud_actor = 'sistema', @aud_origen = ?, @aud_motivo = NULL, @aud_ip = NULL")->execute([$origen]);
    } catch (Exception $e) {}
}

/* ---------------------------------------------------------------- instalación */
function auditoriaSQLInstalacion() {
    // Campos del fichaje que se guardan y que, si cambian, cuentan como modificación
    $json = function ($r) {
        return "JSON_OBJECT('usuario_id',$r.usuario_id,'nombre',$r.nombre,'jibble_person_id',$r.jibble_person_id,"
             . "'tipo',$r.tipo,'fecha_hora',$r.fecha_hora,'timestamp_unix',$r.timestamp_unix,"
             . "'nota',$r.nota,'dispositivo',$r.dispositivo,'es_descanso',$r.es_descanso,"
             . "'latitud',$r.latitud,'longitud',$r.longitud)";
    };
    $cambia = "NOT (OLD.usuario_id <=> NEW.usuario_id AND OLD.tipo <=> NEW.tipo AND OLD.fecha_hora <=> NEW.fecha_hora"
            . " AND OLD.timestamp_unix <=> NEW.timestamp_unix AND OLD.nota <=> NEW.nota"
            . " AND OLD.dispositivo <=> NEW.dispositivo AND OLD.es_descanso <=> NEW.es_descanso)";

    return [
        "CREATE TABLE IF NOT EXISTS fichajes_historial (
            id          BIGINT AUTO_INCREMENT PRIMARY KEY,
            fichaje_id  INT NOT NULL,
            usuario_id  INT NULL,
            accion      VARCHAR(10) NOT NULL COMMENT 'INSERT | UPDATE | DELETE',
            antes       LONGTEXT NULL,
            despues     LONGTEXT NULL,
            actor       VARCHAR(120) NOT NULL,
            origen      VARCHAR(60) NOT NULL,
            motivo      VARCHAR(500) NULL,
            ip          VARCHAR(45) NULL,
            creado      DATETIME(6) NOT NULL,
            hash_prev   CHAR(64) NOT NULL,
            hash        CHAR(64) NOT NULL,
            KEY idx_fichaje (fichaje_id),
            KEY idx_usuario_fecha (usuario_id, creado),
            KEY idx_creado (creado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fichajes_historial_cadena (
            id          TINYINT PRIMARY KEY,
            ultimo_hash CHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "INSERT IGNORE INTO fichajes_historial_cadena (id, ultimo_hash) VALUES (1, REPEAT('0', 64))",

        "DROP PROCEDURE IF EXISTS aud_fichajes_registrar",
        "CREATE PROCEDURE aud_fichajes_registrar(
            IN p_fid INT, IN p_uid INT, IN p_accion VARCHAR(10),
            IN p_antes LONGTEXT CHARSET utf8mb4, IN p_despues LONGTEXT CHARSET utf8mb4)
        BEGIN
            DECLARE v_prev   CHAR(64);
            DECLARE v_hash   CHAR(64);
            DECLARE v_now    DATETIME(6);
            DECLARE v_actor  VARCHAR(120) CHARSET utf8mb4;
            DECLARE v_origen VARCHAR(60)  CHARSET utf8mb4;
            DECLARE v_motivo VARCHAR(500) CHARSET utf8mb4;
            DECLARE v_ip     VARCHAR(45)  CHARSET utf8mb4;

            SET v_actor  = LEFT(COALESCE(@aud_actor, CONCAT('bd:', USER())), 120);
            SET v_origen = LEFT(COALESCE(@aud_origen, 'bd_directa'), 60);
            SET v_motivo = LEFT(@aud_motivo, 500);
            SET v_ip     = LEFT(@aud_ip, 45);
            SET v_now    = NOW(6);

            -- FOR UPDATE serializa los registros simultáneos (p. ej. ráfagas del ZKteco)
            SELECT ultimo_hash INTO v_prev FROM fichajes_historial_cadena WHERE id = 1 FOR UPDATE;
            SET v_hash = SHA2(CONCAT_WS('|', v_prev, p_fid, IFNULL(p_uid, ''), p_accion,
                         IFNULL(p_antes, ''), IFNULL(p_despues, ''), v_actor, v_origen,
                         IFNULL(v_motivo, ''), IFNULL(v_ip, ''), DATE_FORMAT(v_now, '%Y-%m-%d %H:%i:%s.%f')), 256);

            INSERT INTO fichajes_historial
                (fichaje_id, usuario_id, accion, antes, despues, actor, origen, motivo, ip, creado, hash_prev, hash)
            VALUES (p_fid, p_uid, p_accion, p_antes, p_despues, v_actor, v_origen, v_motivo, v_ip, v_now, v_prev, v_hash);

            UPDATE fichajes_historial_cadena SET ultimo_hash = v_hash WHERE id = 1;
        END",

        "DROP TRIGGER IF EXISTS trg_fichajes_aud_ins",
        "CREATE TRIGGER trg_fichajes_aud_ins AFTER INSERT ON fichajes FOR EACH ROW
            CALL aud_fichajes_registrar(NEW.id, NEW.usuario_id, 'INSERT', NULL, " . $json('NEW') . ")",

        "DROP TRIGGER IF EXISTS trg_fichajes_aud_upd",
        "CREATE TRIGGER trg_fichajes_aud_upd AFTER UPDATE ON fichajes FOR EACH ROW
        BEGIN
            IF $cambia THEN
                CALL aud_fichajes_registrar(NEW.id, NEW.usuario_id, 'UPDATE', " . $json('OLD') . ", " . $json('NEW') . ");
            END IF;
        END",

        "DROP TRIGGER IF EXISTS trg_fichajes_aud_del",
        "CREATE TRIGGER trg_fichajes_aud_del AFTER DELETE ON fichajes FOR EACH ROW
            CALL aud_fichajes_registrar(OLD.id, OLD.usuario_id, 'DELETE', " . $json('OLD') . ", NULL)",
    ];
}

function auditoriaInstalada($pdo) {
    try {
        $n = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND TRIGGER_NAME IN ('trg_fichajes_aud_ins','trg_fichajes_aud_upd','trg_fichajes_aud_del')
        ")->fetchColumn();
        return $n === 3;
    } catch (Exception $e) {
        return false;
    }
}

/* ---------------------------------------------------------------- dispatcher
   En api.php, antes del switch:
     if (strpos($action, 'auditoria_') === 0 || $action === 'mis_cambios_fichajes') { auditoriaDispatch($action, $data); exit; } */
function auditoriaDispatch($action, $data) {
    try {
        switch ($action) {
            case 'auditoria_estado':     requireAdmin(); audEstado(); break;
            case 'auditoria_instalar':   requireAdmin(); audInstalar(); break;
            case 'auditoria_fichaje':    requireAdmin(); audFichaje($data); break;
            case 'auditoria_listado':    requireAdmin(); audListado($data); break;
            case 'auditoria_marcas':     requireAdmin(); audMarcas($data); break;
            case 'auditoria_restaurar':  requireAdmin(); audRestaurar($data); break;
            case 'auditoria_verificar':  requireAdmin(); audVerificar(); break;
            case 'mis_cambios_fichajes': audMisCambios(); break;
            default:
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Action not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function audEstado() {
    $pdo = getDBConnection();
    $inst = auditoriaInstalada($pdo);
    $res = ['success' => true, 'instalado' => $inst, 'total' => 0, 'desde' => null];
    if ($inst) {
        $r = $pdo->query("SELECT COUNT(*) AS n, MIN(creado) AS desde FROM fichajes_historial")->fetch();
        $res['total'] = (int)$r['n'];
        $res['desde'] = $r['desde'] ? substr($r['desde'], 0, 19) : null;
    }
    echo json_encode($res);
}

function audInstalar() {
    $pdo = getDBConnection();
    $paso = 0;
    try {
        foreach (auditoriaSQLInstalacion() as $sql) { $paso++; $pdo->exec($sql); }
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $ayuda = '';
        if (strpos($msg, '1419') !== false || stripos($msg, 'log_bin_trust_function_creators') !== false) {
            $ayuda = ' El servidor tiene el log binario activo: pide que activen log_bin_trust_function_creators=1 o instala el SQL como root desde phpMyAdmin.';
        } elseif (strpos($msg, '1142') !== false || strpos($msg, '1044') !== false || stripos($msg, 'denied') !== false) {
            $ayuda = ' El usuario de la base de datos no tiene permiso TRIGGER / CREATE ROUTINE.';
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "Fallo en el paso $paso: $msg.$ayuda"]);
        return;
    }
    echo json_encode(['success' => true, 'message' => 'Historial de cambios activado. A partir de ahora se registra todo cambio en los fichajes.']);
}

// Traduce el actor técnico a algo legible
function audActorLegible($actor, $nombres = []) {
    if (strpos($actor, 'admin:') === 0)       return ['tipo' => 'admin', 'texto' => 'Administración (' . substr($actor, 6) . ')'];
    if (strpos($actor, 'empleado:') === 0) {
        $id = (int)substr($actor, 9);
        return ['tipo' => 'empleado', 'texto' => $nombres[$id] ?? 'El propio empleado'];
    }
    if (strpos($actor, 'dispositivo:') === 0) return ['tipo' => 'dispositivo', 'texto' => 'Terminal ' . substr($actor, 12)];
    if ($actor === 'sistema')                 return ['tipo' => 'sistema', 'texto' => 'Sistema'];
    if (strpos($actor, 'bd:') === 0)          return ['tipo' => 'bd', 'texto' => 'Base de datos directa (' . substr($actor, 3) . ')'];
    return ['tipo' => 'otro', 'texto' => $actor];
}

function audFila($r, $nombres = [], $conIp = true) {
    $a = audActorLegible($r['actor'], $nombres);
    $out = [
        'id'         => (int)$r['id'],
        'fichaje_id' => (int)$r['fichaje_id'],
        'usuario_id' => $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null,
        'empleado'   => $r['empleado'] ?? null,
        'accion'     => $r['accion'],
        'antes'      => $r['antes'] !== null ? json_decode($r['antes'], true) : null,
        'despues'    => $r['despues'] !== null ? json_decode($r['despues'], true) : null,
        'actor'      => $a['texto'],
        'actor_tipo' => $a['tipo'],
        'origen'     => $r['origen'],
        'motivo'     => $r['motivo'],
        'fecha'      => substr($r['creado'], 0, 19),
    ];
    if ($conIp) $out['ip'] = $r['ip'];
    return $out;
}

function audNombres($pdo) {
    return $pdo->query("SELECT id, nombre FROM usuarios")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function audExigirInstalada($pdo) {
    if (!auditoriaInstalada($pdo)) {
        echo json_encode(['success' => false, 'error' => 'El historial de cambios no está activado.', 'no_instalado' => true]);
        return false;
    }
    return true;
}

function audFichaje($data) {
    $pdo = getDBConnection();
    if (!audExigirInstalada($pdo)) return;
    $id = (int)($data['fichaje_id'] ?? 0);
    $st = $pdo->prepare("SELECT h.*, u.nombre AS empleado FROM fichajes_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE h.fichaje_id = ? ORDER BY h.id");
    $st->execute([$id]);
    $nombres = audNombres($pdo);
    $filas = array_map(function ($r) use ($nombres) { return audFila($r, $nombres); }, $st->fetchAll());
    $existe = (bool)$pdo->query("SELECT 1 FROM fichajes WHERE id = " . $id)->fetchColumn();
    echo json_encode(['success' => true, 'fichaje_id' => $id, 'existe' => $existe, 'historial' => $filas]);
}

// Condición SQL de "cambio relevante": todo lo que no sea un fichaje normal del propio empleado/terminal
function audCondCambios($alias = 'h') {
    return "($alias.accion IN ('UPDATE','DELETE') OR $alias.actor LIKE 'admin:%' OR $alias.actor LIKE 'bd:%')";
}

function audListado($data) {
    $pdo = getDBConnection();
    if (!audExigirInstalada($pdo)) return;

    $where = [];
    $params = [];
    if (($data['tipo'] ?? 'cambios') !== 'todo') $where[] = audCondCambios();
    if (!empty($data['usuario_id']) && $data['usuario_id'] !== 'all') { $where[] = 'h.usuario_id = ?'; $params[] = (int)$data['usuario_id']; }
    if (!empty($data['accion']) && in_array($data['accion'], ['INSERT', 'UPDATE', 'DELETE'], true)) { $where[] = 'h.accion = ?'; $params[] = $data['accion']; }
    if (!empty($data['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['desde'])) { $where[] = 'h.creado >= ?'; $params[] = $data['desde'] . ' 00:00:00'; }
    if (!empty($data['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['hasta'])) { $where[] = 'h.creado < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $data['hasta']; }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $porPagina = 50;
    $pagina = max(1, (int)($data['pagina'] ?? 1));
    $st = $pdo->prepare("SELECT COUNT(*) FROM fichajes_historial h $sqlWhere");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("
        SELECT h.*, u.nombre AS empleado,
               EXISTS(SELECT 1 FROM fichajes f WHERE f.id = h.fichaje_id) AS existe
        FROM fichajes_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id
        $sqlWhere ORDER BY h.id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
    $st->execute($params);
    $nombres = audNombres($pdo);
    $filas = array_map(function ($r) use ($nombres) {
        $f = audFila($r, $nombres);
        $f['existe'] = (bool)$r['existe'];
        return $f;
    }, $st->fetchAll());

    echo json_encode(['success' => true, 'total' => $total, 'pagina' => $pagina, 'paginas' => max(1, (int)ceil($total / $porPagina)), 'registros' => $filas]);
}

// Para pintar una marca en los fichajes que han sido modificados o añadidos a mano
function audMarcas($data) {
    $pdo = getDBConnection();
    if (!auditoriaInstalada($pdo)) { echo json_encode(['success' => true, 'marcas' => new stdClass()]); return; }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['ids'] ?? [])))));
    if (!$ids) { echo json_encode(['success' => true, 'marcas' => new stdClass()]); return; }
    $ids = array_slice($ids, 0, 2000);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT fichaje_id, SUM(accion = 'UPDATE') AS ediciones, MAX(accion = 'INSERT' AND actor LIKE 'admin:%') AS manual
        FROM fichajes_historial WHERE fichaje_id IN ($in) GROUP BY fichaje_id
        HAVING ediciones > 0 OR manual = 1");
    $st->execute($ids);
    $m = [];
    foreach ($st->fetchAll() as $r) $m[$r['fichaje_id']] = ['ediciones' => (int)$r['ediciones'], 'manual' => (bool)$r['manual']];
    echo json_encode(['success' => true, 'marcas' => $m ?: new stdClass()]);
}

function audRestaurar($data) {
    $pdo = getDBConnection();
    if (!audExigirInstalada($pdo)) return;
    $hid = (int)($data['historial_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM fichajes_historial WHERE id = ? AND accion = 'DELETE'");
    $st->execute([$hid]);
    $h = $st->fetch();
    if (!$h) { echo json_encode(['success' => false, 'error' => 'Registro de borrado no encontrado']); return; }

    $fid = (int)$h['fichaje_id'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM fichajes WHERE id = ?");
    $st->execute([$fid]);
    if ($st->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Ese fichaje ya existe (quizá ya se restauró).']); return; }

    $a = json_decode($h['antes'], true);
    if (!$a || empty($a['fecha_hora'])) { echo json_encode(['success' => false, 'error' => 'El historial no tiene los datos del fichaje']); return; }

    $st = $pdo->prepare("
        INSERT INTO fichajes (id, usuario_id, jibble_person_id, nombre, tipo, fecha_hora, timestamp_unix,
                              nota, dispositivo, es_descanso, latitud, longitud, sincronizado_jibble)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
    $st->execute([
        $fid, $a['usuario_id'], $a['jibble_person_id'], $a['nombre'], $a['tipo'], $a['fecha_hora'],
        $a['timestamp_unix'] ?: strtotime($a['fecha_hora']), $a['nota'], $a['dispositivo'],
        (int)($a['es_descanso'] ?? 0), $a['latitud'], $a['longitud'],
    ]);

    // Recalcular emparejamientos entrada/salida, horas y resumen de ese día
    $fecha = substr($a['fecha_hora'], 0, 10);
    try {
        if (function_exists('calcularHorasYRelacionesDia')) calcularHorasYRelacionesDia($pdo, (int)$a['usuario_id'], $fecha);
        if (function_exists('actualizarResumenDiario')) actualizarResumenDiario($pdo, (int)$a['usuario_id'], $a['jibble_person_id'], $a['nombre'], $fecha);
    } catch (Exception $e) {
        error_log('[auditoria] recalcular tras restaurar: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Fichaje restaurado (' . ($a['tipo'] === 'In' ? 'entrada' : 'salida') . ' del ' . date('d/m/Y H:i', strtotime($a['fecha_hora'])) . '). No se reenvía a Jibble.', 'fichaje_id' => $fid]);
}

// Recalcula la cadena completa. Detecta registros alterados, borrados o insertados a mano.
function audVerificar() {
    $pdo = getDBConnection();
    if (!audExigirInstalada($pdo)) return;

    $esperado = str_repeat('0', 64);
    $total = 0;
    $errores = [];
    $ultimoId = 0;
    do {
        $st = $pdo->prepare("
            SELECT id, hash_prev, hash,
                   SHA2(CONCAT_WS('|', hash_prev, fichaje_id, IFNULL(usuario_id, ''), accion,
                        IFNULL(antes, ''), IFNULL(despues, ''), actor, origen,
                        IFNULL(motivo, ''), IFNULL(ip, ''), DATE_FORMAT(creado, '%Y-%m-%d %H:%i:%s.%f')), 256) AS recalculado
            FROM fichajes_historial WHERE id > ? ORDER BY id LIMIT 5000");
        $st->execute([$ultimoId]);
        $filas = $st->fetchAll();
        foreach ($filas as $r) {
            $total++;
            if ($r['hash_prev'] !== $esperado) $errores[] = ['id' => (int)$r['id'], 'problema' => 'Falta un registro anterior o se ha insertado uno a mano'];
            if ($r['recalculado'] !== $r['hash']) $errores[] = ['id' => (int)$r['id'], 'problema' => 'El contenido del registro ha sido modificado'];
            $esperado = $r['hash'];
            $ultimoId = (int)$r['id'];
            if (count($errores) >= 50) break 2;
        }
    } while (count($filas) === 5000);

    $cadena = $pdo->query("SELECT ultimo_hash FROM fichajes_historial_cadena WHERE id = 1")->fetchColumn();
    if (!count($errores) && $cadena !== $esperado) $errores[] = ['id' => null, 'problema' => 'Se han borrado los últimos registros del historial'];

    echo json_encode([
        'success' => true,
        'integro' => !$errores,
        'total'   => $total,
        'errores' => $errores,
        'message' => $errores
            ? '⚠️ El historial ha sido manipulado: ' . count($errores) . ' problema(s) detectado(s).'
            : "✅ Historial íntegro: $total registros verificados, ninguno alterado.",
    ]);
}

// Empleado: cambios hechos en SUS fichajes por la empresa (transparencia para el trabajador)
function audMisCambios() {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    $pdo = getDBConnection();
    if (!auditoriaInstalada($pdo)) { echo json_encode(['success' => true, 'cambios' => []]); return; }
    $st = $pdo->prepare("
        SELECT h.* FROM fichajes_historial h
        WHERE h.usuario_id = ? AND " . audCondCambios() . " AND h.creado >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
        ORDER BY h.id DESC LIMIT 100");
    $st->execute([(int)$_SESSION['user_id']]);
    $filas = array_map(function ($r) {
        $f = audFila($r, [], false);
        // Al empleado no le mostramos el usuario del admin, solo que fue la empresa
        if ($f['actor_tipo'] === 'admin') $f['actor'] = 'Administración';
        if ($f['actor_tipo'] === 'bd')    $f['actor'] = 'Cambio directo en base de datos';
        return $f;
    }, $st->fetchAll());
    echo json_encode(['success' => true, 'cambios' => $filas]);
}
