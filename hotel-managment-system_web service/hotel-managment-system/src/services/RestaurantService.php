<?php
// src/services/RestaurantService.php
// Completely rewritten to avoid PHP errors

class RestaurantService {
    private $baseUrl;
    private $apiKey;
    private $timeout = 20;

    public function __construct() {
        $this->baseUrl = 'https://restaurant-management.page.gd/api/service-provider.php';
        $this->apiKey = 'TOUR_SERVICE_KEY_2025';
    }

    /**
     * Get list of restaurants
     */
    public function getRestaurants() {
        $url = $this->baseUrl . '?action=restaurants';
        $result = $this->makeRequest('GET', $url);
        
        if ($result['success']) {
            $data = $result['data'];
            // Extract nested data if present
            if (isset($data['data'])) {
                $data = $data['data'];
            }
            return [
                'status' => 'real',
                'data' => $data,
                'source' => 'Restaurant API'
            ];
        }
        
        return $this->simulatedRestaurants($result['error']);
    }

    /**
     * Book a restaurant
     */
    public function bookService($serviceId, $data) {
        $user = isset($data['user']) ? $data['user'] : array();
        
        $payload = array(
            'restaurant_id' => $serviceId,
            'customer_name' => isset($user['name']) ? $user['name'] : 'Guest',
            'customer_email' => isset($user['email']) ? $user['email'] : 'guest@hotel.com',
            'customer_phone' => isset($user['phone']) ? $user['phone'] : '+251000000000',
            'date' => isset($data['reservation_date']) ? $data['reservation_date'] : date('Y-m-d'),
            'time' => isset($data['reservation_time']) ? $data['reservation_time'] : '19:00',
            'guests' => isset($data['guests']) ? (int)$data['guests'] : 2,
            'special_requests' => isset($data['special_requests']) ? $data['special_requests'] : '',
            'external_booking_id' => 'HOTEL-' . uniqid()
        );
        
        error_log("[RestaurantService] Booking payload: " . json_encode($payload));

        $url = $this->baseUrl . '?action=create_reservation';
        $result = $this->makeRequest('POST', $url, $payload);
        
        if ($result['success']) {
            $responseData = $result['data'];
            if (isset($responseData['data'])) {
                $responseData = $responseData['data'];
            }
            
            return array(
                'status' => 'real',
                'message' => 'Restaurant reservation successful',
                'booking_id' => isset($responseData['reservation_id']) ? $responseData['reservation_id'] : uniqid('rest_'),
                'data' => $responseData,
                'source' => 'Restaurant API'
            );
        }
        
        return $this->simulatedBooking($serviceId, $data, $result['error']);
    }

    /**
     * Make HTTP request using basic PHP
     */
    private function makeRequest($method, $url, $data = null) {
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($data !== null && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("[RestaurantService] URL: $url, HTTP Code: $httpCode");

        if ($response === false) {
            error_log("[RestaurantService] CURL Error: $curlError");
            return array('success' => false, 'error' => "CURL error: $curlError");
        }

        // Check if HTML response
        $trimmed = trim($response);
        if (strpos($trimmed, '<!DOCTYPE') === 0 || strpos($trimmed, '<html') === 0) {
            error_log("[RestaurantService] Got HTML response instead of JSON");
            return array('success' => false, 'error' => 'API returned HTML');
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[RestaurantService] JSON error: " . json_last_error_msg());
            error_log("[RestaurantService] Response: " . substr($response, 0, 200));
            return array('success' => false, 'error' => 'Invalid JSON');
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return array('success' => true, 'data' => $decoded);
        }

        return array('success' => false, 'error' => isset($decoded['message']) ? $decoded['message'] : 'API error', 'data' => $decoded);
    }

    /**
     * Simulated restaurants
     */
    private function simulatedRestaurants($reason) {
        error_log("[RestaurantService] Using simulated data: $reason");
        return array(
            'status' => 'simulated',
            'data' => array(
                array(
                    'id' => 'sim_201',
                    'name' => 'Italian Bistro (Simulated)',
                    'cuisine' => 'Italian',
                    'rating' => 4.5,
                    'price_range' => '$$',
                    'opening_hours' => '11:00-23:00',
                    'address' => '123 Main St',
                    'description' => 'Authentic Italian cuisine (API unavailable)'
                ),
                array(
                    'id' => 'sim_202',
                    'name' => 'Sushi Place (Simulated)',
                    'cuisine' => 'Japanese',
                    'rating' => 4.7,
                    'price_range' => '$$$',
                    'opening_hours' => '12:00-22:00',
                    'address' => '456 Oak Ave',
                    'description' => 'Fresh sushi (API unavailable)'
                )
            ),
            'note' => $reason
        );
    }

    /**
     * Simulated booking
     */
    private function simulatedBooking($serviceId, $data, $reason) {
        error_log("[RestaurantService] Using simulated booking: $reason");
        $user = isset($data['user']) ? $data['user'] : array();
        
        return array(
            'status' => 'simulated',
            'message' => 'Reservation simulated (API unavailable)',
            'booking_id' => 'REST_SIM_' . uniqid(),
            'data' => array(
                'restaurant_id' => $serviceId,
                'customer_name' => isset($user['name']) ? $user['name'] : 'Guest',
                'date' => isset($data['reservation_date']) ? $data['reservation_date'] : date('Y-m-d'),
                'time' => isset($data['reservation_time']) ? $data['reservation_time'] : '19:00',
                'guests' => isset($data['guests']) ? $data['guests'] : 2,
                'status' => 'confirmed'
            ),
            'note' => $reason
        );
    }
}