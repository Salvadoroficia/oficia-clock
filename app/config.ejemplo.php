<?php
// config.ejemplo.php → cópialo como config.php en cada cliente y rellénalo.
// config.php NO se sube al repositorio (está en .gitignore).

date_default_timezone_set('Europe/Madrid');

define('DB_HOST', 'localhost');
define('DB_NAME', 'nombre_bd_del_cliente');
define('DB_USER', 'usuario_bd');
define('DB_PASS', 'contraseña_bd');

define('JIBBLE_API_ID', '');
define('JIBBLE_API_SECRET', '');

define('SESSION_DURATION', 28800); // 8 horas

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            die(json_encode(['error' => 'DB connection failed']));
        }
    }
    return $pdo;
}
