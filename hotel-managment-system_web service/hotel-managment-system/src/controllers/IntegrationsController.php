<?php
// src/controllers/IntegrationsController.php
require_once __DIR__ . '/../bootstrap.php';

class IntegrationsController {
    
    public function getTaxis() {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized. Please login first.'], 401);
        }
        
        try {
            // ✅ FIX: Use the correct URL with ?i=1 parameter
            $response = $this->callTaxiAPI();
            
            if ($response['success']) {
                return json([
                    'status' => 'success',
                    'service' => 'taxi',
                    'provider_groups' => ['group4', 'group8'],
                    'data' => [
                        'taxis' => $response['data'],
                        'currency' => 'ETB'
                    ],
                    'metadata' => [
                        'source' => 'Group 4/8 Taxi API',
                        'total_taxis' => count($response['data']),
                        'response_time_ms' => $response['response_time_ms'],
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                throw new Exception($response['error']);
            }
            
        } catch (Exception $e) {
            error_log("Taxi API error: " . $e->getMessage());
            
            // Fallback to simulated data
            $simulatedTaxis = $this->getSimulatedTaxis();
            
            return json([
                'status' => 'simulated',
                'service' => 'taxi',
                'provider_groups' => ['group4', 'group8'],
                'data' => ['taxis' => $simulatedTaxis],
                'metadata' => [
                    'source' => 'Simulated Data (API Protected)',
                    'total_taxis' => count($simulatedTaxis),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'note' => 'Taxi API requires JavaScript challenge. Using simulated data for demonstration.'
                ]
            ]);
        }
    }
    
    private function callTaxiAPI() {
        // ✅ FIX: Use the working URL with ?i=1 parameter
        $url = 'https://taxi-system.infinityfreeapp.com/api/services.php?i=1';
        $apiKey = 'TAXI_GROUP_SECURE_KEY_2024';
        
        $startTime = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $apiKey,
                'Accept: application/json',
                'User-Agent: HotelSystem/1.0'
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => '/tmp/taxi_cookie.txt', // Store cookies
            CURLOPT_COOKIEFILE => '/tmp/taxi_cookie.txt'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = microtime(true) - $startTime;
        $error = curl_error($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        // Log for debugging
        error_log("Taxi API Call:");
        error_log("  URL: $url");
        error_log("  Effective URL: $effectiveUrl");
        error_log("  HTTP Code: $httpCode");
        error_log("  Response Size: " . strlen($response) . " bytes");
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'CURL Error: ' . $error,
                'response_time_ms' => round($responseTime * 1000, 2)
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'HTTP ' . $httpCode . ' from API',
                'http_code' => $httpCode,
                'response_time_ms' => round($responseTime * 1000, 2),
                'response_preview' => substr($response, 0, 200)
            ];
        }
        
        // Check if we got the JavaScript challenge page
        if (strpos($response, '<html') !== false || 
            strpos($response, '<script') !== false ||
            strpos($response, 'aes.js') !== false) {
            
            error_log("Got JavaScript challenge page. Length: " . strlen($response));
            
            // Try alternative URL without parameters
            $altUrl = 'https://taxi-system.infinityfreeapp.com/api/services.php';
            return $this->tryAlternativeURL($altUrl, $apiKey);
        }
        
        // Try to parse JSON
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON from response
            $jsonStart = strpos($response, '[');
            if ($jsonStart === false) {
                $jsonStart = strpos($response, '{');
            }
            
            if ($jsonStart !== false) {
                $cleanJson = substr($response, $jsonStart);
                $data = json_decode($cleanJson, true);
            }
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Taxi API JSON Parse Error: " . json_last_error_msg());
                
                return [
                    'success' => false,
                    'error' => 'Invalid JSON: ' . json_last_error_msg(),
                    'http_code' => $httpCode,
                    'response_time_ms' => round($responseTime * 1000, 2),
                    'response_preview' => substr($response, 0, 300)
                ];
            }
        }
        
        // Format the taxi data
        $formattedData = $this->formatTaxiData($data);
        
        return [
            'success' => true,
            'data' => $formattedData,
            'http_code' => $httpCode,
            'response_time_ms' => round($responseTime * 1000, 2),
            'raw_response_size' => strlen($response)
        ];
    }
    
    private function tryAlternativeURL($url, $apiKey) {
        // Try without API key or with different headers
        $startTime = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = microtime(true) - $startTime;
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $formattedData = $this->formatTaxiData($data);
                
                return [
                    'success' => true,
                    'data' => $formattedData,
                    'http_code' => $httpCode,
                    'response_time_ms' => round($responseTime * 1000, 2),
                    'note' => 'Used alternative method'
                ];
            }
        }
        
        return [
            'success' => false,
            'error' => 'API requires JavaScript challenge (InfinityFree protection)',
            'http_code' => $httpCode,
            'response_time_ms' => round($responseTime * 1000, 2)
        ];
    }
    
    private function formatTaxiData($data) {
        if (!is_array($data)) {
            return [];
        }
        
        // The Taxi API returns: [{id: 1, name: "Standard Taxi", base_price: "5.00", price_per_km: "2.00"}, ...]
        if (isset($data[0]) && is_array($data[0])) {
            $formatted = [];
            foreach ($data as $taxi) {
                $formatted[] = [
                    'id' => $taxi['id'] ?? uniqid(),
                    'name' => $taxi['name'] ?? 'Taxi',
                    'description' => $taxi['description'] ?? '',
                    'base_price' => (float) ($taxi['base_price'] ?? 5.00),
                    'price_per_km' => (float) ($taxi['price_per_km'] ?? 2.00),
                    'capacity' => $taxi['capacity'] ?? 4,
                    'available' => $taxi['available'] ?? true,
                    'estimated_arrival' => $taxi['estimated_arrival'] ?? '5-10 minutes',
                    'rating' => $taxi['rating'] ?? 4.5
                ];
            }
            return $formatted;
        }
        
        return [];
    }
    
    private function getSimulatedTaxis() {
        // Sample real data from the Taxi API (as seen in browser)
        return [
            [
                'id' => 4,
                'name' => 'Luxury VIP',
                'description' => 'Premium luxury service',
                'base_price' => 20.00,
                'price_per_km' => 5.00,
                'capacity' => 4,
                'available' => true,
                'estimated_arrival' => '5-15 minutes',
                'rating' => 4.8
            ],
            [
                'id' => 1,
                'name' => 'Standard Taxi',
                'description' => 'Reliable standard taxi service',
                'base_price' => 5.00,
                'price_per_km' => 2.00,
                'capacity' => 4,
                'available' => true,
                'estimated_arrival' => '5-10 minutes',
                'rating' => 4.5
            ],
            [
                'id' => 2,
                'name' => 'Family Van',
                'description' => 'Spacious van for families',
                'base_price' => 10.00,
                'price_per_km' => 3.00,
                'capacity' => 7,
                'available' => true,
                'estimated_arrival' => '10-20 minutes',
                'rating' => 4.6
            ]
        ];
    }
    
    // Other methods remain the same...
    public function getTours() {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized. Please login first.'], 401);
        }
        
        try {
            $tours = $this->fetchTourData();
            
            return json([
                'status' => 'success',
                'service' => 'tour',
                'provider_groups' => ['group1', 'group5', 'group9'],
                'data' => ['tours' => $tours],
                'metadata' => [
                    'source' => 'Group 1/5/9 Tour API',
                    'total_tours' => count($tours),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Tour API error: " . $e->getMessage());
            
            $simulatedTours = $this->getSimulatedTours();
            
            return json([
                'status' => 'simulated',
                'service' => 'tour',
                'provider_groups' => ['group1', 'group5', 'group9'],
                'data' => ['tours' => $simulatedTours],
                'metadata' => [
                    'source' => 'Simulated Data',
                    'total_tours' => count($simulatedTours),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'note' => 'Tour API unavailable'
                ]
            ]);
        }
    }
    
    private function fetchTourData() {
        $url = 'https://tour-management-web.onrender.com/api/v1/tours.php';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: demo-api-key',
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Tour API returned HTTP ' . $httpCode);
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON from tour API');
        }
        
        return $this->formatTourData($data);
    }
    
    private function formatTourData($data) {
        if (!is_array($data)) {
            return [];
        }
        
        if (isset($data[0]) && is_array($data[0])) {
            return array_map(function($tour) {
                return [
                    'id' => $tour['id'] ?? uniqid(),
                    'title' => $tour['title'] ?? 'Tour',
                    'description' => $tour['description'] ?? '',
                    'location' => $tour['location'] ?? 'Various',
                    'price' => (float) ($tour['price'] ?? 50.00),
                    'duration_hours' => $tour['duration'] ?? 4,
                    'available' => true,
                    'rating' => $tour['rating'] ?? 4.5
                ];
            }, $data);
        }
        
        return [];
    }
    
    private function getSimulatedTours() {
        return [
            [
                'id' => 'tour_001',
                'title' => 'Addis Ababa City Tour',
                'description' => 'Full day tour covering major attractions',
                'location' => 'Addis Ababa',
                'price' => 1500.00,
                'duration_hours' => 8,
                'available' => true,
                'rating' => 4.8
            ],
            [
                'id' => 'tour_002',
                'title' => 'Lalibela Rock Churches',
                'description' => 'Visit UNESCO World Heritage site',
                'location' => 'Lalibela',
                'price' => 8500.00,
                'duration_hours' => 48,
                'available' => true,
                'rating' => 4.9
            ]
        ];
    }
    
    public function getServicesStatus() {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized. Please login first.'], 401);
        }
        
        $status = [
            'taxi' => $this->checkTaxiStatus(),
            'tour' => $this->checkTourStatus(),
            'restaurant' => [
                'status' => 'disabled',
                'available' => false,
                'message' => 'Service coming soon',
                'last_checked' => date('Y-m-d H:i:s')
            ]
        ];
        
        return json([
            'services' => $status,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Service status retrieved'
        ]);
    }
    
    private function checkTaxiStatus() {
        // Since the API has JavaScript protection, we'll check differently
        $url = 'https://taxi-system.infinityfreeapp.com/api/services.php?i=1';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOBODY => true,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: TAXI_GROUP_SECURE_KEY_2024'
            ]
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status' => $httpCode === 200 ? 'protected' : 'offline',
            'available' => $httpCode === 200,
            'message' => $httpCode === 200 ? 'API is protected (requires JavaScript)' : 'HTTP ' . $httpCode,
            'last_checked' => date('Y-m-d H:i:s'),
            'http_code' => $httpCode,
            'note' => 'InfinityFree JavaScript protection active'
        ];
    }
    
    private function checkTourStatus() {
        try {
            $url = 'https://tour-management-web.onrender.com/api/v1/tours.php';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_NOBODY => true
            ]);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return [
                'status' => $httpCode === 200 ? 'online' : 'offline',
                'available' => $httpCode === 200,
                'message' => $httpCode === 200 ? 'Service operational' : 'HTTP ' . $httpCode,
                'last_checked' => date('Y-m-d H:i:s'),
                'http_code' => $httpCode
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'offline',
                'available' => false,
                'message' => 'Connection failed',
                'last_checked' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    public function discoverServices() {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized. Please login first.'], 401);
        }
        
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                  $_SERVER['HTTP_HOST'] . 
                  dirname($_SERVER['PHP_SELF']);
        
        return json([
            'group' => 'Group 2 - Hotel Management System',
            'version' => '1.0.0',
            'description' => 'Hotel management system with external service integration',
            'contact' => 'group2@example.com',
            'integration_status' => [
                'taxi' => 'Protected by InfinityFree JavaScript challenge',
                'tour' => 'Direct API access available',
                'restaurant' => 'Coming soon'
            ],
            'endpoints' => [
                'taxi_services' => 'GET /api/services/taxis',
                'tour_services' => 'GET /api/services/tours',
                'service_status' => 'GET /api/services/status',
                'service_discovery' => 'GET /api/services/discover'
            ],
            'testing_interface' => $baseUrl . '/pages/customer.html',
            'note' => 'Taxi API requires JavaScript execution for InfinityFree protection'
        ]);
    }
}