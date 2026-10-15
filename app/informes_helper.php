<?php
/* ============================================================
   Oficia Clock · Informe mensual de registro de jornada con firma

   - El empleado revisa su mes (entradas, salidas, descansos, horas,
     ausencias y correcciones hechas por la empresa) y lo firma:
     "Conforme" o "Con observaciones", con firma dibujada opcional.
   - Se guarda una huella SHA-256 de los datos firmados. Si después se
     modifica algún fichaje de ese mes, la firma queda "desactualizada"
     y el empleado debe volver a firmar.
   - PDF generado en el servidor con FPDF (lib/fpdf/).
   ============================================================ */

define('INF_FPDF', __DIR__ . '/lib/fpdf/fpdf.php');

/* ---------------------------------------------------------------- tablas */
function informesAsegurarTablas($pdo) {
    static $hecho = false;
    if ($hecho) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS informes_firmas (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id   INT NOT NULL,
        periodo      CHAR(7) NOT NULL COMMENT 'YYYY-MM',
        hash_datos   CHAR(64) NOT NULL COMMENT 'SHA-256 de los datos del mes firmados',
        hash_firma   CHAR(64) NOT NULL COMMENT 'SHA-256 de datos + firma + fecha + IP',
        datos        LONGTEXT NOT NULL COMMENT 'Copia exacta de lo que vio y firmó',
        tipo         VARCHAR(15) NOT NULL COMMENT 'conforme | observaciones',
        observaciones TEXT NULL,
        firma_img    MEDIUMTEXT NULL COMMENT 'data:image/jpeg;base64,...',
        firmado_en   DATETIME NOT NULL,
        ip           VARCHAR(45) NULL,
        user_agent   VARCHAR(255) NULL,
        KEY idx_usuario_periodo (usuario_id, periodo),
        KEY idx_periodo (periodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS informes_config (
        id       TINYINT PRIMARY KEY,
        empresa  VARCHAR(150) NULL,
        cif      VARCHAR(20)  NULL,
        centro   VARCHAR(200) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO informes_config (id) VALUES (1)");
    $hecho = true;
}

function informesConfig($pdo) {
    informesAsegurarTablas($pdo);
    $c = $pdo->query("SELECT empresa, cif, centro FROM informes_config WHERE id = 1")->fetch() ?: [];
    return ['empresa' => $c['empresa'] ?? '', 'cif' => $c['cif'] ?? '', 'centro' => $c['centro'] ?? ''];
}

/* ---------------------------------------------------------------- datos del mes */
function informesPeriodoValido($p) {
    return is_string($p) && preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $p);
}
function informesMesCerrado($p) { return $p < date('Y-m'); }

function informesDatos($pdo, $uid, $periodo) {
    $st = $pdo->prepare("SELECT id, nombre, email FROM usuarios WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) throw new Exception('Empleado no encontrado');

    $ini = $periodo . '-01';
    $fin = date('Y-m-d', strtotime("$ini +1 month"));

    // Fichajes del mes (sin descansos), emparejados entrada → salida
    $st = $pdo->prepare("
        SELECT id, tipo, fecha_hora FROM fichajes
        WHERE usuario_id = ? AND es_descanso = 0 AND fecha_hora >= ? AND fecha_hora < ?
        ORDER BY fecha_hora, id");
    $st->execute([$uid, $ini, $fin]);
    $dias = [];
    $abierto = null;
    $add = function ($e, $s) use (&$dias) {
        $fecha = substr($e ?: $s, 0, 10);
        $min = ($e && $s) ? max(0, (int)round((strtotime($s) - strtotime($e)) / 60)) : 0;
        if (!isset($dias[$fecha])) $dias[$fecha] = ['fecha' => $fecha, 'tramos' => [], 'descanso_min' => 0, 'trabajado_min' => 0, 'neto_min' => 0, 'incidencia' => false];
        $dias[$fecha]['tramos'][] = ['entrada' => $e ? substr($e, 11, 5) : null, 'salida' => $s ? (substr($s, 0, 10) !== $fecha ? substr($s, 8, 2) . '/' . substr($s, 5, 2) . ' ' : '') . substr($s, 11, 5) : null, 'min' => $min];
        $dias[$fecha]['trabajado_min'] += $min;
        if (!$e || !$s) $dias[$fecha]['incidencia'] = true;
    };
    foreach ($st->fetchAll() as $f) {
        if ($f['tipo'] === 'In') {
            if ($abierto) $add($abierto, null);           // entrada sin salida
            $abierto = $f['fecha_hora'];
        } elseif ($f['tipo'] === 'Out') {
            if ($abierto) { $add($abierto, $f['fecha_hora']); $abierto = null; }
            else $add(null, $f['fecha_hora']);              // salida sin entrada
        }
    }
    if ($abierto) {
        // Si la salida cae ya en el mes siguiente (turno de noche del último día), buscarla
        $st = $pdo->prepare("SELECT fecha_hora FROM fichajes WHERE usuario_id = ? AND es_descanso = 0 AND tipo = 'Out' AND fecha_hora >= ? AND fecha_hora < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY fecha_hora LIMIT 1");
        $st->execute([$uid, $fin, $fin]);
        $add($abierto, $st->fetchColumn() ?: null);
    }

    // Descansos
    try {
        $st = $pdo->prepare("
            SELECT DATE(inicio) AS fecha, SUM(TIMESTAMPDIFF(MINUTE, inicio, fin)) AS m
            FROM breaks WHERE usuario_id = ? AND fin IS NOT NULL AND inicio >= ? AND inicio < ?
            GROUP BY DATE(inicio)");
        $st->execute([$uid, $ini, $fin]);
        foreach ($st->fetchAll() as $b) {
            if (!isset($dias[$b['fecha']])) continue;
            $dias[$b['fecha']]['descanso_min'] = (int)$b['m'];
        }
    } catch (Exception $e) {}

    ksort($dias);
    $tot = ['dias' => 0, 'trabajado_min' => 0, 'descanso_min' => 0, 'neto_min' => 0, 'incidencias' => 0];
    foreach ($dias as &$d) {
        $d['neto_min'] = max(0, $d['trabajado_min'] - $d['descanso_min']);
        $tot['dias']++;
        $tot['trabajado_min'] += $d['trabajado_min'];
        $tot['descanso_min'] += $d['descanso_min'];
        $tot['neto_min'] += $d['neto_min'];
        if ($d['incidencia']) $tot['incidencias']++;
    }
    unset($d);

    // Ausencias que tocan el mes
    $ausencias = [];
    try {
        $st = $pdo->prepare("
            SELECT tipo, fecha_inicio, fecha_fin FROM ausencias
            WHERE usuario_id = ? AND estado IN ('activa','finalizada')
              AND fecha_inicio < ? AND (fecha_fin IS NULL OR fecha_fin >= ?)
            ORDER BY fecha_inicio");
        $st->execute([$uid, $fin, $ini]);
        foreach ($st->fetchAll() as $a) {
            $ausencias[] = ['tipo' => $a['tipo'], 'desde' => max($a['fecha_inicio'], $ini),
                            'hasta' => $a['fecha_fin'] ? min($a['fecha_fin'], date('Y-m-d', strtotime("$fin -1 day"))) : null];
        }
    } catch (Exception $e) {}

    // Correcciones hechas por la empresa sobre fichajes de este mes (historial de cambios)
    $cambios = [];
    try {
        if (function_exists('auditoriaInstalada') && auditoriaInstalada($pdo)) {
            $st = $pdo->prepare("
                SELECT accion, antes, despues, motivo, creado, actor FROM fichajes_historial h
                WHERE usuario_id = ? AND " . audCondCambios() . " AND creado >= ?
                ORDER BY id");
            $st->execute([$uid, $ini]);
            foreach ($st->fetchAll() as $h) {
                $a = $h['antes'] ? json_decode($h['antes'], true) : null;
                $dd = $h['despues'] ? json_decode($h['despues'], true) : null;
                $fh = ($dd['fecha_hora'] ?? null) ?: ($a['fecha_hora'] ?? '');
                $fa = $a['fecha_hora'] ?? '';
                if (!(($fh >= $ini && $fh < $fin) || ($fa >= $ini && $fa < $fin))) continue;
                $cambios[] = [
                    'cuando' => substr($h['creado'], 0, 16),
                    'accion' => $h['accion'],
                    'tipo'   => ($dd['tipo'] ?? $a['tipo'] ?? ''),
                    'antes'  => $a ? substr($a['fecha_hora'], 0, 16) : null,
                    'despues'=> $dd ? substr($dd['fecha_hora'], 0, 16) : null,
                    'motivo' => $h['motivo'],
                    'por'    => strpos($h['actor'], 'admin:') === 0 ? 'Empresa' : (strpos($h['actor'], 'bd:') === 0 ? 'Base de datos' : 'Sistema'),
                ];
            }
        }
    } catch (Exception $e) {}

    $datos = [
        'version'   => 1,
        'periodo'   => $periodo,
        'usuario'   => ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'email' => $u['email']],
        'dias'      => array_values($dias),
        'totales'   => $tot,
        'ausencias' => $ausencias,
        'cambios'   => $cambios,
    ];
    $datos['hash'] = informesHash($datos);
    return $datos;
}

// Huella de lo que se firma: el registro del mes (no el nombre de la empresa ni otros datos que cambian solos)
function informesHash($d) {
    $base = ['periodo' => $d['periodo'], 'usuario_id' => $d['usuario']['id'], 'dias' => $d['dias'], 'ausencias' => $d['ausencias'], 'totales' => $d['totales']];
    return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function informesUltimaFirma($pdo, $uid, $periodo) {
    $st = $pdo->prepare("SELECT * FROM informes_firmas WHERE usuario_id = ? AND periodo = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $periodo]);
    return $st->fetch() ?: null;
}

function informesEstado($firma, $hashActual) {
    if (!$firma) return 'pendiente';
    return hash_equals($firma['hash_datos'], $hashActual) ? 'firmado' : 'desactualizado';
}

function informesFirmaPublica($f) {
    if (!$f) return null;
    return [
        'id' => (int)$f['id'], 'tipo' => $f['tipo'], 'observaciones' => $f['observaciones'],
        'firmado_en' => $f['firmado_en'], 'ip' => $f['ip'], 'con_firma' => !empty($f['firma_img']),
        'codigo' => strtoupper(substr($f['hash_firma'], 0, 16)),
    ];
}

/* ---------------------------------------------------------------- dispatcher
   En api.php, antes del switch:
     if (strpos($action, 'informe_') === 0) { informesDispatch($action, $data); exit; } */
function informesDispatch($action, $data) {
    $data = array_merge($_GET, is_array($data) ? $data : []);
    try {
        $pdo = getDBConnection();
        informesAsegurarTablas($pdo);
        switch ($action) {
            case 'informe_mis_pendientes': infMisPendientes($pdo); break;
            case 'informe_mio':            infMio($pdo, $data); break;
            case 'informe_firmar':         infFirmar($pdo, $data); break;
            case 'informe_pdf':            infPdf($pdo, $data); break;
            case 'informe_resumen':        requireAdmin(); infResumen($pdo, $data); break;
            case 'informe_zip':            requireAdmin(); infZip($pdo, $data); break;
            case 'informe_config':         requireAdmin(); echo json_encode(['success' => true, 'config' => informesConfig($pdo)]); break;
            case 'informe_config_guardar': requireAdmin(); infConfigGuardar($pdo, $data); break;
            default:
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Action not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function infUsuarioSesion() {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return null;
    }
    return (int)$_SESSION['user_id'];
}

// Meses cerrados (últimos 12) con fichajes, y su estado de firma
function infMisPendientes($pdo) {
    $uid = infUsuarioSesion();
    if (!$uid) return;
    $st = $pdo->prepare("
        SELECT DISTINCT DATE_FORMAT(fecha_hora, '%Y-%m') AS p FROM fichajes
        WHERE usuario_id = ? AND es_descanso = 0
          AND fecha_hora >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 12 MONTH)
          AND fecha_hora < DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ORDER BY p DESC");
    $st->execute([$uid]);
    $meses = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $d = informesDatos($pdo, $uid, $p);
        $f = informesUltimaFirma($pdo, $uid, $p);
        $meses[] = ['periodo' => $p, 'estado' => informesEstado($f, $d['hash']), 'neto_min' => $d['totales']['neto_min'], 'firma' => informesFirmaPublica($f)];
    }
    $pend = array_values(array_filter($meses, function ($m) { return $m['estado'] !== 'firmado'; }));
    echo json_encode(['success' => true, 'meses' => $meses, 'pendientes' => count($pend)]);
}

function infMio($pdo, $data) {
    $uid = infUsuarioSesion();
    if (!$uid) return;
    $p = $data['periodo'] ?? '';
    if (!informesPeriodoValido($p)) { echo json_encode(['success' => false, 'error' => 'Periodo no válido']); return; }
    $d = informesDatos($pdo, $uid, $p);
    $f = informesUltimaFirma($pdo, $uid, $p);
    echo json_encode(['success' => true, 'datos' => $d, 'estado' => informesEstado($f, $d['hash']),
                      'firma' => informesFirmaPublica($f), 'cerrado' => informesMesCerrado($p), 'empresa' => informesConfig($pdo)]);
}

function infFirmar($pdo, $data) {
    $uid = infUsuarioSesion();
    if (!$uid) return;
    $p = $data['periodo'] ?? '';
    if (!informesPeriodoValido($p)) { echo json_encode(['success' => false, 'error' => 'Periodo no válido']); return; }
    if (!informesMesCerrado($p)) { echo json_encode(['success' => false, 'error' => 'El mes aún no ha terminado: podrás firmarlo a partir del día 1.']); return; }

    $d = informesDatos($pdo, $uid, $p);
    // Lo que firma tiene que ser exactamente lo que estaba viendo
    if (($data['hash'] ?? '') !== $d['hash']) {
        echo json_encode(['success' => false, 'error' => 'Tus fichajes de ese mes han cambiado mientras lo revisabas. Vuelve a abrir el informe.', 'recargar' => true]);
        return;
    }
    $tipo = ($data['tipo'] ?? '') === 'observaciones' ? 'observaciones' : 'conforme';
    $obs = trim((string)($data['observaciones'] ?? ''));
    if ($tipo === 'observaciones' && mb_strlen($obs) < 5) { echo json_encode(['success' => false, 'error' => 'Escribe tus observaciones (qué no está bien).']); return; }
    if ($tipo === 'conforme') $obs = '';

    $img = (string)($data['firma_img'] ?? '');
    if ($img !== '') {
        if (!preg_match('#^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$#', $img) || strlen($img) > 600000) {
            echo json_encode(['success' => false, 'error' => 'La imagen de la firma no es válida']);
            return;
        }
    }

    $ahora = date('Y-m-d H:i:s');
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $hashFirma = hash('sha256', implode('|', [$d['hash'], $uid, $p, $tipo, $obs, $img !== '' ? hash('sha256', $img) : '', $ahora, $ip]));

    $st = $pdo->prepare("INSERT INTO informes_firmas (usuario_id, periodo, hash_datos, hash_firma, datos, tipo, observaciones, firma_img, firmado_en, ip, user_agent)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$uid, $p, $d['hash'], $hashFirma, json_encode($d, JSON_UNESCAPED_UNICODE), $tipo, $obs ?: null, $img ?: null, $ahora, $ip, $ua]);

    echo json_encode(['success' => true, 'message' => $tipo === 'conforme' ? 'Informe firmado. ¡Gracias!' : 'Informe firmado con tus observaciones. La empresa las verá.',
                      'codigo' => strtoupper(substr($hashFirma, 0, 16))]);
}

function infResumen($pdo, $data) {
    $p = $data['periodo'] ?? date('Y-m', strtotime('first day of last month'));
    if (!informesPeriodoValido($p)) { echo json_encode(['success' => false, 'error' => 'Periodo no válido']); return; }
    $us = $pdo->query("SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll();
    $filas = [];
    $cuenta = ['firmado' => 0, 'pendiente' => 0, 'desactualizado' => 0, 'observaciones' => 0, 'sin_fichajes' => 0];
    foreach ($us as $u) {
        $d = informesDatos($pdo, (int)$u['id'], $p);
        $f = informesUltimaFirma($pdo, (int)$u['id'], $p);
        $estado = $d['totales']['dias'] || $f ? informesEstado($f, $d['hash']) : 'sin_fichajes';
        $cuenta[$estado]++;
        if ($estado === 'firmado' && $f['tipo'] === 'observaciones') $cuenta['observaciones']++;
        $filas[] = ['usuario_id' => (int)$u['id'], 'nombre' => $u['nombre'], 'estado' => $estado,
                    'dias' => $d['totales']['dias'], 'neto_min' => $d['totales']['neto_min'], 'incidencias' => $d['totales']['incidencias'],
                    'cambios' => count($d['cambios']), 'firma' => informesFirmaPublica($f)];
    }
    echo json_encode(['success' => true, 'periodo' => $p, 'cerrado' => informesMesCerrado($p), 'filas' => $filas, 'cuenta' => $cuenta, 'config' => informesConfig($pdo)]);
}

function infConfigGuardar($pdo, $data) {
    $st = $pdo->prepare("UPDATE informes_config SET empresa = ?, cif = ?, centro = ? WHERE id = 1");
    $st->execute([mb_substr(trim($data['empresa'] ?? ''), 0, 150), mb_substr(strtoupper(trim($data['cif'] ?? '')), 0, 20), mb_substr(trim($data['centro'] ?? ''), 0, 200)]);
    echo json_encode(['success' => true, 'message' => 'Datos de la empresa guardados']);
}

/* ---------------------------------------------------------------- PDF */
function infPdf($pdo, $data) {
    $p = $data['periodo'] ?? '';
    $esAdmin = !empty($_SESSION['admin_logged_in']);
    $uid = $esAdmin && !empty($data['usuario_id']) ? (int)$data['usuario_id'] : (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'No autenticado']); return; }
    if (!informesPeriodoValido($p)) { echo json_encode(['success' => false, 'error' => 'Periodo no válido']); return; }
    $pdf = informesGenerarPdf($pdo, $uid, $p);
    $nombre = informesNombreArchivo($pdo, $uid, $p);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($data['descargar']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
}

function infZip($pdo, $data) {
    $p = $data['periodo'] ?? '';
    if (!informesPeriodoValido($p)) { echo json_encode(['success' => false, 'error' => 'Periodo no válido']); return; }
    if (!class_exists('ZipArchive')) { echo json_encode(['success' => false, 'error' => 'El servidor no tiene la extensión ZIP de PHP']); return; }
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'infzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $us = $pdo->query("SELECT id FROM usuarios WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($us as $uid) {
        $d = informesDatos($pdo, (int)$uid, $p);
        if (!$d['totales']['dias'] && !informesUltimaFirma($pdo, (int)$uid, $p)) continue;
        $zip->addFromString(informesNombreArchivo($pdo, (int)$uid, $p), informesGenerarPdf($pdo, (int)$uid, $p, $d));
        $n++;
    }
    $zip->close();
    if (!$n) { @unlink($tmp); echo json_encode(['success' => false, 'error' => 'No hay fichajes en ese mes']); return; }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="registro_jornada_' . $p . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}

function informesNombreArchivo($pdo, $uid, $p) {
    $st = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    $st->execute([$uid]);
    $n = iconv('UTF-8', 'ASCII//TRANSLIT', (string)$st->fetchColumn());
    $n = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $n), '_') ?: ('empleado_' . $uid);
    return "registro_jornada_{$p}_{$n}.pdf";
}

function informesTxt($s) {
    // FPDF usa Windows-1252: quitar emojis y convertir
    $s = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', (string)$s);
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $r === false ? mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : $r;
}
function informesMin($m) { $m = (int)$m; return sprintf('%d:%02d', intdiv($m, 60), $m % 60); }
function informesMes($p) {
    $m = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $m[(int)substr($p, 5, 2) - 1] . ' de ' . substr($p, 0, 4);
}

function informesGenerarPdf($pdo, $uid, $p, $datos = null) {
    if (!file_exists(INF_FPDF)) throw new Exception('Falta la librería FPDF en lib/fpdf/fpdf.php');
    require_once INF_FPDF;
    if (!class_exists('InformeJornadaPDF')) {
        class InformeJornadaPDF extends FPDF {
            public $pie = '';
            function Footer() {
                $this->SetY(-12);
                $this->SetFont('Helvetica', '', 7);
                $this->SetTextColor(130);
                $this->Cell(0, 5, $this->pie, 0, 0, 'L');
                $this->Cell(0, 5, 'Pag. ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
            }
        }
    }

    $actual = $datos ?: informesDatos($pdo, $uid, $p);
    $firma = informesUltimaFirma($pdo, $uid, $p);
    $estado = informesEstado($firma, $actual['hash']);
    // Si está firmado, el PDF muestra exactamente lo que se firmó
    $d = ($estado === 'firmado') ? json_decode($firma['datos'], true) : $actual;
    $emp = informesConfig($pdo);
    $T = 'informesTxt';
    $dSem = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

    $pdf = new InformeJornadaPDF('P', 'mm', 'A4');
    $pdf->pie = $T('Generado por Oficia Clock el ' . date('d/m/Y H:i') . ' · Conservar 4 años (art. 34.9 ET)');
    $pdf->SetTitle($T('Registro de jornada ' . informesMes($p) . ' - ' . $d['usuario']['nombre']));
    $pdf->SetAuthor('Oficia Clock');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // Cabecera
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->Cell(0, 8, $T('Registro diario de jornada'), 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(90);
    $pdf->Cell(0, 5, $T('Periodo: ' . informesMes($p) . '   ·   Art. 34.9 del Estatuto de los Trabajadores'), 0, 1);
    $pdf->SetTextColor(0);
    $pdf->Ln(3);

    $pdf->SetFillColor(245, 246, 250);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(95, 6, $T(' Empresa'), 'LTR', 0, 'L', true);
    $pdf->Cell(0, 6, $T(' Trabajador/a'), 'LTR', 1, 'L', true);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 5.5, $T(' ' . ($emp['empresa'] ?: '(Configura el nombre de la empresa)')), 'LR');
    $pdf->Cell(0, 5.5, $T(' ' . $d['usuario']['nombre']), 'LR', 1);
    $pdf->Cell(95, 5.5, $T(' CIF: ' . ($emp['cif'] ?: '—')), 'LR');
    $pdf->Cell(0, 5.5, $T(' ' . $d['usuario']['email']), 'LR', 1);
    $pdf->Cell(95, 5.5, $T(' Centro: ' . ($emp['centro'] ?: '—')), 'LBR');
    $pdf->Cell(0, 5.5, $T(' Nº empleado: ' . $d['usuario']['id']), 'LBR', 1);
    $pdf->Ln(4);

    // Tabla de días
    $w = [32, 76, 27, 27, 28];
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(30, 60, 114);
    $pdf->SetTextColor(255);
    foreach (['Fecha', 'Entrada - Salida', 'Trabajado', 'Descansos', 'Neto'] as $i => $h) $pdf->Cell($w[$i], 7, $T($h), 0, 0, $i ? 'C' : 'L', true);
    $pdf->Ln();
    $pdf->SetTextColor(0);
    $pdf->SetFont('Helvetica', '', 9);
    $fill = false;
    if (!$d['dias']) {
        $pdf->Cell(array_sum($w), 8, $T('Sin fichajes en este periodo.'), 'B', 1, 'C');
    }
    foreach ($d['dias'] as $dia) {
        $tramos = array_map(function ($t) { return ($t['entrada'] ?: '¿?') . ' - ' . ($t['salida'] ?: '¿?'); }, $dia['tramos']);
        $lineas = array_chunk($tramos, 2);
        $h = 6 * count($lineas);
        if ($pdf->GetY() + $h > 270) $pdf->AddPage();
        $pdf->SetFillColor(248, 249, 252);
        $ds = $dSem[(int)date('N', strtotime($dia['fecha']))] . ' ' . date('d/m', strtotime($dia['fecha']));
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->Cell($w[0], $h, $T($ds), 'B', 0, 'L', $fill);
        $pdf->SetXY($x + $w[0], $y);
        foreach ($lineas as $k => $l) {
            $pdf->SetX($x + $w[0]);
            if ($dia['incidencia']) $pdf->SetTextColor(185, 28, 28);
            $pdf->Cell($w[1], 6, $T(implode('    ', $l)), $k === count($lineas) - 1 ? 'B' : 0, 2, 'C', $fill);
            $pdf->SetTextColor(0);
        }
        $pdf->SetXY($x + $w[0] + $w[1], $y);
        $pdf->Cell($w[2], $h, informesMin($dia['trabajado_min']), 'B', 0, 'C', $fill);
        $pdf->Cell($w[3], $h, $dia['descanso_min'] ? informesMin($dia['descanso_min']) : '-', 'B', 0, 'C', $fill);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($w[4], $h, informesMin($dia['neto_min']), 'B', 1, 'C', $fill);
        $pdf->SetFont('Helvetica', '', 9);
        $fill = !$fill;
    }
    $t = $d['totales'];
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetFillColor(230, 236, 250);
    $pdf->Cell($w[0] + $w[1], 8, $T(' TOTAL  ·  ' . $t['dias'] . ' días con fichajes'), 0, 0, 'L', true);
    $pdf->Cell($w[2], 8, informesMin($t['trabajado_min']), 0, 0, 'C', true);
    $pdf->Cell($w[3], 8, informesMin($t['descanso_min']), 0, 0, 'C', true);
    $pdf->Cell($w[4], 8, informesMin($t['neto_min']), 0, 1, 'C', true);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(110);
    if ($t['incidencias']) $pdf->Cell(0, 5, $T('En rojo: fichajes sin entrada o sin salida (' . $t['incidencias'] . ' día/s).'), 0, 1);
    $pdf->SetTextColor(0);
    $pdf->Ln(3);

    // Ausencias
    if (!empty($d['ausencias'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, $T('Ausencias'), 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($d['ausencias'] as $a) {
            $pdf->Cell(0, 5, $T('·  ' . ucfirst(str_replace('_', ' ', $a['tipo'])) . ': del ' . date('d/m', strtotime($a['desde'])) . ($a['hasta'] ? ' al ' . date('d/m', strtotime($a['hasta'])) : ' (sin fecha de fin)')), 0, 1);
        }
        $pdf->Ln(2);
    }

    // Correcciones
    if (!empty($d['cambios'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, $T('Correcciones realizadas sobre este registro'), 0, 1);
        $pdf->SetFont('Helvetica', '', 8.5);
        foreach ($d['cambios'] as $c) {
            $acc = ['INSERT' => 'Añadido', 'UPDATE' => 'Modificado', 'DELETE' => 'Eliminado'][$c['accion']] ?? $c['accion'];
            $tipo = $c['tipo'] === 'In' ? 'entrada' : ($c['tipo'] === 'Out' ? 'salida' : 'fichaje');
            $det = $c['accion'] === 'UPDATE' ? date('d/m H:i', strtotime($c['antes'])) . ' -> ' . date('d/m H:i', strtotime($c['despues']))
                 : date('d/m H:i', strtotime($c['despues'] ?: $c['antes']));
            $pdf->MultiCell(0, 4.5, $T('·  ' . date('d/m/Y H:i', strtotime($c['cuando'])) . ' · ' . $acc . ' ' . $tipo . ' ' . $det . ' · ' . $c['por'] . ($c['motivo'] ? ' · Motivo: ' . $c['motivo'] : '')));
        }
        $pdf->Ln(2);
    }

    // Firma
    if ($pdf->GetY() > 225) $pdf->AddPage();
    $pdf->Ln(2);
    $y0 = $pdf->GetY();
    $pdf->SetDrawColor(180);
    $pdf->Rect(10, $y0, 190, 46);
    $pdf->SetXY(13, $y0 + 3);
    $pdf->SetFont('Helvetica', 'B', 10);
    if ($estado === 'firmado') {
        $pdf->Cell(0, 6, $T($firma['tipo'] === 'conforme' ? 'Firmado CONFORME por el/la trabajador/a' : 'Firmado CON OBSERVACIONES por el/la trabajador/a'), 0, 1);
        $pdf->SetX(13);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(110, 4.5, $T('Fecha de firma: ' . date('d/m/Y H:i:s', strtotime($firma['firmado_en'])) . '   ·   IP: ' . $firma['ip']), 0, 1);
        $pdf->SetX(13);
        $pdf->Cell(110, 4.5, $T('Código de firma: ' . strtoupper(substr($firma['hash_firma'], 0, 16))), 0, 1);
        if ($firma['observaciones']) {
            $pdf->SetX(13);
            $pdf->MultiCell(110, 4.5, $T('Observaciones: ' . $firma['observaciones']));
        }
        if (!empty($firma['firma_img']) && preg_match('#^data:image/(jpeg|png);base64,(.+)$#', $firma['firma_img'], $m)) {
            $tmp = tempnam(sys_get_temp_dir(), 'firma') . '.' . ($m[1] === 'png' ? 'png' : 'jpg');
            file_put_contents($tmp, base64_decode($m[2]));
            try { $pdf->Image($tmp, 130, $y0 + 6, 62, 0); } catch (Exception $e) {}
            @unlink($tmp);
        }
    } else {
        $pdf->SetTextColor($estado === 'desactualizado' ? 185 : 120, $estado === 'desactualizado' ? 28 : 120, $estado === 'desactualizado' ? 28 : 120);
        $pdf->Cell(0, 6, $T($estado === 'desactualizado'
            ? 'FIRMA DESACTUALIZADA: los fichajes cambiaron después de firmar (' . date('d/m/Y', strtotime($firma['firmado_en'])) . '). Pendiente de nueva firma.'
            : 'PENDIENTE DE FIRMA del/la trabajador/a'), 0, 1);
        $pdf->SetTextColor(0);
    }
    $pdf->SetXY(13, $y0 + 34);
    $pdf->SetFont('Courier', '', 7);
    $pdf->SetTextColor(100);
    $pdf->Cell(0, 4, $T('Huella SHA-256 del registro: ' . $d['hash']), 0, 1);
    $pdf->SetX(13);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Cell(0, 4, $T('Firma electrónica simple. Cualquier cambio posterior en los fichajes de este mes cambia la huella e invalida la firma.'), 0, 1);
    $pdf->SetTextColor(0);

    return $pdf->Output('S');
}
