<?php
// src/controllers/RestaurantController.php
require_once __DIR__.'/../services/RestaurantService.php';

class RestaurantController {
    
    private RestaurantService $service;
    
    public function __construct() {
        $this->service = new RestaurantService();
    }
    
    // GET /api/services/restaurants
    public function index() {
        return json($this->service->getRestaurants());
    }
    
    // POST /api/services/restaurants/book
    public function book() {
        // Global error handling for this method
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            error_log("[RestaurantController] Raw booking data: " . print_r($data, true));
            
            // Support both formats: restaurant_id and service_id from frontend  
            $serviceId = isset($data['restaurant_id']) ? $data['restaurant_id'] : (isset($data['service_id']) ? $data['service_id'] : '');
            
            if (!$serviceId) {
                return json(array('error' => 'Restaurant ID required'), 400);
            }
            
            // Get user data from payload or session
            $userData = isset($data['user']) ? $data['user'] : array();
            $userId = null;
            
            if (!empty($userData)) {
                // User data provided in request (preferred)
                $userId = isset($userData['user_id']) ? $userData['user_id'] : (isset($userData['id']) ? $userData['id'] : null);
                error_log("[RestaurantController] User from payload: " . json_encode($userData));
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
                    error_log("[RestaurantController] User from session: " . json_encode($userData));
                }
            }
            
            // Allow guest bookings with email
            if (!$userId && empty($userData['email'])) {
                return json(array('error' => 'User authentication or email required'), 401);
            }
            
            // Add user data to request data
            $data['user'] = $userData;
            
            error_log("[RestaurantController] Processing booking - Restaurant: $serviceId, User ID: " . ($userId ? $userId : 'guest'));
            
            // Call Restaurant API
            // Check if service exists
            if (!isset($this->service)) {
                $this->service = new RestaurantService();
            }
            
            $result = $this->service->bookService($serviceId, $data);
            
            // Save booking to database if successful
            if ($result && (isset($result['status']) && ($result['status'] === 'real' || $result['status'] === 'simulated'))) {
                require_once __DIR__ . '/../services/IntegrationBookingService.php';
                $bookingService = new IntegrationBookingService();
                
                $isSimulated = ($result['status'] === 'simulated');
                
                // Use safe user ID
                $finalUserId = $userId ? $userId : 0;
                
                $bookingService->saveBooking(
                    $finalUserId,
                    'restaurant',
                    $data,
                    $result,
                    $isSimulated
                );
            }
            
            return json($result);
            
        } catch (Exception $e) {
            error_log("[RestaurantController] Exception: " . $e->getMessage());
            return json(array('error' => 'Server error: ' . $e->getMessage()), 500);
        } catch (Error $e) {
            error_log("[RestaurantController] Fatal Error: " . $e->getMessage());
            return json(array('error' => 'Fatal error: ' . $e->getMessage()), 500);
        }
    }
    
    // GET /api/services/restaurants/status/:bookingId
    public function status($params) {
        $bookingId = $params['bookingId'] ?? '';
        
        if (!$bookingId) {
            return json(['error' => 'Booking ID required'], 400);
        }
        
        return json($this->service->checkStatus($bookingId));
    }
    
    // POST /api/services/restaurants/cancel
    public function cancel() {
        $data = json_decode(file_get_contents('php://input'), true);
        $bookingId = $data['booking_id'] ?? '';
        
        if (!$bookingId) {
            return json(['error' => 'Booking ID required'], 400);
        }
        
        return json($this->service->cancelBooking($bookingId));
    }
}