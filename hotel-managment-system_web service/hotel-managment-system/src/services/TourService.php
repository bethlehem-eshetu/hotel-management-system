<?php
// src/services/TourService.php

require_once 'IntegrationService.php';

class TourService extends IntegrationService
{
    public function __construct() {
        parent::__construct('tour');
        // Set specific timeout for Tour API
        $this->timeout = 30;
        // Initialize service URLs immediately
        $this->initializeService();
    }

    // Initialize service with proper URLs
    protected function initializeService(): void
    {
        // Use the actual Tour API URL from documentation
        $this->baseUrl = getenv('TOUR_API_URL')
            ?: 'https://tour-management-web.onrender.com/api/v1';
        $this->apiKey = getenv('TOUR_API_KEY')
            ?: 'demo-api-key'; // From documentation
        
        error_log('[TourService] Initialized with URL: ' . $this->baseUrl);
    }

    public function getTours(): array
    {
        try {
            $result = $this->request('GET', 'tours.php');
            
            // Handle the actual response format
            $tours = $result['data'] ?? [];
            
            // Check if response has nested structure
            if (isset($tours['source']) && isset($tours['data'])) {
                $tours = $tours['data']; // Extract the actual tour data
            }
            
            $formattedTours = [];
            
            foreach ($tours as $tour) {
                $formattedTours[] = [
                    'id' => $tour['id'] ?? 'unknown',
                    'title' => $tour['title'] ?? 'Tour Package',
                    'location' => $tour['location'] ?? 'Various locations',
                    'price' => (float)($tour['price'] ?? 25),
                    'description' => $tour['description'] ?? 'Guided tour experience',
                    'duration' => $tour['duration'] ?? '2 hours',
                    'duration_hours' => $tour['duration_hours'] ?? 2,
                    'schedule_date' => $tour['schedule_date'] ?? date('Y-m-d', strtotime('+1 day')),
                    'max_group_size' => $tour['max_group_size'] ?? 15,
                    'includes' => $tour['includes'] ?? ['Guide', 'Transportation'],
                    'rating' => $tour['rating'] ?? 4.5
                ];
            }
            
            return [
                'status' => 'real',
                'data' => $formattedTours,
                'source' => 'Tour API',
                'count' => count($formattedTours)
            ];
            
        } catch (Exception $e) {
            error_log('[TourService] getTours error: ' . $e->getMessage());
            return $this->simulatedTours($e->getMessage());
        }
    }

    // Override request method with better error handling
    protected function request(string $method, string $endpoint, array $payload = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->apiKey,
            'User-Agent: HotelManagementSystem/1.0'
        ];
        
        $ch = curl_init();
        
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => 'gzip, deflate',
        ];
        
        if ($method !== 'GET') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }
        
        if ($payload !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception("Tour API CURL error: {$curlError}");
        }
        
        // Debug logging
        error_log("[TourService] Request: {$method} {$url}");
        error_log("[TourService] Response - HTTP: {$httpCode}");
        
        // Handle empty response
        if (empty($response)) {
            throw new Exception("Empty response from Tour API");
        }
        
        // Check if response is HTML (Cloudflare/error page)
        $trimmedResponse = trim($response);
        if (str_starts_with($trimmedResponse, '<!DOCTYPE') ||
            str_starts_with($trimmedResponse, '<html')) {
            throw new Exception("Tour API returned HTML instead of JSON");
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[TourService] Invalid JSON response. Preview: " . substr($response, 0, 200));
            throw new Exception("Invalid JSON response from Tour API");
        }
        
        return ['status' => $httpCode, 'data' => $decoded];
    }

    // Simulated methods
    protected function simulatedBooking(string $serviceId, array $userData, string $reason): array
    {
        $tourName = match($serviceId) {
            '1' => 'Paris Highlights Walking Tour',
            '2' => 'Tokyo Food & Culture Tour',
            '3' => 'Bali Beach & Temple Day Trip',
            '6' => 'Lalibela Tour',
            '7' => 'Sof Omar Tour',
            '8' => 'Axum Tour',
            '9' => 'Debre Damo Monastery Tour',
            '10' => 'Harari Region Tour',
            default => 'Tour Package'
        };
        
        $bookingId = 'TOUR_' . uniqid();
        return [
            'status' => 'simulated',
            'booking' => [
                'booking_id' => $bookingId,
                'tour_id' => $serviceId,
                'tour_name' => $tourName,
                'user_name' => $userData['name'] ?? 'Guest',
                'user_email' => $userData['email'] ?? '',
                'booking_date' => date('Y-m-d'),
                'booking_time' => date('H:i:s'),
                'price' => match($serviceId) {
                    '1' => 79,
                    '2' => 99,
                    '3' => 120,
                    '6' => 100,
                    '7' => 150,
                    '8' => 500,
                    '9' => 550,
                    '10' => 450,
                    default => 100
                },
                'status' => 'confirmed',
                'meeting_point' => 'Hotel Lobby',
                'guide_name' => 'Local Guide',
                'participants' => $userData['party_size'] ?? 1,
                'tour_date' => date('Y-m-d', strtotime('+1 day')),
                'confirmation_code' => 'TOUR-' . strtoupper(substr(md5($bookingId), 0, 8))
            ],
            'note' => $reason,
            'source' => 'Simulated Tour API'
        ];
    }

    protected function simulatedStatus(string $bookingId, string $reason): array
    {
        $statuses = ['confirmed', 'in_progress', 'completed'];
        $status = $statuses[array_rand($statuses)];
        
        $statusData = [
            'booking_id' => $bookingId,
            'tour_name' => 'City Tour',
            'status' => $status,
            'start_time' => '10:00 AM',
            'duration' => '2 hours',
            'guide' => 'Guide Smith',
            'participants' => rand(1, 10),
            'meeting_point' => 'Hotel Lobby'
        ];
        
        if ($status === 'in_progress') {
            $statusData['current_location'] = 'Tour in progress';
            $statusData['estimated_completion'] = date('H:i', strtotime('+1 hour'));
        } elseif ($status === 'completed') {
            $statusData['completion_time'] = date('Y-m-d H:i:s', strtotime('-2 hours'));
            $statusData['rating'] = rand(4, 5) . '.' . rand(0, 9);
        }
        
        return [
            'status' => 'simulated',
            'data' => $statusData,
            'note' => $reason,
            'source' => 'Simulated Tour API'
        ];
    }

    protected function simulatedCancel(string $bookingId, string $reason): array
    {
        return [
            'status' => 'simulated',
            'data' => [
                'booking_id' => $bookingId,
                'cancellation_time' => date('Y-m-d H:i:s'),
                'refund_amount' => rand(15, 25),
                'cancellation_fee' => 5,
                'status' => 'cancelled',
                'can_rebook' => true,
                'refund_processed' => true,
                'refund_reference' => 'REF-' . strtoupper(substr(md5($bookingId), 0, 10))
            ],
            'note' => $reason,
            'source' => 'Simulated Tour API'
        ];
    }

    // Corrected: protected method with string parameter
    protected function simulatedTours(string $reason): array
    {
        return [
            'status' => 'simulated',
            'data' => [
                [
                    'id' => '1',
                    'title' => 'Paris Highlights Walking Tour',
                    'price' => 79,
                    'description' => 'Explore the Eiffel Tower, Louvre, and charming streets with a local guide.',
                    'duration' => '2 hours',
                    'duration_hours' => 2,
                    'location' => 'Paris, France',
                    'max_group_size' => 15,
                    'includes' => ['Guide', 'Transportation'],
                    'schedule_date' => date('Y-m-d', strtotime('+1 day')),
                    'rating' => 4.5
                ],
                [
                    'id' => '2',
                    'title' => 'Tokyo Food & Culture Tour',
                    'price' => 99,
                    'description' => 'Taste authentic sushi and visit hidden gems in Tokyo.',
                    'duration' => '2 hours',
                    'duration_hours' => 2,
                    'location' => 'Tokyo, Japan',
                    'max_group_size' => 15,
                    'includes' => ['Guide', 'Transportation'],
                    'schedule_date' => date('Y-m-d', strtotime('+2 days')),
                    'rating' => 4.5
                ]
            ],
            'note' => $reason,
            'source' => 'Simulated Tour API'
        ];
    }

    // Main booking method
    public function bookTour(array $data): array
    {
        $tourId = $data['tour_id'] ?? $data['service_id'] ?? null;
        
        if (!$tourId) {
            throw new Exception("Tour ID is required");
        }
        
        // Check if we should use simulated data
        if (getenv('USE_SIMULATED_TOURS') === 'true') {
            return $this->simulatedBooking($tourId, $data['user'] ?? [], 'Using simulated data for testing');
        }
        
        try {
            // Prepare booking payload for external API
            $bookingPayload = [
                'tour_id' => (int)$tourId, // Ensure it's integer
            ];
            
            // Add user identification
            if (isset($data['user']['user_id']) && $data['user']['user_id'] !== 'guest') {
                $bookingPayload['user_id'] = $data['user']['user_id'];
            } else {
                $bookingPayload['email'] = $data['user']['email'] ?? 'guest@example.com';
                $bookingPayload['name'] = $data['user']['name'] ?? 'Guest';
            }
            
            // Add optional fields
            if (isset($data['user']['phone'])) {
                $bookingPayload['phone'] = $data['user']['phone'];
            }
            
            if (isset($data['participants'])) {
                $bookingPayload['participants'] = $data['participants'];
            }
            
            error_log('[TourService] Booking payload: ' . json_encode($bookingPayload));
            
            // Call external API
            $result = $this->request('POST', 'tours.php', $bookingPayload);
            
            $responseData = $result['data'] ?? [];
            $message = $responseData['message'] ?? 'Tour booked successfully';
            $bookingData = $responseData['data'] ?? [];
            
            // Check for errors in response
            if (strpos(strtolower($message), 'not found') !== false || 
                strpos(strtolower($message), 'error') !== false ||
                strpos(strtolower($message), 'fail') !== false) {
                
                error_log('[TourService] External API booking failed: ' . $message);
                throw new Exception($message);
            }
            
            // Generate booking ID if not provided
            $generatedBookingId = $this->generateBookingId(
                $bookingData['tour_id'] ?? $tourId,
                $bookingData['user_id'] ?? 'guest'
            );
            
            $bookingResponse = [
                'booking_id' => $bookingData['booking_id'] ?? $generatedBookingId,
                'tour_id' => $bookingData['tour_id'] ?? $tourId,
                'user_id' => $bookingData['user_id'] ?? 'guest',
                'status' => $bookingData['status'] ?? 'confirmed',
                'message' => $message,
                'booking_time' => date('Y-m-d H:i:s'),
                'confirmation_code' => 'TOUR-' . strtoupper(substr(md5($generatedBookingId), 0, 8))
            ];
            
            // Add additional data
            if (isset($bookingData['tour_date'])) {
                $bookingResponse['tour_date'] = $bookingData['tour_date'];
            }
            
            if (isset($bookingData['participants'])) {
                $bookingResponse['participants'] = $bookingData['participants'];
            }
            
            error_log('[TourService] Booking successful: ' . $generatedBookingId);
            
            return [
                'status' => 'real',
                'booking' => $bookingResponse,
                'message' => $message,
                'source' => 'Tour API'
            ];
            
        } catch (Exception $e) {
            error_log('[TourService] Booking failed: ' . $e->getMessage());
            
            // Fall back to simulated booking
            $simulatedResult = $this->simulatedBooking(
                $tourId,
                $data['user'] ?? [],
                'External API error: ' . $e->getMessage()
            );
            
            return [
                'status' => $simulatedResult['status'],
                'booking' => $simulatedResult['booking'],
                'note' => $simulatedResult['note'],
                'source' => 'Simulated (API Error)'
            ];
        }
    }
    
    // For backward compatibility
    public function bookService(string $serviceId, array $userData): array
    {
        $data = [
            'tour_id' => $serviceId,
            'user' => $userData
        ];
        
        $result = $this->bookTour($data);
        
        return [
            'status' => $result['status'],
            'booking' => $result['booking'],
            'source' => $result['source'] ?? 'Tour API'
        ];
    }
    
    public function checkStatus(string $bookingId): array
    {
        if (getenv('USE_SIMULATED_TOURS') === 'true') {
            $result = $this->simulatedStatus($bookingId, 'Using simulated data');
            return [
                'status' => $result['status'],
                'data' => $result['data'],
                'note' => $result['note'],
                'source' => 'Simulated Tour API'
            ];
        }
        
        // For real API, you'd need to implement status check
        // Since the API might not have this endpoint, return simulated
        $result = $this->simulatedStatus($bookingId, 'Status check not available in external API');
        return [
            'status' => $result['status'],
            'data' => $result['data'],
            'note' => $result['note'],
            'source' => 'Simulated (API Limitation)'
        ];
    }
    
    public function cancelBooking(string $bookingId): array
    {
        if (getenv('USE_SIMULATED_TOURS') === 'true') {
            $result = $this->simulatedCancel($bookingId, 'Using simulated data');
            return [
                'status' => $result['status'],
                'data' => $result['data'],
                'note' => $result['note'],
                'source' => 'Simulated Tour API'
            ];
        }
        
        // For real API, you'd need to implement cancel
        $result = $this->simulatedCancel($bookingId, 'Cancel endpoint not available in external API');
        return [
            'status' => $result['status'],
            'data' => $result['data'],
            'note' => $result['note'],
            'source' => 'Simulated (API Limitation)'
        ];
    }
    
    // Test API connection
    public function testConnection(): array
    {
        try {
            // Try to get tours
            $tours = $this->getTours();
            
            return [
                'connected' => true,
                'source' => $tours['source'] ?? 'unknown',
                'tour_count' => count($tours['data'] ?? []),
                'base_url' => $this->baseUrl
            ];
            
        } catch (Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
                'base_url' => $this->baseUrl
            ];
        }
    }
    
    // Helper method to generate booking ID
    private function generateBookingId($tourId, $userId): string
    {
        $timestamp = date('YmdHis');
        $random = rand(1000, 9999);
        return 'TOUR-' . $tourId . '-' . $timestamp . '-' . $random;
    }
}
?>