<?php
/**
 * horarios_helper.php — Oficia Clock
 * Lógica compartida de horarios. Lo incluyen api.php y zkteco_adms_listener.php:
 *     require_once __DIR__ . '/horarios_helper.php';
 * No ejecuta nada al incluirse (solo define funciones) y todas reciben $pdo.
 *
 * Política de fallo: si algo va mal (tabla inexistente, error SQL) se PERMITE
 * fichar y se escribe en error_log. Preferimos un fichaje de más a dejar a
 * toda la plantilla bloqueada por un fallo de BD.
 */

if (!function_exists('horariosGetConfig')) {

function horariosGetConfig($pdo) {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $row = false;
    try {
        $row = $pdo->query("SELECT * FROM horarios_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $row = false; }
    $cfg = [
        'margen_antes_min'        => isset($row['margen_antes_min']) ? (int)$row['margen_antes_min'] : 15,
        'bloquear_festivos'       => isset($row['bloquear_festivos']) ? (int)$row['bloquear_festivos'] : 1,
        'usuarios_editan_horario' => isset($row['usuarios_editan_horario']) ? (int)$row['usuarios_editan_horario'] : 1,
    ];
    return $cfg;
}

/** Normaliza "9:5", "09:05", "09:05:00" → "09:05:00". Devuelve null si no es válido. */
function horariosNormalizarHora($h) {
    $h = trim((string)$h);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $h, $m)) return null;
    $hh = (int)$m[1]; $mm = (int)$m[2];
    if ($hh > 23 || $mm > 59) return null;
    return sprintf('%02d:%02d:00', $hh, $mm);
}

/** Minutos de un tramo (gestiona cruce de medianoche). */
function horariosMinutosTramo($inicio, $fin) {
    $a = (int)substr($inicio, 0, 2) * 60 + (int)substr($inicio, 3, 2);
    $b = (int)substr($fin, 0, 2) * 60 + (int)substr($fin, 3, 2);
    if ($b <= $a) $b += 1440;
    return $b - $a;
}

/**
 * Tramos que tiene un usuario en una fecha concreta.
 * Prioridad: excepción del día > horario semanal.
 * Devuelve ['tramos' => [['inicio','fin','origen']], 'motivo' => semanal|especial|libre|sin_turno, 'nota' => ?]
 */
function horariosTramosDelDia($pdo, $usuarioId, $fecha) {
    $stmt = $pdo->prepare("
        SELECT tipo, hora_inicio, hora_fin, nota
        FROM horarios_excepciones
        WHERE usuario_id = ? AND fecha = ?
        ORDER BY hora_inicio
    ");
    $stmt->execute([$usuarioId, $fecha]);
    $exc = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($exc) {
        $tramos = [];
        foreach ($exc as $e) {
            if ($e['tipo'] === 'libre') {
                return ['tramos' => [], 'motivo' => 'libre', 'nota' => $e['nota']];
            }
            if ($e['hora_inicio'] && $e['hora_fin']) {
                $tramos[] = ['inicio' => $e['hora_inicio'], 'fin' => $e['hora_fin'], 'origen' => 'especial'];
            }
        }
        return ['tramos' => $tramos, 'motivo' => 'especial', 'nota' => $exc[0]['nota']];
    }

    $dia = (int)date('N', strtotime($fecha));
    $stmt = $pdo->prepare("
        SELECT hora_inicio, hora_fin
        FROM horarios_usuario
        WHERE usuario_id = ? AND dia_semana = ?
        ORDER BY orden, hora_inicio
    ");
    $stmt->execute([$usuarioId, $dia]);
    $tramos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tramos[] = ['inicio' => $t['hora_inicio'], 'fin' => $t['hora_fin'], 'origen' => 'semanal'];
    }
    return ['tramos' => $tramos, 'motivo' => $tramos ? 'semanal' : 'sin_turno', 'nota' => null];
}

function horariosFestivo($pdo, $fecha) {
    try {
        $stmt = $pdo->prepare("SELECT nombre FROM festivos WHERE fecha = ? LIMIT 1");
        $stmt->execute([$fecha]);
        $n = $stmt->fetchColumn();
        return $n !== false ? $n : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * ¿Puede este usuario fichar ENTRADA en este momento?
 * Solo aplica si usuarios.restringir_horario = 1. Las SALIDAS no se bloquean nunca.
 *
 * Ventana válida de cada tramo: [inicio - margen_antes, fin]
 * Los turnos nocturnos del día anterior (p.ej. 20:00–02:00) también cuentan.
 *
 * @return array ['permitido' => bool, 'mensaje' => string, 'proximo_turno' => 'HH:MM'|null]
 */
function comprobarHorarioFichaje($pdo, $usuarioId, $ts = null) {
    $ts = $ts ?: time();
    try {
        $stmt = $pdo->prepare("SELECT restringir_horario FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u || (int)($u['restringir_horario'] ?? 0) !== 1) {
            return ['permitido' => true, 'mensaje' => '', 'proximo_turno' => null];
        }

        $cfg    = horariosGetConfig($pdo);
        $margen = max(0, $cfg['margen_antes_min']) * 60;
        $hoy    = date('Y-m-d', $ts);
        $ayer   = date('Y-m-d', $ts - 86400);

        $diaHoy = horariosTramosDelDia($pdo, $usuarioId, $hoy);

        // Festivo: bloquea salvo que el admin haya puesto un turno especial ese día
        if ($cfg['bloquear_festivos'] && $diaHoy['motivo'] !== 'especial') {
            $festivo = horariosFestivo($pdo, $hoy);
            if ($festivo) {
                return ['permitido' => false, 'mensaje' => "⛔ Hoy es festivo ({$festivo}). No tienes turno asignado.", 'proximo_turno' => null];
            }
        }

        // Ventanas: tramos de hoy + tramos de ayer que cruzan medianoche
        $ventanas = [];
        foreach ($diaHoy['tramos'] as $t) {
            $ini = strtotime("$hoy {$t['inicio']}");
            $fin = strtotime("$hoy {$t['fin']}");
            if ($fin <= $ini) $fin += 86400;
            $ventanas[] = [$ini, $fin];
        }
        $diaAyer = horariosTramosDelDia($pdo, $usuarioId, $ayer);
        foreach ($diaAyer['tramos'] as $t) {
            $ini = strtotime("$ayer {$t['inicio']}");
            $fin = strtotime("$ayer {$t['fin']}");
            if ($fin <= $ini) {
                $fin += 86400;
                $ventanas[] = [$ini, $fin];
            }
        }

        foreach ($ventanas as $v) {
            if ($ts >= $v[0] - $margen && $ts <= $v[1]) {
                return ['permitido' => true, 'mensaje' => '', 'proximo_turno' => null];
            }
        }

        // Bloqueado: construir mensaje útil
        $proximo = null;
        foreach ($ventanas as $v) {
            if ($v[0] - $margen > $ts && ($proximo === null || $v[0] < $proximo)) $proximo = $v[0];
        }
        if ($diaHoy['motivo'] === 'libre') {
            $msg = '⛔ Hoy tienes el día libre' . ($diaHoy['nota'] ? " ({$diaHoy['nota']})" : '') . '.';
        } elseif (!$diaHoy['tramos']) {
            $msg = '⛔ Hoy no tienes turno asignado. Habla con tu responsable si crees que es un error.';
        } elseif ($proximo) {
            $msg = '⛔ Fuera de tu horario. Tu turno empieza a las ' . date('H:i', $proximo)
                 . ($margen ? ' (puedes fichar desde las ' . date('H:i', $proximo - $margen) . ')' : '') . '.';
        } else {
            $msg = '⛔ Tu turno de hoy ya ha terminado. No puedes fichar entrada fuera de tu horario.';
        }
        return ['permitido' => false, 'mensaje' => $msg, 'proximo_turno' => $proximo ? date('H:i', $proximo) : null];

    } catch (Exception $e) {
        error_log('[horarios] comprobarHorarioFichaje: ' . $e->getMessage());
        return ['permitido' => true, 'mensaje' => '', 'proximo_turno' => null];
    }
}

function horariosRegistrarBloqueo($pdo, $usuarioId, $nombre, $origen, $motivo, $ts = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO horarios_bloqueos (usuario_id, nombre, fecha_hora, origen, motivo)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuarioId, $nombre, date('Y-m-d H:i:s', $ts ?: time()), $origen, mb_substr((string)$motivo, 0, 255)]);
    } catch (Exception $e) {
        error_log('[horarios] registrarBloqueo: ' . $e->getMessage());
    }
}

/** Color estable por usuario para el calendario. */
function horariosColorUsuario($usuarioId) {
    $paleta = ['#4f7cff', '#13a37a', '#e8833a', '#9b5de5', '#0aa1c4', '#d6457a',
               '#6b8e23', '#c0912b', '#5a67d8', '#2f9e44', '#e5484d', '#8a6d3b'];
    return $paleta[((int)$usuarioId) % count($paleta)];
}


// ----------------------------------------------------------------------------
// FESTIVOS AUTOMÁTICOS (nacionales + autonómicos) desde Nager.Date
// https://date.nager.at — API pública, gratuita, sin clave. Los locales se añaden a mano.
// ----------------------------------------------------------------------------

function horariosRegionesES() {
    return [
        ''      => 'Solo nacionales',
        'ES-AN' => 'Andalucía',          'ES-AR' => 'Aragón',            'ES-AS' => 'Asturias',
        'ES-IB' => 'Illes Balears',      'ES-CN' => 'Canarias',          'ES-CB' => 'Cantabria',
        'ES-CL' => 'Castilla y León',    'ES-CM' => 'Castilla-La Mancha','ES-CT' => 'Cataluña',
        'ES-VC' => 'Comunitat Valenciana','ES-EX' => 'Extremadura',      'ES-GA' => 'Galicia',
        'ES-MD' => 'Comunidad de Madrid','ES-MC' => 'Región de Murcia',  'ES-NC' => 'Navarra',
        'ES-PV' => 'País Vasco',         'ES-RI' => 'La Rioja',
    ];
}

/** Crea (una sola vez) las columnas que usa la importación. Seguro de repetir. */
function horariosAsegurarColumnasFestivos($pdo) {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try { $pdo->exec("ALTER TABLE festivos ADD COLUMN origen VARCHAR(10) NOT NULL DEFAULT 'manual'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE horarios_config ADD COLUMN region_festivos VARCHAR(10) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE horarios_config ADD COLUMN festivos_ultimo_intento DATETIME NULL"); } catch (Exception $e) {}
}

/** Región configurada: null = nunca configurada, '' = solo nacionales, 'ES-MC' = Murcia... */
function horariosRegionFestivos($pdo) {
    horariosAsegurarColumnasFestivos($pdo);
    try {
        $r = $pdo->query("SELECT region_festivos FROM horarios_config WHERE id = 1")->fetchColumn();
        return $r === false ? null : $r;
    } catch (Exception $e) { return null; }
}

/** Descarga los festivos de un año para España. Lanza Exception si falla. */
function horariosDescargarFestivos($ano) {
    $url = 'https://date.nager.at/api/v3/PublicHolidays/' . (int)$ano . '/ES';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'OficiaClock/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        throw new Exception('No se pudo descargar el calendario de festivos (' . ($err ?: 'HTTP ' . $code) . ')');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) throw new Exception('Respuesta no válida del servicio de festivos');
    return $data;
}

/**
 * Importa nacionales + los de la región. Nunca toca los festivos añadidos a mano
 * (si ya hay uno manual ese día, se respeta).
 * @return array ['nuevos' => n, 'actualizados' => n, 'total' => n]
 */
function horariosImportarFestivos($pdo, $ano, $region) {
    horariosAsegurarColumnasFestivos($pdo);
    $lista = horariosDescargarFestivos($ano);
    $sql = "INSERT INTO festivos (fecha, nombre, origen) VALUES (?, ?, 'auto')
            ON DUPLICATE KEY UPDATE nombre = IF(origen = 'auto', VALUES(nombre), nombre)";
    $ins = $pdo->prepare($sql);
    $res = ['nuevos' => 0, 'actualizados' => 0, 'total' => 0];
    foreach ($lista as $h) {
        $counties = $h['counties'] ?? null;
        $aplica   = ($counties === null) || ($region && in_array($region, (array)$counties, true));
        if (!$aplica || empty($h['date'])) continue;
        $res['total']++;
        $ins->execute([$h['date'], mb_substr($h['localName'] ?? $h['name'] ?? 'Festivo', 0, 120)]);
        $n = $ins->rowCount();          // 1 = insertado, 2 = actualizado, 0 = sin cambios
        if ($n === 1) $res['nuevos']++;
        elseif ($n === 2) $res['actualizados']++;
    }
    return $res;
}

/**
 * Importación automática "silenciosa": solo si hay región configurada, ese año aún no tiene
 * festivos importados y no se ha intentado en la última hora (para no frenar la web si el
 * servicio está caído: festivos_ultimo_intento guarda el último FALLO). Nunca lanza errores.
 */
function horariosAutoImportarFestivos($pdo, array $anos) {
    try {
        $region = horariosRegionFestivos($pdo);
        if ($region === null) return;                       // nunca configurado → no hacer nada
        $ult = $pdo->query("SELECT festivos_ultimo_intento FROM horarios_config WHERE id = 1")->fetchColumn();
        if ($ult && strtotime($ult) > time() - 3600) return;
        $chk = $pdo->prepare("SELECT COUNT(*) FROM festivos WHERE origen = 'auto' AND YEAR(fecha) = ?");
        foreach (array_unique(array_map('intval', $anos)) as $ano) {
            if ($ano < 2000 || $ano > (int)date('Y') + 2) continue;
            $chk->execute([$ano]);
            if ((int)$chk->fetchColumn() > 0) continue;
            try { horariosImportarFestivos($pdo, $ano, $region); }
            catch (Exception $e) {
                error_log('[horarios] auto-importar festivos ' . $ano . ': ' . $e->getMessage());
                try { $pdo->exec("UPDATE horarios_config SET festivos_ultimo_intento = NOW() WHERE id = 1"); } catch (Exception $e2) {}
                return;
            }
        }
    } catch (Exception $e) {
        error_log('[horarios] auto-importar festivos: ' . $e->getMessage());
    }
}

} // fin if !function_exists