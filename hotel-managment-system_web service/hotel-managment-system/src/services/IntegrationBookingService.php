<?php
// src/services/IntegrationBookingService.php

/**
 * Service to manage external service bookings
 * Stores booking records from Taxi, Restaurant, and Tour services
 */
class IntegrationBookingService
{
    private $db;
    
    public function __construct()
    {
        // Use the db() helper function from bootstrap.php
        if (function_exists('db')) {
            $this->db = db();
        } else {
            // Fallback if global is set (legacy support)
            global $pdo;
            $this->db = $pdo;
        }
        
        if (!$this->db) {
            error_log("[IntegrationBookingService] Database connection not available!");
        }
    }
    
    /**
     * Save external service booking to database
     * 
     * @param int $userId User ID who made the booking
     * @param string $serviceType 'taxi', 'restaurant', or 'tour'
     * @param array $requestData Original request sent to external API
     * @param array $responseData Response received from external API
     * @param bool $isSimulated Whether this was a simulated booking
     * @return int|false Booking ID or false on failure
     */
    public function saveBooking(
        int $userId,
        string $serviceType,
        array $requestData,
        array $responseData,
        bool $isSimulated = false
    ) {
        try {
            // Extract external booking ID if available
            $externalBookingId = $this->extractBookingId($responseData);
            
            // Extract HTTP status if available
            $httpStatusCode = $responseData['metadata']['http_status'] ?? 200;
            
            // Extract status
            $status = $this->determineStatus($responseData, $isSimulated);
            
            $sql = "INSERT INTO integration_bookings (
                user_id,
                service_type,
                request_data,
                response_data,
                simulated,
                external_booking_id,
                http_status_code,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $userId,
                $serviceType,
                json_encode($requestData),
                json_encode($responseData),
                $isSimulated ? 1 : 0,
                $externalBookingId,
                $httpStatusCode,
                $status
            ]);
            
            if ($success) {
                $bookingId = $this->db->lastInsertId();
                error_log("[IntegrationBookingService] Saved $serviceType booking #$bookingId for user #$userId");
                return $bookingId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("[IntegrationBookingService] Error saving booking: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all bookings for a specific user
     */
    public function getUserBookings(int $userId, ?string $serviceType = null): array
    {
        try {
            $sql = "SELECT * FROM integration_bookings WHERE user_id = ?";
            $params = [$userId];
            
            if ($serviceType) {
                $sql .= " AND service_type = ?";
                $params[] = $serviceType;
            }
            
            $sql .= " ORDER BY booked_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $bookings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $bookings[] = $this->formatBooking($row);
            }
            
            return $bookings;
            
        } catch (Exception $e) {
            error_log("[IntegrationBookingService] Error getting user bookings: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all bookings (for admin/manager view)
     */
    public function getAllBookings(?string $serviceType = null, int $limit = 100): array
    {
        try {
            $sql = "SELECT ib.*, u.name as user_name, u.email as user_email 
                    FROM integration_bookings ib
                    LEFT JOIN users u ON ib.user_id = u.id";
            
            $params = [];
            
            if ($serviceType) {
                $sql .= " WHERE ib.service_type = ?";
                $params[] = $serviceType;
            }
            
            $sql .= " ORDER BY ib.booked_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $bookings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $bookings[] = $this->formatBooking($row);
            }
            
            return $bookings;
            
        } catch (Exception $e) {
            error_log("[IntegrationBookingService] Error getting all bookings: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get booking statistics
     */
    public function getStatistics(): array
    {
        try {
            $sql = "SELECT 
                service_type,
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN simulated = 1 THEN 1 ELSE 0 END) as simulated_count,
                SUM(CASE WHEN simulated = 0 THEN 1 ELSE 0 END) as real_count
                FROM integration_bookings
                GROUP BY service_type";
            
            $stmt = $this->db->query($sql);
            $stats = [];
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats[$row['service_type']] = $row;
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("[IntegrationBookingService] Error getting statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract booking ID from various response formats
     */
    private function extractBookingId(array $responseData): ?string
    {
        // Try multiple common locations
        return $responseData['booking_id'] 
            ?? $responseData['data']['booking_id']
            ?? $responseData['booking']['booking_id']
            ?? $responseData['data']['reservation_id']
            ?? $responseData['reservation_id']
            ?? $responseData['data']['id']
            ?? $responseData['id']
            ?? null;
    }
    
    /**
     * Determine booking status from response
     */
    private function determineStatus(array $responseData, bool $isSimulated): string
    {
        if ($isSimulated) {
            return 'confirmed'; // Simulated bookings are auto-confirmed
        }
        
        // Extract status from various locations
        $status = $responseData['status']
            ?? $responseData['data']['status']
            ?? $responseData['booking']['status']
            ?? 'confirmed';
        
        // Normalize status values
        if (in_array($status, ['confirmed', 'pending', 'cancelled', 'failed'])) {
            return $status;
        }
        
        // Default to confirmed for successful responses
        return 'confirmed';
    }
    
    /**
     * Format booking data for display
     */
    private function formatBooking(array $row): array
    {
        $row['request_data'] = json_decode($row['request_data'], true);
        $row['response_data'] = json_decode($row['response_data'], true);
        $row['simulated'] = (bool)$row['simulated'];
        
        return $row;
    }
    
    /**
     * Update booking status
     */
    public function updateStatus(int $bookingId, string $status): bool
    {
        try {
            $sql = "UPDATE integration_bookings SET status = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$status, $bookingId]);
        } catch (Exception $e) {
            error_log("[IntegrationBookingService] Error updating status: " . $e->getMessage());
            return false;
        }
    }
}
