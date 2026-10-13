<?php
// src/controllers/TaxiController.php
require_once __DIR__ . '/../services/TaxiService.php';

class TaxiController {
    private $taxiService;
    
    public function __construct() {
        $this->taxiService = new TaxiService();
    }
    
    public function index() {
        $result = $this->taxiService->getAvailableServices();
        return json($result);
    }
    
    public function book() {
        // Global error handling
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            error_log("[TaxiController] Raw booking data: " . print_r($data, true));
            
            // Get user data from payload or session
            $userData = isset($data['user']) ? $data['user'] : array();
            $userId = null;
            
            if (!empty($userData)) {
                // User data provided in request (preferred)
                $userId = isset($userData['user_id']) ? $userData['user_id'] : (isset($userData['id']) ? $userData['id'] : null);
                error_log("[TaxiController] User from payload: " . json_encode($userData));
            } else {
                // Fall back to session
                if (session_status() === PHP_SESSION_NONE) {
                    start_session();
                }
                $userId = isset($_SESSION['uid']) ? $_SESSION['uid'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
                
                if ($userId) {
                    $userData = array(
                        'user_id' => $userId,
                        'email' => isset($_SESSION['email']) ? $_SESSION['email'] : '',
                        'name' => isset($_SESSION['name']) ? $_SESSION['name'] : '',
                        'phone' => isset($_SESSION['phone']) ? $_SESSION['phone'] : ''
                    );
                    error_log("[TaxiController] User from session: " . json_encode($userData));
                }
            }
            
            // Allow guest bookings with email
            if (!$userId && empty($userData['email'])) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'User authentication or email required']);
                return;
            }
            
            // Add user data to request data
            $data['user'] = $userData;
            
            error_log("[TaxiController] Processing booking - User ID: " . ($userId ? $userId : 'guest'));
            
            // Call Taxi API
            $result = $this->taxiService->bookTaxi($data);
            
            // Save booking to database if successful
            if ($result && (isset($result['status']) && ($result['status'] === 'real' || $result['status'] === 'simulated'))) {
                require_once __DIR__ . '/../services/IntegrationBookingService.php';
                $bookingService = new IntegrationBookingService();
                
                $isSimulated = ($result['status'] === 'simulated');
                
                // Use safe user ID
                $finalUserId = $userId ? $userId : 0;
                
                $internalId = $bookingService->saveBooking(
                    $finalUserId,
                    'taxi',
                    $data,
                    $result,
                    $isSimulated
                );
                
                // If external API didn't return an ID, use our internal one as fallback
                if (empty($result['booking_id']) && $internalId) {
                    $result['booking_id'] = 'INT-' . $internalId;
                    // Append to message for clarity
                    if (isset($result['message'])) {
                        $result['message'] .= ' (Ref: #' . $internalId . ')';
                    }
                }
            }
            
            return json($result);
            
        } catch (Exception $e) {
            error_log("[TaxiController] Exception: " . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        }
    }
    
    public function status($params) {
        $bookingId = $params['bookingId'] ?? '';
        if (empty($bookingId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Booking ID required']);
            return;
        }
        return json($result);
    }
}