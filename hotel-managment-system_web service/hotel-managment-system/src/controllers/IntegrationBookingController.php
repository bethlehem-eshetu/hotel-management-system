<?php
// src/controllers/IntegrationBookingController.php
require_once __DIR__ . '/../services/IntegrationBookingService.php';

class IntegrationBookingController
{
    private $bookingService;
    
    public function __construct()
    {
        $this->bookingService = new IntegrationBookingService();
    }
    
    /**
     * GET /api/integration-bookings
     * Get all integration bookings for current user
     */
    public function getUserBookings()
    {
        start_session();
        $userId = $_SESSION['uid'] ?? null;
        
        if (!$userId) {
            return json(['error' => 'Unauthorized'], 401);
        }
        
        $serviceType = $_GET['service_type'] ?? null;
        $bookings = $this->bookingService->getUserBookings($userId, $serviceType);
        
        return json([
            'success' => true,
            'data' => $bookings,
            'total' => count($bookings)
        ]);
    }
    
    /**
     * GET /api/admin/integration-bookings
     * Get all integration bookings (admin/manager only)
     */
    public function getAllBookings()
    {
        start_session();
        $userRole = $_SESSION['role'] ?? 'customer';
        
        if (!in_array($userRole, ['admin', 'manager'])) {
            return json(['error' => 'Forbidden - Admin/Manager access required'], 403);
        }
        
        $serviceType = $_GET['service_type'] ?? null;
        $limit = (int)($_GET['limit'] ?? 100);
        
        $bookings = $this->bookingService->getAllBookings($serviceType, $limit);
        
        return json([
            'success' => true,
            'data' => $bookings,
            'total' => count($bookings)
        ]);
    }
    
    /**
     * GET /api/admin/integration-bookings/stats
     * Get booking statistics (admin/manager only)
     */
    public function getStatistics()
    {
        start_session();
        $userRole = $_SESSION['role'] ?? 'customer';
        
        if (!in_array($userRole, ['admin', 'manager'])) {
            return json(['error' => 'Forbidden - Admin/Manager access required'], 403);
        }
        
        $stats = $this->bookingService->getStatistics();
        
        return json([
            'success' => true,
            'data' => $stats
        ]);
    }
    
    /**
     * PUT /api/integration-bookings/:id/status
     * Update booking status (admin/manager only)
     */
    public function updateStatus($params)
    {
        start_session();
        $userRole = $_SESSION['role'] ?? 'customer';
        
        if (!in_array($userRole, ['admin', 'manager'])) {
            return json(['error' => 'Forbidden - Admin/Manager access required'], 403);
        }
        
        $bookingId = $params['id'] ?? 0;
        $input = json_decode(file_get_contents('php://input'), true);
        $newStatus = $input['status'] ?? '';
        
        if (!in_array($newStatus, ['pending', 'confirmed', 'cancelled', 'failed'])) {
            return json(['error' => 'Invalid status'], 400);
        }
        
        $success = $this->bookingService->updateStatus($bookingId, $newStatus);
        
        if ($success) {
            return json(['success' => true, 'message' => 'Status updated']);
        } else {
            return json(['error' => 'Failed to update status'], 500);
        }
    }
}
