<?php
/**
 * WhatsApp API Client
 *
 * Kommuniziert mit dem WhatsApp Node.js Service für das Senden
 * und Empfangen von Nachrichten. Verwendet cURL für HTTP-Requests.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

class WhatsAppApi
{
    /**
     * Basis-URL des WhatsApp-Service
     *
     * @var string
     */
    private $baseUrl;

    /**
     * API-Schlüssel für Authentifizierung (optional)
     *
     * @var string|null
     */
    private $apiKey;

    /**
     * Request-Timeout in Sekunden
     *
     * @var int
     */
    private $timeout = 10;

    /**
     * Konstruktor
     *
     * Initialisiert den API-Client mit der Service-URL und
     * optionalem API-Schlüssel.
     *
     * @param string $baseUrl Service-URL (z.B. http://127.0.0.1:3000)
     * @param string|null $apiKey Optionaler API-Schlüssel für Authentifizierung
     */
    public function __construct($baseUrl, $apiKey = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Sendet eine Textnachricht via WhatsApp
     *
     * Sendet eine Nachricht an die angegebene Telefonnummer.
     * Verwendet den /send-secure Endpoint wenn ein API-Key konfiguriert ist,
     * sonst /send.
     *
     * @param string $phone Telefonnummer mit Ländercode (z.B. 491234567890)
     * @param string $message Nachrichtentext
     * @return array Ergebnis-Array:
     *   - success: bool - True bei Erfolg
     *   - messageId: string - WhatsApp Message-ID (bei Erfolg)
     *   - error: string - Fehlermeldung (bei Fehler)
     *
     * @example
     * $api = new WhatsAppApi('http://127.0.0.1:3000');
     * $result = $api->sendMessage('491234567890', 'Hallo Welt!');
     * if ($result['success']) {
     *     echo 'Gesendet: ' . $result['messageId'];
     * }
     */
    public function sendMessage($phone, $message)
    {
        $endpoint = $this->apiKey ? '/send-secure' : '/send';

        // HTML zu Plain-Text konvertieren fuer WhatsApp
        $message = $this->formatForWhatsApp($message);

        return $this->request('POST', $endpoint, [
            'phone' => $phone,
            'message' => $message,
        ]);
    }

    /**
     * Konvertiert HTML-Text zu WhatsApp-kompatiblem Plain-Text
     *
     * Wandelt HTML-Tags in entsprechende Zeilenumbrueche um und
     * entfernt alle verbleibenden HTML-Elemente.
     *
     * @param string $text Eingabetext (kann HTML enthalten)
     * @return string Bereinigter Text fuer WhatsApp
     */
    private function formatForWhatsApp($text)
    {
        if (empty($text)) {
            return '';
        }

        // 1. Paragraph-Ende (</p>) durch doppelte Zeilenumbrueche ersetzen
        $text = preg_replace('/<\/p>\s*/i', "\n\n", $text);

        // 2. Paragraph-Anfang (<p>) entfernen
        $text = preg_replace('/<p[^>]*>/i', '', $text);

        // 3. <br>-Tags (alle Varianten: <br>, <br/>, <br />) in Zeilenumbrueche
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);

        // 4. <div>-Tags als Zeilenumbrueche behandeln
        $text = preg_replace('/<\/div>\s*/i', "\n", $text);
        $text = preg_replace('/<div[^>]*>/i', '', $text);

        // 5. Restliche HTML-Tags entfernen
        $text = strip_tags($text);

        // 6. HTML-Entities dekodieren (z.B. &amp; -> &, &nbsp; -> Leerzeichen)
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // 7. Non-breaking spaces durch normale Leerzeichen ersetzen
        $text = str_replace("\xc2\xa0", ' ', $text);

        // 8. Mehrfache Zeilenumbrueche reduzieren (max. 2)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // 9. Whitespace trimmen
        return trim($text);
    }

    /**
     * Ruft den Verbindungsstatus des Service ab
     *
     * Gibt Informationen über den aktuellen Status der
     * WhatsApp-Verbindung zurück.
     *
     * @return array Status-Array:
     *   - success: bool - True wenn Abfrage erfolgreich
     *   - state: string - Verbindungsstatus ('connected', 'disconnected', etc.)
     *   - qr: string - QR-Code falls Verbindung erforderlich (optional)
     */
    public function getStatus()
    {
        return $this->request('GET', '/status');
    }

    /**
     * Prüft ob der Service mit WhatsApp verbunden ist
     *
     * Kurzform-Methode die true zurückgibt wenn der Service
     * aktiv mit WhatsApp verbunden ist.
     *
     * @return bool True wenn verbunden
     */
    public function isConnected()
    {
        $status = $this->getStatus();
        return $status['success'] && ($status['state'] ?? '') === 'connected';
    }

    /**
     * Führt einen HTTP-Request zum Service aus
     *
     * Interne Methode die cURL verwendet um mit dem WhatsApp-Service
     * zu kommunizieren. Unterstützt GET und POST Requests mit
     * JSON-Daten.
     *
     * @param string $method HTTP-Methode ('GET' oder 'POST')
     * @param string $endpoint API-Endpoint (z.B. '/send')
     * @param array|null $data Request-Daten für POST (wird zu JSON konvertiert)
     * @return array Response-Array:
     *   - success: bool - True bei HTTP 2xx
     *   - error: string - Fehlermeldung bei Fehler
     *   - http_code: int - HTTP-Statuscode bei Fehler
     *   - ... weitere Felder aus der API-Response
     */
    private function request($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->apiKey) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('WhatsApp API: Connection error to ' . $url . ' - ' . $error);
            return [
                'success' => false,
                'error' => 'Connection error: ' . $error,
            ];
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $result ?: ['success' => true];
        }

        error_log('WhatsApp API: HTTP ' . $httpCode . ' from ' . $url . ' - ' . ($result['error'] ?? 'Unknown error'));
        return [
            'success' => false,
            'error' => $result['error'] ?? "HTTP Error: {$httpCode}",
            'http_code' => $httpCode,
        ];
    }
}
