<?php
// src/controllers/TourController.php
require_once __DIR__.'/../services/TourService.php';

class TourController {
    
    private TourService $service;
    
    public function __construct() {
        $this->service = new TourService();
    }
    
    // GET /api/services/tours
    public function index() {
        error_log("[TourController] Getting tours...");
        $result = $this->service->getTours();
        error_log("[TourController] Found " . count($result['data'] ?? []) . " tours");
        return json($result);
    }
    
    // POST /api/services/tours/book
    public function book() {
        error_log("[TourController] Processing tour booking...");
        
        // Get input
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json(['error' => 'Invalid JSON input'], 400);
        }
        
        error_log("[TourController] Raw booking data: " . print_r($data, true));
        
        // Support both formats: tour_id and service_id from frontend
        $tourId = $data['tour_id'] ?? $data['service_id'] ?? '';
        
        // Build user data from different possible formats
        $userData = $data['user'] ?? [];
        
        // If no user object, check session
        if (empty($userData)) {
            start_session();
            if (isset($_SESSION['uid'])) {
                $userData['user_id'] = $_SESSION['uid'];
                $userData['email'] = $_SESSION['email'] ?? '';
                $userData['name'] = $_SESSION['name'] ?? '';
            } else {
                // Guest user from frontend data
                $userData['email'] = $data['email'] ?? 'guest@hotel.com';
                $userData['name'] = $data['name'] ?? 'Guest';
            }
        }
        
        // Add additional fields from frontend
        if (isset($data['participants'])) {
            $userData['participants'] = $data['participants'];
        }
        
        error_log("[TourController] Processed - Tour ID: $tourId, User: " . json_encode($userData));
        
        if (empty($tourId)) {
            return json(['error' => 'Tour ID is required'], 400);
        }
        
        if (empty($userData['email']) && empty($userData['user_id'])) {
            return json(['error' => 'Email or User ID is required'], 400);
        }
        
        // Validate tour ID
        if (!is_numeric($tourId) && getenv('USE_SIMULATED_TOURS') !== 'true') {
            return json([
                'error' => 'Tour ID must be numeric for real bookings',
                'hint' => 'Use IDs from /api/services/tours endpoint',
                'available_ids' => 'Try: 1, 2, 3, 6, 7, 8, 9, 10'
            ], 400);
        }
        
        // Process booking
        try {
            $result = $this->service->bookService($tourId, $userData);
            
            error_log("[TourController] Booking result: " . json_encode($result, JSON_PRETTY_PRINT));
            
            // Format response
            $response = [
                'status' => $result['status'] ?? 'unknown',
                'source' => $result['source'] ?? 'unknown',
                'booking' => $result['booking'] ?? null,
                'message' => $result['message'] ?? ($result['booking']['message'] ?? 'Booking processed'),
                'booking_id' => $result['booking']['booking_id'] ?? null
            ];
            
            if (isset($result['note'])) {
                $response['note'] = $result['note'];
            }
            
            return json($response);
            
        } catch (Exception $e) {
            error_log("[TourController] Booking exception: " . $e->getMessage());
            return json(['error' => 'Booking failed: ' . $e->getMessage()], 500);
        }
    }
    
    // GET /api/services/tours/status/:bookingId
    public function status($params) {
        $bookingId = $params['bookingId'] ?? '';
        
        if (empty($bookingId)) {
            return json(['error' => 'Booking ID required'], 400);
        }
        
        $result = $this->service->checkStatus($bookingId);
        return json($result);
    }
    
    // POST /api/services/tours/cancel
    public function cancel() {
        $data = json_decode(file_get_contents('php://input'), true);
        $bookingId = $data['booking_id'] ?? '';
        
        if (empty($bookingId)) {
            return json(['error' => 'Booking ID required'], 400);
        }
        
        $result = $this->service->cancelBooking($bookingId);
        return json($result);
    }
}
?>