<?php
// src/services/IntegrationService.php

class IntegrationService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout = 20;
    protected string $serviceName;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
        $this->baseUrl = getenv(strtoupper($serviceName) . '_API_URL') 
            ?: "https://{$serviceName}-system.infinityfreeapp.com/api";
        $this->apiKey = getenv(strtoupper($serviceName) . '_API_KEY') 
            ?: strtoupper($serviceName) . '_SECURE_KEY_2024';
    }

    protected function request(string $method, string $endpoint, array $payload = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->apiKey
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload ? json_encode($payload) : null
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Detect anti-bot protection or HTML response
        if (str_starts_with(trim($response), '<!DOCTYPE html') || 
            str_contains($response, 'challenge') ||
            str_contains($response, 'Enable JavaScript')) {
            throw new Exception("JavaScript anti-bot detected");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from API");
        }

        return ['status' => $httpCode, 'data' => $decoded];
    }

    // Common booking methods
    public function bookService(string $serviceId, array $userData): array
    {
        $payload = [
            'service_id' => $serviceId,
            'user_id' => $userData['user_id'] ?? 'guest',
            'user_email' => $userData['email'] ?? '',
            'user_name' => $userData['name'] ?? 'Guest',
            'booking_time' => date('Y-m-d H:i:s'),
            'notes' => $userData['notes'] ?? ''
        ];

        try {
            $result = $this->request('POST', 'book.php', $payload);
            return ['status' => 'real', 'booking' => $result['data'], 'source' => $this->serviceName . ' API'];
        } catch (Exception $e) {
            error_log('[' . $this->serviceName . 'Service] ' . $e->getMessage());
            return $this->simulatedBooking($serviceId, $userData, $e->getMessage());
        }
    }

    public function checkStatus(string $bookingId): array
    {
        try {
            $result = $this->request('GET', 'status.php?booking_id=' . urlencode($bookingId));
            return ['status' => 'real', 'data' => $result['data'], 'source' => $this->serviceName . ' API'];
        } catch (Exception $e) {
            error_log('[' . $this->serviceName . 'Service] ' . $e->getMessage());
            return $this->simulatedStatus($bookingId, $e->getMessage());
        }
    }

    public function cancelBooking(string $bookingId): array
    {
        try {
            $result = $this->request('POST', 'cancel.php', ['booking_id' => $bookingId]);
            return ['status' => 'real', 'data' => $result['data'], 'source' => $this->serviceName . ' API'];
        } catch (Exception $e) {
            error_log('[' . $this->serviceName . 'Service] ' . $e->getMessage());
            return $this->simulatedCancel($bookingId, $e->getMessage());
        }
    }

    // Simulated methods to be overridden by child classes
    protected function simulatedBooking(string $serviceId, array $userData, string $reason): array
    {
        return ['status' => 'simulated', 'booking' => [], 'note' => $reason];
    }

    protected function simulatedStatus(string $bookingId, string $reason): array
    {
        return ['status' => 'simulated', 'data' => [], 'note' => $reason];
    }

    protected function simulatedCancel(string $bookingId, string $reason): array
    {
        return ['status' => 'simulated', 'data' => [], 'note' => $reason];
    }
}