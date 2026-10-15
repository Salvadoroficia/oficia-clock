<?php
header('Content-Type: application/json');
ob_start();

// === PON AQUÍ TUS CREDENCIALES NUEVAS (las que creaste al regenerar el secreto) ===
require_once dirname(__DIR__) . '/app/config.php';
$client_id = JIBBLE_API_ID;
$client_secret = JIBBLE_API_SECRET;
// ========================================================================

// Obtener token
$token_url = 'https://identity.prod.jibble.io/connect/token';
$post_data = http_build_query([
    'grant_type' => 'client_credentials',
    'client_id' => $client_id,
    'client_secret' => $client_secret
]);

$ch_token = curl_init($token_url);
curl_setopt($ch_token, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_token, CURLOPT_POST, true);
curl_setopt($ch_token, CURLOPT_POSTFIELDS, $post_data);
curl_setopt($ch_token, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded'
]);

$token_response = curl_exec($ch_token);
$token_httpCode = curl_getinfo($ch_token, CURLINFO_HTTP_CODE);
curl_close($ch_token);

if ($token_httpCode !== 200) {
    ob_clean();
    echo json_encode(['error' => 'Fallo token', 'code' => $token_httpCode]);
    exit;
}

$token_json = json_decode($token_response, true);
$access_token = $token_json['access_token'] ?? null;

if (!$access_token) {
    ob_clean();
    echo json_encode(['error' => 'No token']);
    exit;
}

// Listar personas
$people_url = 'https://workspace.prod.jibble.io/v1/People';

$ch = curl_init($people_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

ob_clean();

if ($httpCode == 200) {
    echo $response;  // Aquí verás tu usuario y su personId
} else {
    echo json_encode(['error' => 'Fallo al listar', 'code' => $httpCode, 'details' => $response]);
}
?>