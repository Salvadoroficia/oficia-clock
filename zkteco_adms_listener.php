<?php
/**
 * ZKteco ADMS Listener — Oficia Clock
 * Protocolo: Attendance PUSH Communication Protocol v4.8
 */

define('API_ENDPOINT',  'https://fichajes.oficiasistemas.es/api.php');
define('LOG_FILE',      __DIR__ . '/logs/zkteco_events.log');
define('ERROR_LOG',     __DIR__ . '/logs/zkteco_errors.log');
define('MAX_LOG_SIZE',  10 * 1024 * 1024);
define('PUSH_COMM_KEY', '');

function ensureLogDirectory() {
    $dir = __DIR__ . '/logs';
    if (!file_exists($dir)) mkdir($dir, 0755, true);
}

function writeLog($file, $msg) {
    if (file_exists($file) && filesize($file) > 15 * 1024 * 1024) {
        rename($file, $file . '.' . date('Ymd-His') . '.bak');
    }
    file_put_contents($file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function respondText($body, $code = 200) {
    http_response_code($code);
    header('Content-Type: text/plain');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

function buildConfig($sn) {
    $config  = "GET OPTION FROM: $sn\n";
    $config .= "ATTLOGStamp=None\n";
    $config .= "OPERLOGStamp=9999\n";
    $config .= "ATTPHOTOStamp=None\n";
    $config .= "ErrorDelay=30\n";
    $config .= "Delay=10\n";
    $config .= "TransTimes=00:00;14:00\n";
    $config .= "TransInterval=1\n";
    $config .= "TransFlag=TransData AttLog\n";
    $config .= "TimeZone=1\n";
    $config .= "Realtime=1\n";
    $config .= "Encrypt=None\n";
    $config .= "ServerVer=2.4.1\n";
    $config .= "PushProtVer=2.4.1\n";
    $config .= "PushOptionsFlag=0\n";
    $config .= "SupportPing=1\n";
    return $config;
}

function sendCheckinToAPI($deviceSerial, $pin, $eventTime) {
    $payload = json_encode([
        'device_serial' => $deviceSerial,
        'rfid_card_id'  => $pin,
        'event_time'    => $eventTime,
    ]);

    $ch = curl_init(API_ENDPOINT . '?action=rfid_checkin');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Forwarded-From: ZKteco-ADMS'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        writeLog(ERROR_LOG, "CURL error enviando a API: $curlError");
        return false;
    }

    writeLog(LOG_FILE, "API response ($httpCode): $response");
    return $httpCode >= 200 && $httpCode < 300;
}

// ── INICIO ────────────────────────────────────────────────────
ensureLogDirectory();
date_default_timezone_set('Europe/Madrid');

$method   = $_SERVER['REQUEST_METHOD'];
$uri      = $_SERVER['REQUEST_URI'] ?? '';
$sn       = $_GET['SN'] ?? $_GET['sn'] ?? '';
$table    = $_GET['table'] ?? '';
$rawInput = file_get_contents('php://input');

writeLog(LOG_FILE, "$method $uri | SN=$sn | table=$table | body=" . substr($rawInput, 0, 500));

// ── 1. HANDSHAKE ──────────────────────────────────────────────
if ($method === 'GET' && strpos($uri, '/iclock/cdata') !== false && isset($_GET['options'])) {
    writeLog(LOG_FILE, "HANDSHAKE de terminal SN=$sn");
    $config  = buildConfig($sn);
    $gmtDate = gmdate('D, d M Y H:i:s') . ' GMT';
    http_response_code(200);
    header('Content-Type: text/plain');
    header('Content-Length: ' . strlen($config));
    header('Date: ' . $gmtDate);
    echo $config;
    exit;
}

// ── 2. REGISTRO DEL DISPOSITIVO (Push 3.x) ───────────────────
if ($method === 'POST' && strpos($uri, '/iclock/registry') !== false) {
    $deviceData = [];
    foreach (explode(',', $rawInput) as $pair) {
        $pair = ltrim($pair, '~');
        if (strpos($pair, '=') !== false) {
            [$k, $v] = explode('=', $pair, 2);
            $deviceData[trim($k)] = trim($v);
        }
    }
    $deviceName = $deviceData['DeviceName'] ?? 'Unknown';
    $firmVer    = $deviceData['FirmVer']    ?? 'Unknown';
    writeLog(LOG_FILE, "Dispositivo registrado: $deviceName, FW=$firmVer, SN=$sn");
    writeLog(LOG_FILE, "REGISTRY body completo: " . $rawInput);

    // No responder nada — el dispositivo debería interpretar timeout como fallo
    // del Auto Server y caer al protocolo iClock estándar
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: text/plain');
    header('Content-Length: 0');
    header('Connection: close');
    flush();
    exit;
}

// ── 3. FICHAJES DE ASISTENCIA ─────────────────────────────────
if ($method === 'POST' && strpos($uri, '/iclock/cdata') !== false && strtoupper($table) === 'ATTLOG') {
    writeLog(LOG_FILE, "ATTLOG recibido de SN=$sn");

    if (empty($rawInput)) {
        writeLog(ERROR_LOG, "ATTLOG vacío de SN=$sn");
        respondText("OK: 0");
    }

    $lines     = explode("\n", trim($rawInput));
    $processed = 0;
    $errors    = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $fields = preg_split('/\t+/', $line);
        if (count($fields) < 2) {
            writeLog(ERROR_LOG, "Línea con formato inválido: $line");
            $errors++;
            continue;
        }

        $pin       = trim($fields[0]);
        $eventTime = trim($fields[1]);
        $eventTime = preg_replace('/\s+/', ' ', $eventTime);

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $eventTime)) {
            writeLog(ERROR_LOG, "Fecha inválida '$eventTime' en línea: $line");
            $errors++;
            continue;
        }

        writeLog(LOG_FILE, "Procesando fichaje: pin=$pin, time=$eventTime, sn=$sn");

        $ok = sendCheckinToAPI($sn, $pin, $eventTime);
        if ($ok) { $processed++; } else { $errors++; }
    }

    writeLog(LOG_FILE, "ATTLOG procesado: $processed ok, $errors errores");
    respondText("OK: $processed");
}

// ── 4. CONFIGURACIÓN DEL TERMINAL (table=options) ────────────
if ($method === 'POST' && strpos($uri, '/iclock/cdata') !== false && strtolower($table) === 'options') {
    writeLog(LOG_FILE, "OPTIONS recibido de SN=$sn (ignorado)");
    respondText("OK");
}

// ── 5. HEARTBEAT / PING ───────────────────────────────────────
if ($method === 'GET' && strpos($uri, '/iclock/ping') !== false) {
    writeLog(LOG_FILE, "PING de SN=$sn");
    respondText("OK");
}

// ── 6. COMANDOS PENDIENTES ────────────────────────────────────
if ($method === 'GET' && strpos($uri, '/iclock/getrequest') !== false) {
    writeLog(LOG_FILE, "GETREQUEST de SN=$sn — consultando comandos pendientes");

    // Pasar SN en la URL (GET) para evitar conflicto con php://input del router
    $ch = curl_init(API_ENDPOINT . '?action=get_zkteco_commands&sn=' . urlencode($sn));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    writeLog(LOG_FILE, "GETREQUEST curl response ($httpCode): " . substr($response ?: $curlError, 0, 200));

    $cmdData = $response ? json_decode($response, true) : [];

    if (!empty($cmdData['command'])) {
        $cmdId  = time();
        $cmdStr = "C:$cmdId:" . $cmdData['command'] . "\n";
        writeLog(LOG_FILE, "GETREQUEST enviando comando (id=" . $cmdData['id'] . "): " . substr(str_replace("\t","[TAB]",$cmdStr), 0, 150));
        http_response_code(200);
        header('Content-Type: text/plain');
        header('Content-Length: ' . strlen($cmdStr));
        echo $cmdStr;
        exit;
    } else {
        writeLog(LOG_FILE, "GETREQUEST sin comandos pendientes");
        respondText("OK");
    }
}
// ── 7. RESPUESTA A COMANDOS EJECUTADOS ───────────────────────
if ($method === 'POST' && strpos($uri, '/iclock/devicecmd') !== false) {
    writeLog(LOG_FILE, "DEVICECMD respuesta de SN=$sn | " . $rawInput);
    respondText("OK");
}

// ── FALLBACK ──────────────────────────────────────────────────
writeLog(ERROR_LOG, "Ruta no reconocida: $method $uri");
respondText("OK");