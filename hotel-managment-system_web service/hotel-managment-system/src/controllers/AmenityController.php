<?php

require_once __DIR__ . '/../bootstrap.php';

class AmenityController {

    // ==================== HELPER METHODS ====================
    
    /**
     * Set JSON headers to prevent HTML errors
     */
    private function setJsonHeaders() {
        header('Content-Type: application/json; charset=utf-8');
        // Prevent any HTML output
        ini_set('display_errors', 0);
        ini_set('html_errors', 0);
    }
    
    /**
     * Standard JSON response
     */
    private function jsonResponse($status, $message, $data = null, $httpCode = 200) {
        http_response_code($httpCode);
        
        $response = [
            'status' => $status,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Get JSON input safely
     */
    private function getJsonInput() {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }
        
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            return null;
        }
        
        return $data;
    }
    
    /**
     * Check if user is admin
     */
    private function checkAdminAuth() {
        start_session();
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            $this->jsonResponse('error', 'Forbidden: Admin access required', null, 403);
        }
    }
    
    /**
     * Extract numeric ID from parameter
     */
    private function extractNumericId($param) {
        if (is_numeric($param)) {
            return (int)$param;
        }
        
        // Try to extract number from string
        if (is_string($param)) {
            preg_match('/\d+/', $param, $matches);
            if (!empty($matches)) {
                return (int)$matches[0];
            }
        }
        
        return null;
    }

    // ==================== GET /api/amenities ====================
    public function index() {
        $this->setJsonHeaders();
        
        try {
            $stmt = db()->query("SELECT id, name, description, created_at FROM amenities ORDER BY name ASC");
            $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->jsonResponse('ok', 'Amenities retrieved successfully', [
                'count' => count($amenities),
                'amenities' => $amenities
            ]);
            
        } catch (Exception $e) {
            error_log("AmenityController::index Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to retrieve amenities', null, 500);
        }
    }

    // ==================== GET /api/amenities/{id} ====================
    public function show($id) {
        $this->setJsonHeaders();
        
        try {
            $amenityId = $this->extractNumericId($id);
            if (!$amenityId) {
                $this->jsonResponse('error', 'Invalid amenity ID format. Received: ' . $id, null, 400);
            }
            
            $stmt = db()->prepare("SELECT id, name, description, created_at FROM amenities WHERE id = ?");
            $stmt->execute([$amenityId]);
            $amenity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$amenity) {
                $this->jsonResponse('error', 'Amenity not found', null, 404);
            }
            
            $this->jsonResponse('ok', 'Amenity retrieved successfully', [
                'amenity' => $amenity
            ]);
            
        } catch (Exception $e) {
            error_log("AmenityController::show Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to retrieve amenity', null, 500);
        }
    }

    // ==================== GET /api/hotels/{hotelId}/amenities ====================
    // FIXED: This is the endpoint that was returning 400 Bad Request
    public function byHotel($hotelId) {
        $this->setJsonHeaders();
        
        // Log for debugging
        error_log("AmenityController::byHotel called with hotelId: " . $hotelId);
        
        try {
            // Extract numeric ID from parameter (handles both "14" and "{hotelId}" format)
            $hotelId = $this->extractNumericId($hotelId);
            
            if (!$hotelId) {
                // List available hotels to help debugging
                $availableHotels = db()->query("SELECT id, name FROM hotels LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                
                $this->jsonResponse('error', 'Invalid hotel ID. Received: ' . $hotelId, [
                    'available_hotels' => $availableHotels
                ], 400);
            }
            
            // First, check if hotel exists
            $hotelStmt = db()->prepare("SELECT id, name FROM hotels WHERE id = ?");
            $hotelStmt->execute([$hotelId]);
            $hotel = $hotelStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hotel) {
                $this->jsonResponse('error', 'Hotel not found. ID: ' . $hotelId, null, 404);
            }
            
            // Check if hotel_amenities table exists
            try {
                $tableCheck = db()->query("SHOW TABLES LIKE 'hotel_amenities'")->fetch();
                if (!$tableCheck) {
                    // Table doesn't exist - return empty array
                    $this->jsonResponse('ok', 'No amenities assigned (hotel_amenities table not found)', [
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotel['name'],
                        'count' => 0,
                        'amenities' => []
                    ]);
                }
            } catch (Exception $e) {
                // Table check failed
                error_log("Table check failed: " . $e->getMessage());
            }
            
            // Get amenities for this hotel
            $stmt = db()->prepare("
                SELECT a.id, a.name, a.description, a.created_at
                FROM amenities a
                INNER JOIN hotel_amenities ha ON ha.amenity_id = a.id
                WHERE ha.hotel_id = ?
                ORDER BY a.name ASC
            ");
            
            $stmt->execute([$hotelId]);
            $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no amenities, check if amenities table has data
            if (empty($amenities)) {
                $amenitiesCount = db()->query("SELECT COUNT(*) FROM amenities")->fetchColumn();
                
                $this->jsonResponse('ok', 'No amenities assigned to this hotel', [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotel['name'],
                    'count' => 0,
                    'amenities' => [],
                    'info' => $amenitiesCount > 0 
                        ? 'Amenities exist in database but not assigned to this hotel' 
                        : 'No amenities in database'
                ]);
            }
            
            $this->jsonResponse('ok', 'Amenities retrieved successfully', [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotel['name'],
                'count' => count($amenities),
                'amenities' => $amenities
            ]);
            
        } catch (Exception $e) {
            error_log("AmenityController::byHotel Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $this->jsonResponse('error', 'Server error: ' . $e->getMessage(), [
                'hotel_id' => $hotelId ?? 'unknown'
            ], 500);
        }
    }

    // ==================== POST /api/amenities ====================
    public function create() {
        $this->setJsonHeaders();
        $this->checkAdminAuth();
        
        try {
            $data = $this->getJsonInput();
            
            if (!$data) {
                $this->jsonResponse('error', 'Invalid JSON input', null, 400);
            }
            
            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            
            if (empty($name)) {
                $this->jsonResponse('error', 'Name is required', null, 400);
            }
            
            // Check for duplicate
            $checkStmt = db()->prepare("SELECT id FROM amenities WHERE name = ?");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                $this->jsonResponse('error', 'Amenity with this name already exists', null, 409);
            }
            
            // Create amenity
            $stmt = db()->prepare("INSERT INTO amenities (name, description, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$name, $description]);
            $amenityId = db()->lastInsertId();
            
            // Get created amenity
            $stmt = db()->prepare("SELECT id, name, description, created_at FROM amenities WHERE id = ?");
            $stmt->execute([$amenityId]);
            $amenity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->jsonResponse('ok', 'Amenity created successfully', [
                'amenity' => $amenity
            ], 201);
            
        } catch (Exception $e) {
            error_log("AmenityController::create Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to create amenity', null, 500);
        }
    }

    // ==================== PATCH /api/amenities/{id} ====================
    public function update($id) {
        $this->setJsonHeaders();
        $this->checkAdminAuth();
        
        try {
            $amenityId = $this->extractNumericId($id);
            if (!$amenityId) {
                $this->jsonResponse('error', 'Invalid amenity ID', null, 400);
            }
            
            $data = $this->getJsonInput();
            if (!$data) {
                $this->jsonResponse('error', 'Invalid JSON input', null, 400);
            }
            
            // Check if amenity exists
            $checkStmt = db()->prepare("SELECT id FROM amenities WHERE id = ?");
            $checkStmt->execute([$amenityId]);
            if (!$checkStmt->fetch()) {
                $this->jsonResponse('error', 'Amenity not found', null, 404);
            }
            
            // Build update fields
            $fields = [];
            $params = [];
            
            if (isset($data['name'])) {
                $name = trim($data['name']);
                if (empty($name)) {
                    $this->jsonResponse('error', 'Name cannot be empty', null, 400);
                }
                
                // Check duplicate name
                $dupStmt = db()->prepare("SELECT id FROM amenities WHERE name = ? AND id != ?");
                $dupStmt->execute([$name, $amenityId]);
                if ($dupStmt->fetch()) {
                    $this->jsonResponse('error', 'Another amenity with this name already exists', null, 409);
                }
                
                $fields[] = 'name = ?';
                $params[] = $name;
            }
            
            if (isset($data['description'])) {
                $fields[] = 'description = ?';
                $params[] = trim($data['description']);
            }
            
            if (empty($fields)) {
                $this->jsonResponse('error', 'No data to update', null, 400);
            }
            
            $params[] = $amenityId;
            
            // Update
            $sql = "UPDATE amenities SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            
            // Get updated amenity
            $stmt = db()->prepare("SELECT id, name, description, created_at, updated_at FROM amenities WHERE id = ?");
            $stmt->execute([$amenityId]);
            $amenity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->jsonResponse('ok', 'Amenity updated successfully', [
                'amenity' => $amenity
            ]);
            
        } catch (Exception $e) {
            error_log("AmenityController::update Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to update amenity', null, 500);
        }
    }

    // ==================== DELETE /api/amenities/{id} ====================
    public function delete($id) {
        $this->setJsonHeaders();
        $this->checkAdminAuth();
        
        try {
            $amenityId = $this->extractNumericId($id);
            if (!$amenityId) {
                $this->jsonResponse('error', 'Invalid amenity ID', null, 400);
            }
            
            // Check if amenity exists
            $checkStmt = db()->prepare("SELECT id FROM amenities WHERE id = ?");
            $checkStmt->execute([$amenityId]);
            if (!$checkStmt->fetch()) {
                $this->jsonResponse('error', 'Amenity not found', null, 404);
            }
            
            // Check if assigned to hotels
            $hotelStmt = db()->prepare("SELECT COUNT(*) as count FROM hotel_amenities WHERE amenity_id = ?");
            $hotelStmt->execute([$amenityId]);
            $result = $hotelStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $this->jsonResponse('error', 'Cannot delete amenity. It is assigned to ' . $result['count'] . ' hotel(s).', null, 400);
            }
            
            // Delete
            $stmt = db()->prepare("DELETE FROM amenities WHERE id = ?");
            $stmt->execute([$amenityId]);
            
            $this->jsonResponse('ok', 'Amenity deleted successfully');
            
        } catch (Exception $e) {
            error_log("AmenityController::delete Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to delete amenity', null, 500);
        }
    }

    // ==================== POST /api/hotels/{hotelId}/amenities ====================
    // Assign amenities to a hotel
    public function assignToHotel($hotelId) {
        $this->setJsonHeaders();
        $this->checkAdminAuth();
        
        try {
            $hotelId = $this->extractNumericId($hotelId);
            if (!$hotelId) {
                $this->jsonResponse('error', 'Invalid hotel ID', null, 400);
            }
            
            // Check hotel exists
            $hotelStmt = db()->prepare("SELECT id FROM hotels WHERE id = ?");
            $hotelStmt->execute([$hotelId]);
            if (!$hotelStmt->fetch()) {
                $this->jsonResponse('error', 'Hotel not found', null, 404);
            }
            
            $data = $this->getJsonInput();
            if (!$data) {
                $this->jsonResponse('error', 'Invalid JSON input', null, 400);
            }
            
            $amenityIds = $data['amenity_ids'] ?? [];
            if (!is_array($amenityIds)) {
                $this->jsonResponse('error', 'amenity_ids must be an array', null, 400);
            }
            
            // Validate amenity IDs
            $validAmenityIds = [];
            foreach ($amenityIds as $aid) {
                if (is_numeric($aid) && $aid > 0) {
                    $validAmenityIds[] = (int)$aid;
                }
            }
            
            if (empty($validAmenityIds)) {
                $this->jsonResponse('error', 'No valid amenity IDs provided', null, 400);
            }
            
            // Check all amenities exist
            $placeholders = str_repeat('?,', count($validAmenityIds) - 1) . '?';
            $checkStmt = db()->prepare("SELECT id FROM amenities WHERE id IN ($placeholders)");
            $checkStmt->execute($validAmenityIds);
            $existingAmenities = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($existingAmenities) !== count($validAmenityIds)) {
                $nonExistent = array_diff($validAmenityIds, $existingAmenities);
                $this->jsonResponse('error', 'Some amenities do not exist: ' . implode(', ', $nonExistent), null, 400);
            }
            
            $pdo = db();
            $pdo->beginTransaction();
            
            try {
                // Remove existing assignments
                $deleteStmt = $pdo->prepare("DELETE FROM hotel_amenities WHERE hotel_id = ?");
                $deleteStmt->execute([$hotelId]);
                
                // Insert new assignments
                $insertStmt = $pdo->prepare("INSERT INTO hotel_amenities (hotel_id, amenity_id, assigned_at) VALUES (?, ?, NOW())");
                
                foreach ($validAmenityIds as $amenityId) {
                    $insertStmt->execute([$hotelId, $amenityId]);
                }
                
                $pdo->commit();
                
                // Get updated list
                $amenitiesStmt = $pdo->prepare("
                    SELECT a.id, a.name, a.description 
                    FROM amenities a
                    INNER JOIN hotel_amenities ha ON ha.amenity_id = a.id
                    WHERE ha.hotel_id = ?
                    ORDER BY a.name ASC
                ");
                $amenitiesStmt->execute([$hotelId]);
                $amenities = $amenitiesStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $this->jsonResponse('ok', 'Amenities assigned to hotel successfully', [
                    'hotel_id' => $hotelId,
                    'assigned_count' => count($validAmenityIds),
                    'amenities' => $amenities
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("AmenityController::assignToHotel Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to assign amenities to hotel', null, 500);
        }
    }

    // ==================== DELETE /api/hotels/{hotelId}/amenities/{amenityId} ====================
    // Remove a specific amenity from a hotel
    public function removeFromHotel($hotelId, $amenityId) {
        $this->setJsonHeaders();
        $this->checkAdminAuth();
        
        try {
            $hotelId = $this->extractNumericId($hotelId);
            $amenityId = $this->extractNumericId($amenityId);
            
            if (!$hotelId || !$amenityId) {
                $this->jsonResponse('error', 'Invalid hotel or amenity ID', null, 400);
            }
            
            // Check if assignment exists
            $checkStmt = db()->prepare("SELECT id FROM hotel_amenities WHERE hotel_id = ? AND amenity_id = ?");
            $checkStmt->execute([$hotelId, $amenityId]);
            
            if (!$checkStmt->fetch()) {
                $this->jsonResponse('error', 'This amenity is not assigned to the specified hotel', null, 404);
            }
            
            // Remove
            $stmt = db()->prepare("DELETE FROM hotel_amenities WHERE hotel_id = ? AND amenity_id = ?");
            $stmt->execute([$hotelId, $amenityId]);
            
            $this->jsonResponse('ok', 'Amenity removed from hotel successfully');
            
        } catch (Exception $e) {
            error_log("AmenityController::removeFromHotel Error: " . $e->getMessage());
            $this->jsonResponse('error', 'Failed to remove amenity from hotel', null, 500);
        }
    }

    // ==================== DEBUG ENDPOINTS ====================
    
    // GET /api/amenities/test
    public function test() {
        $this->setJsonHeaders();
        
        $this->jsonResponse('ok', 'Amenity API is working', [
            'timestamp' => date('Y-m-d H:i:s'),
            'endpoints' => [
                'GET /api/amenities' => 'List all amenities',
                'GET /api/amenities/{id}' => 'Get specific amenity',
                'GET /api/hotels/{hotelId}/amenities' => 'Get amenities for a hotel',
                'POST /api/amenities' => 'Create new amenity',
                'PATCH /api/amenities/{id}' => 'Update amenity',
                'DELETE /api/amenities/{id}' => 'Delete amenity',
                'POST /api/hotels/{hotelId}/amenities' => 'Assign amenities to hotel',
                'DELETE /api/hotels/{hotelId}/amenities/{amenityId}' => 'Remove amenity from hotel'
            ]
        ]);
    }
    
    // GET /api/debug/amenities-db
    public function debugDatabase() {
        $this->setJsonHeaders();
        
        try {
            $pdo = db();
            
            // Check tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            $tableInfo = [];
            foreach (['hotels', 'amenities', 'hotel_amenities'] as $table) {
                if (in_array($table, $tables)) {
                    $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                    $tableInfo[$table] = ['exists' => true, 'count' => $count];
                    
                    // Sample data for debugging
                    if ($count > 0 && $count <= 10) {
                        $sample = $pdo->query("SELECT * FROM $table LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                        $tableInfo[$table]['sample'] = $sample;
                    }
                } else {
                    $tableInfo[$table] = ['exists' => false];
                }
            }
            
            // Check specific hotels from error logs
            $hotelIds = [1, 11, 14];
            $hotelDetails = [];
            foreach ($hotelIds as $id) {
                $stmt = $pdo->prepare("SELECT id, name FROM hotels WHERE id = ?");
                $stmt->execute([$id]);
                $hotel = $stmt->fetch(PDO::FETCH_ASSOC);
                $hotelDetails[$id] = $hotel ?: 'Not found';
            }
            
            $this->jsonResponse('ok', 'Database debug info', [
                'tables' => $tables,
                'table_info' => $tableInfo,
                'hotel_ids_check' => $hotelDetails,
                'hotel_amenities_relationships' => $pdo->query("
                    SELECT ha.hotel_id, h.name as hotel_name, ha.amenity_id, a.name as amenity_name
                    FROM hotel_amenities ha
                    LEFT JOIN hotels h ON h.id = ha.hotel_id
                    LEFT JOIN amenities a ON a.id = ha.amenity_id
                    ORDER BY ha.hotel_id
                ")->fetchAll(PDO::FETCH_ASSOC)
            ]);
            
        } catch (Exception $e) {
            $this->jsonResponse('error', 'Debug failed: ' . $e->getMessage(), null, 500);
        }
    }
    
    // GET /api/debug/test-hotel-amenities/{hotelId}
    public function testHotelAmenities($hotelId) {
        $this->setJsonHeaders();
        
        $hotelId = $this->extractNumericId($hotelId);
        if (!$hotelId) {
            $this->jsonResponse('error', 'Invalid hotel ID', null, 400);
        }
        
        try {
            $pdo = db();
            
            // 1. Check hotel exists
            $hotelStmt = $pdo->prepare("SELECT id, name FROM hotels WHERE id = ?");
            $hotelStmt->execute([$hotelId]);
            $hotel = $hotelStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hotel) {
                // List available hotels
                $allHotels = $pdo->query("SELECT id, name FROM hotels LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                $this->jsonResponse('error', 'Hotel not found', [
                    'requested_id' => $hotelId,
                    'available_hotels' => $allHotels
                ], 404);
            }
            
            // 2. Check amenities table
            $amenitiesCount = $pdo->query("SELECT COUNT(*) FROM amenities")->fetchColumn();
            
            // 3. Check hotel_amenities table exists
            $tableExists = $pdo->query("SHOW TABLES LIKE 'hotel_amenities'")->fetch();
            
            // 4. Get assigned amenities
            $assignedAmenities = [];
            if ($tableExists) {
                $stmt = $pdo->prepare("
                    SELECT a.id, a.name, a.description
                    FROM amenities a
                    INNER JOIN hotel_amenities ha ON ha.amenity_id = a.id
                    WHERE ha.hotel_id = ?
                ");
                $stmt->execute([$hotelId]);
                $assignedAmenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $this->jsonResponse('ok', 'Test completed', [
                'hotel' => $hotel,
                'amenities_in_database' => $amenitiesCount,
                'hotel_amenities_table_exists' => (bool)$tableExists,
                'assigned_amenities_count' => count($assignedAmenities),
                'assigned_amenities' => $assignedAmenities,
                'test_url' => "/api/hotels/{$hotelId}/amenities"
            ]);
            
        } catch (Exception $e) {
            $this->jsonResponse('error', 'Test failed: ' . $e->getMessage(), null, 500);
        }
    }
    
    // POST /api/debug/setup-sample-data
    public function setupSampleData() {
        $this->setJsonHeaders();
        $this->checkAdminAuth();
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Create hotel_amenities table if it doesn't exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS hotel_amenities (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    hotel_id INT NOT NULL,
                    amenity_id INT NOT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE,
                    FOREIGN KEY (amenity_id) REFERENCES amenities(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_hotel_amenity (hotel_id, amenity_id)
                )
            ");
            
            // Add sample amenities if none exist
            $amenitiesCount = $pdo->query("SELECT COUNT(*) FROM amenities")->fetchColumn();
            if ($amenitiesCount == 0) {
                $sampleAmenities = [
                    ['Free WiFi', 'High-speed internet access'],
                    ['Swimming Pool', 'Outdoor swimming pool'],
                    ['Parking', 'Free parking available'],
                    ['Breakfast', 'Complimentary breakfast'],
                    ['Spa', 'Spa and wellness center'],
                    ['Gym', 'Fitness center'],
                    ['Restaurant', 'On-site restaurant'],
                    ['Bar', 'Hotel bar'],
                    ['Room Service', '24-hour room service'],
                    ['Airport Shuttle', 'Airport transportation']
                ];
                
                $stmt = $pdo->prepare("INSERT INTO amenities (name, description, created_at) VALUES (?, ?, NOW())");
                foreach ($sampleAmenities as $amenity) {
                    $stmt->execute($amenity);
                }
            }
            
            // Get all hotels
            $hotels = $pdo->query("SELECT id FROM hotels")->fetchAll(PDO::FETCH_COLUMN);
            $amenities = $pdo->query("SELECT id FROM amenities")->fetchAll(PDO::FETCH_COLUMN);
            
            // Assign random amenities to each hotel
            if (!empty($hotels) && !empty($amenities)) {
                $assignStmt = $pdo->prepare("INSERT IGNORE INTO hotel_amenities (hotel_id, amenity_id, assigned_at) VALUES (?, ?, NOW())");
                
                foreach ($hotels as $hotelId) {
                    // Assign 2-4 random amenities to each hotel
                    $randomAmenities = array_rand($amenities, min(4, count($amenities)));
                    if (!is_array($randomAmenities)) {
                        $randomAmenities = [$randomAmenities];
                    }
                    
                    foreach ($randomAmenities as $amenityIndex) {
                        $assignStmt->execute([$hotelId, $amenities[$amenityIndex]]);
                    }
                }
            }
            
            $pdo->commit();
            
            $this->jsonResponse('ok', 'Sample data setup completed', [
                'hotels_count' => count($hotels),
                'amenities_count' => count($amenities),
                'hotel_amenities_created' => true
            ]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->jsonResponse('error', 'Setup failed: ' . $e->getMessage(), null, 500);
        }
    }
}