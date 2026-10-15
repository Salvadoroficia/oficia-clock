<?php
require_once __DIR__ . '/config.php';

class JibbleAPI {
    private $apiId;
    private $apiSecret;
    private $accessToken;
    
    public function __construct() {
        $this->apiId = JIBBLE_API_ID;
        $this->apiSecret = JIBBLE_API_SECRET;
    }
    
    private function getAccessToken() {
        if ($this->accessToken) return $this->accessToken;
        
        $ch = curl_init('https://identity.prod.jibble.io/connect/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 
            'grant_type=client_credentials&client_id='.$this->apiId.
            '&client_secret='.$this->apiSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to obtain OAuth token");
        }
        
        $json = json_decode($response, true);
        $this->accessToken = $json['access_token'];
        return $this->accessToken;
    }
    
    private function request($url, $method = 'GET', $body = null) {
        $token = $this->getAccessToken();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        $headers = ['Authorization: Bearer '.$token, 'Accept: application/json'];
        if ($body) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("API Error: $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    public function getPeople() {
        return $this->request('https://workspace.prod.jibble.io/v1/People');
    }
    
    public function clockIn($personId, $note = null) {
        $body = [
            'personId' => $personId,
            'clientType' => 'Web',
            'platform' => ['clientVersion' => '1.0', 'os' => 'Web', 'deviceModel' => 'Kiosk']
        ];
        if ($note) $body['note'] = $note;
        return $this->request('https://time-tracking.prod.jibble.io/v1/TimeEntries', 'POST', $body);
    }
    
    public function clockOut($personId) {
        return $this->request('https://time-tracking.prod.jibble.io/v1/TimeEntries', 'POST', [
            'personId' => $personId,
            'type' => 'Out',
            'clientType' => 'Web',
            'platform' => ['clientVersion' => '1.0', 'os' => 'Web', 'deviceModel' => 'Kiosk']
        ]);
    }
    
    public function getStatus($personId) {
        try {
            $url = 'https://time-tracking.prod.jibble.io/v1/TimeEntries?$filter=personId%20eq%20'.$personId.'&$orderby=time%20desc&$top=10';
            $entries = $this->request($url, 'GET');
            
            if (empty($entries['value'])) {
                return ['status' => 'out', 'lastEntry' => null];
            }
            
            $lastEntry = $entries['value'][0];
            $type = $lastEntry['type'] ?? 'Out';
            
            return [
                'status' => ($type === 'In') ? 'in' : 'out',
                'lastEntry' => $lastEntry
            ];
        } catch (Exception $e) {
            return ['status' => 'out', 'lastEntry' => null];
        }
    }
	
/**
 * Obtener time entries de una persona por fecha
 */
public function getTimeEntriesByDate($personId, $fecha) {
    try {
        // Usar el mismo formato que getStatus() que sabemos que funciona
        $url = 'https://time-tracking.prod.jibble.io/v1/TimeEntries?$filter=personId%20eq%20'.$personId.'&$orderby=time%20desc&$top=100';
        
        $result = $this->request($url, 'GET');
        
        if (empty($result['value'])) {
            return [];
        }
        
        // Filtrar por fecha manualmente
        $entries = [];
        foreach ($result['value'] as $entry) {
            $entryDate = date('Y-m-d', strtotime($entry['time'] ?? ''));
            if ($entryDate === $fecha) {
                $entries[] = $entry;
            }
        }
        
        return $entries;
        
    } catch (Exception $e) {
        error_log("Error obteniendo time entries de Jibble: " . $e->getMessage());
        return [];
    }
}

/**
 * Eliminar un time entry de Jibble
 */
public function deleteTimeEntry($entryId) {
    try {
        $url = "https://time-tracking.prod.jibble.io/v1/TimeEntries/$entryId";
        
        $token = $this->getAccessToken();
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 200, 204 o 404 (ya borrado) = éxito
        return ($httpCode === 200 || $httpCode === 204 || $httpCode === 404);
        
    } catch (Exception $e) {
        error_log("Error borrando time entry de Jibble: " . $e->getMessage());
        return false;
    }
}
}
?>