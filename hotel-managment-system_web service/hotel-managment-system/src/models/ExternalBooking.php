<?php
// src/models/ExternalBooking.php

class ExternalBooking
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    public function save(array $bookingData): array
    {
        $query = "INSERT INTO external_bookings (
            external_id, 
            service_type, 
            internal_booking_id,
            user_id,
            user_email,
            user_name,
            booking_data,
            status,
            booking_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($query);
        
        $bookingDataJson = json_encode($bookingData['booking_data'] ?? []);
        
        $stmt->execute([
            $bookingData['external_id'] ?? $bookingData['booking_id'],
            $bookingData['service_type'],
            $bookingData['internal_booking_id'] ?? null,
            $bookingData['user_id'] ?? null,
            $bookingData['user_email'] ?? null,
            $bookingData['user_name'] ?? null,
            $bookingDataJson,
            $bookingData['status'] ?? 'confirmed',
            $bookingData['booking_date'] ?? date('Y-m-d H:i:s')
        ]);
        
        $id = $this->db->lastInsertId();
        
        return [
            'id' => $id,
            'external_id' => $bookingData['external_id'],
            'service_type' => $bookingData['service_type']
        ];
    }
    
    public function findByExternalId(string $externalId): ?array
    {
        $query = "SELECT * FROM external_bookings WHERE external_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$externalId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    public function findByUserId(int $userId): array
    {
        $query = "SELECT * FROM external_bookings WHERE user_id = ? ORDER BY booking_date DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findByUserEmail(string $email): array
    {
        $query = "SELECT * FROM external_bookings WHERE user_email = ? ORDER BY booking_date DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$email]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getAll(array $filters = []): array
    {
        $query = "SELECT * FROM external_bookings WHERE 1=1";
        $params = [];
        
        if (isset($filters['service_type'])) {
            $query .= " AND service_type = ?";
            $params[] = $filters['service_type'];
        }
        
        if (isset($filters['status'])) {
            $query .= " AND status = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['start_date'])) {
            $query .= " AND booking_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (isset($filters['end_date'])) {
            $query .= " AND booking_date <= ?";
            $params[] = $filters['end_date'];
        }
        
        $query .= " ORDER BY booking_date DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updateStatus(string $externalId, string $status): bool
    {
        $query = "UPDATE external_bookings SET status = ? WHERE external_id = ?";
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$status, $externalId]);
    }
    
    public function delete(string $externalId): bool
    {
        $query = "DELETE FROM external_bookings WHERE external_id = ?";
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$externalId]);
    }
    
    public function getStats(): array
    {
        $query = "
            SELECT 
                service_type,
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                DATE(booking_date) as booking_day
            FROM external_bookings 
            WHERE booking_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY service_type, DATE(booking_date)
            ORDER BY booking_day DESC
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}