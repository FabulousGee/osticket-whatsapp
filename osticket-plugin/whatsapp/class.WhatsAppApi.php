<?php
/**
 * WhatsApp API Client
 *
 * Communicates with the WhatsApp Node.js service
 */

class WhatsAppApi
{
    private $baseUrl;
    private $apiKey;
    private $timeout = 10;

    /**
     * Constructor
     *
     * @param string $baseUrl Service URL (e.g., http://127.0.0.1:3000)
     * @param string $apiKey Optional API key for authentication
     */
    public function __construct($baseUrl, $apiKey = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Send a text message via WhatsApp
     *
     * @param string $phone Phone number (with country code)
     * @param string $message Message text
     * @return array Result with success status
     */
    public function sendMessage($phone, $message)
    {
        $endpoint = $this->apiKey ? '/send-secure' : '/send';

        return $this->request('POST', $endpoint, [
            'phone' => $phone,
            'message' => $message,
        ]);
    }

    /**
     * Get service connection status
     *
     * @return array Status information
     */
    public function getStatus()
    {
        return $this->request('GET', '/status');
    }

    /**
     * Check if service is connected to WhatsApp
     *
     * @return bool
     */
    public function isConnected()
    {
        $status = $this->getStatus();
        return $status['success'] && ($status['state'] ?? '') === 'connected';
    }

    /**
     * Make HTTP request to the service
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data (for POST)
     * @return array Response data
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
            return [
                'success' => false,
                'error' => 'Connection error: ' . $error,
            ];
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $result ?: ['success' => true];
        }

        return [
            'success' => false,
            'error' => $result['error'] ?? "HTTP Error: {$httpCode}",
            'http_code' => $httpCode,
        ];
    }
}
