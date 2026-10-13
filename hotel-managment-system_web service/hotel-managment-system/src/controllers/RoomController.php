<?php
// src/controllers/RoomController.php
require_once __DIR__ . '/../bootstrap.php';

class RoomController {
    private $db;
    
    public function __construct() {
        try {
            // Get the global PDO instance
            $this->db = db();
            
            if (!$this->db) {
                error_log("WARNING: Database connection is null in RoomController");
                // Don't throw exception - let methods handle it
            } else {
                error_log("RoomController initialized with database connection");
            }
        } catch (Exception $e) {
            error_log("ERROR in RoomController constructor: " . $e->getMessage());
            // Don't re-throw, just log
        }
    }
    
    // GET /api/hotels/{id}/rooms
    public function byHotel($hotelId) {
        error_log("=== ROOM BY HOTEL START ===");
        error_log("Hotel ID received: " . print_r($hotelId, true));
        
        // Check database connection
        if (!$this->db) {
            error_log("ERROR: Database connection not available");
            return json(['error' => 'Database connection not available'], 500);
        }
        
        // Extract ID if it's an array (from router)
        if (is_array($hotelId)) {
            if (isset($hotelId['id'])) {
                $hotelId = $hotelId['id'];
            } elseif (isset($hotelId[0])) {
                $hotelId = $hotelId[0];
            }
        }
        
        // Validate hotel ID
        if (!is_numeric($hotelId) || $hotelId <= 0) {
            return json(['error' => 'Invalid hotel ID'], 400);
        }
        
        $hotelId = (int)$hotelId;
        error_log("Processing hotel ID: $hotelId");
        
        try {
            // First check if hotel exists
            $hotelCheck = $this->db->prepare("SELECT id, name FROM hotels WHERE id = ?");
            $hotelCheck->execute([$hotelId]);
            $hotel = $hotelCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$hotel) {
                error_log("Hotel ID $hotelId not found in database");
                return json(['error' => 'Hotel not found'], 404);
            }
            
            error_log("Hotel found: " . $hotel['name']);
            
            // Get rooms for this hotel - WITHOUT description column
            $stmt = $this->db->prepare(
                "SELECT id, hotel_id, room_type, room_number, price_per_night, 
                        capacity, status, created_at, updated_at
                 FROM rooms 
                 WHERE hotel_id = ? 
                 ORDER BY room_number"
            );
            $stmt->execute([$hotelId]);
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($rooms) . " rooms for hotel $hotelId");
            
            return json([
                'status' => 'ok',
                'hotel_id' => $hotelId,
                'hotel_name' => $hotel['name'],
                'count' => count($rooms),
                'rooms' => $rooms
            ]);
            
        } catch (PDOException $e) {
            error_log("Database error in byHotel: " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            return json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (Exception $e) {
            error_log("General Exception in byHotel: " . $e->getMessage());
            return json(['error' => 'Failed to fetch rooms: ' . $e->getMessage()], 500);
        }
    }
    
    // GET /api/rooms/{id}
    public function show($id) {
        error_log("=== ROOM SHOW START ===");
        error_log("Room ID received: " . print_r($id, true));
        
        // Check database connection
        if (!$this->db) {
            error_log("ERROR: Database connection not available");
            return json(['error' => 'Database connection not available'], 500);
        }
        
        // Extract ID if it's an array (from router)
        if (is_array($id)) {
            if (isset($id['id'])) {
                $id = $id['id'];
            } elseif (isset($id[0])) {
                $id = $id[0];
            }
        }
        
        $roomId = (int)$id;
        error_log("Processing room ID: $roomId");
        
        if ($roomId <= 0) {
            return json(['error' => 'Invalid room ID'], 400);
        }
        
        try {
            // Get room details with hotel info - WITHOUT description column
            $stmt = $this->db->prepare(
                "SELECT r.id, r.hotel_id, r.room_type, r.room_number, r.price_per_night,
                        r.capacity, r.status, r.created_at, r.updated_at,
                        h.name as hotel_name, h.city, h.country, h.address
                 FROM rooms r 
                 JOIN hotels h ON r.hotel_id = h.id 
                 WHERE r.id = ?"
            );
            $stmt->execute([$roomId]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room) {
                error_log("Room ID $roomId not found");
                return json(['error' => 'Room not found'], 404);
            }
            
            error_log("Room found: " . print_r($room, true));
            
            // Get availability for next 30 days
            try {
                $availStmt = $this->db->prepare(
                    "SELECT date, is_available 
                     FROM room_availability 
                     WHERE room_id = ? 
                     AND date >= CURDATE()
                     AND date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                     ORDER BY date"
                );
                $availStmt->execute([$roomId]);
                $availability = $availStmt->fetchAll(PDO::FETCH_ASSOC);
                $room['availability'] = $availability;
            } catch (Exception $e) {
                error_log("Availability check skipped: " . $e->getMessage());
                $room['availability'] = [];
            }
            
            return json([
                'status' => 'ok',
                'room' => $room
            ]);
            
        } catch (PDOException $e) {
            error_log("Database error in show: " . $e->getMessage());
            return json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (Exception $e) {
            error_log("General Exception in show: " . $e->getMessage());
            return json(['error' => 'Failed to fetch room: ' . $e->getMessage()], 500);
        }
    }
    
    // POST /api/hotels/{id}/rooms - FIXED: Parameters swapped ($hotelId, $data)
    public function create($hotelId, $data) {
        error_log("=== ROOM CREATE START ===");
        error_log("Hotel ID received: " . print_r($hotelId, true));
        error_log("Data: " . print_r($data, true));
        
        // Check database connection first
        if (!$this->db) {
            error_log("ERROR: Database connection is null!");
            return json(['error' => 'Database connection failed'], 500);
        }
        
        start_session();
        
        // Check permissions
        if (!isset($_SESSION['uid'])) {
            error_log("Unauthorized: No user session");
            return json(['error' => 'Unauthorized'], 401);
        }
        
        $userId = $_SESSION['uid'];
        $userRole = $_SESSION['role'] ?? '';
        error_log("User ID: $userId, Role: $userRole");
        
        // Only managers and admins can create rooms
        if ($userRole !== 'manager' && $userRole !== 'admin') {
            return json(['error' => 'Forbidden - Only managers and admins can create rooms'], 403);
        }
        
        // Extract ID if it's an array (from router)
        if (is_array($hotelId)) {
            if (isset($hotelId['id'])) {
                $hotelId = $hotelId['id'];
            } elseif (isset($hotelId[0])) {
                $hotelId = $hotelId[0];
            }
        }
        
        // Validate hotel ID
        if (!is_numeric($hotelId) || $hotelId <= 0) {
            return json(['error' => 'Invalid hotel ID'], 400);
        }
        
        $hotelId = (int)$hotelId;
        error_log("Processing hotel ID: $hotelId");
        
        try {
            // Check if hotel exists
            $hotelCheck = $this->db->prepare("SELECT id, name, manager_id FROM hotels WHERE id = ?");
            $hotelCheck->execute([$hotelId]);
            $hotel = $hotelCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$hotel) {
                error_log("Hotel ID $hotelId not found in database");
                return json(['error' => 'Hotel not found. Cannot create room for non-existent hotel.'], 404);
            }
            
            error_log("Hotel found: " . $hotel['name']);
            
            // If manager, check if they manage this hotel
            if ($userRole === 'manager') {
                if ($hotel['manager_id'] != $userId) {
                    error_log("Manager $userId does not manage hotel $hotelId (manager: {$hotel['manager_id']})");
                    return json(['error' => 'You can only add rooms to hotels you manage'], 403);
                }
                error_log("Manager permission verified");
            }
            
            // Validate required fields
            $required = ['room_type', 'room_number', 'price_per_night', 'capacity'];
            $missing = [];
            
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }
            
            if (!empty($missing)) {
                return json([
                    'error' => 'Missing required fields',
                    'missing_fields' => $missing
                ], 400);
            }
            
            // Additional validation
            $price = floatval($data['price_per_night']);
            $capacity = intval($data['capacity']);
            
            if ($price <= 0) {
                return json(['error' => 'Price must be greater than 0'], 400);
            }
            
            if ($capacity <= 0) {
                return json(['error' => 'Capacity must be at least 1'], 400);
            }
            
            $roomNumber = trim($data['room_number']);
            $roomType = trim($data['room_type']);
            
            // Check if room number already exists for this hotel
            $roomCheck = $this->db->prepare(
                "SELECT id FROM rooms WHERE hotel_id = ? AND room_number = ?"
            );
            $roomCheck->execute([$hotelId, $roomNumber]);
            
            if ($roomCheck->fetch()) {
                return json([
                    'error' => 'Room number already exists for this hotel',
                    'room_number' => $roomNumber,
                    'hotel_id' => $hotelId
                ], 409);
            }
            
            // Create the room - WITHOUT description column
            $stmt = $this->db->prepare(
                "INSERT INTO rooms 
                 (hotel_id, room_type, room_number, price_per_night, capacity, status) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            
            $status = $data['status'] ?? 'available';
            
            error_log("Executing INSERT with values: $hotelId, $roomType, $roomNumber, $price, $capacity, $status");
            
            $stmt->execute([
                $hotelId,
                $roomType,
                $roomNumber,
                $price,
                $capacity,
                $status
            ]);
            
            $roomId = $this->db->lastInsertId();
            
            error_log("Room created successfully. ID: $roomId");
            
            // Get the created room details
            $roomStmt = $this->db->prepare(
                "SELECT * FROM rooms WHERE id = ?"
            );
            $roomStmt->execute([$roomId]);
            $createdRoom = $roomStmt->fetch(PDO::FETCH_ASSOC);
            
            return json([
                'status' => 'ok',
                'message' => 'Room created successfully',
                'room_id' => $roomId,
                'hotel_id' => $hotelId,
                'hotel_name' => $hotel['name'],
                'room' => $createdRoom
            ], 201);
            
        } catch (PDOException $e) {
            error_log("PDO Exception in create(): " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            error_log("SQL State: " . $e->errorInfo[0]);
            
            return json([
                'error' => 'Database error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ], 500);
        } catch (Exception $e) {
            error_log("General Exception in create(): " . $e->getMessage());
            return json(['error' => 'Failed to create room: ' . $e->getMessage()], 500);
        }
    }
    
    // PATCH /api/rooms/{id} - FIXED: Parameters swapped ($id, $data)
    public function update($id, $data) {
        error_log("=== ROOM UPDATE START ===");
        error_log("Room ID received: " . print_r($id, true));
        error_log("Update data: " . print_r($data, true));
        
        start_session();
        
        if (!isset($_SESSION['uid'])) {
            error_log("Unauthorized: No user session");
            return json(['error' => 'Unauthorized'], 401);
        }
        
        $userId = $_SESSION['uid'];
        $userRole = $_SESSION['role'] ?? '';
        error_log("User ID: $userId, Role: $userRole");
        
        try {
            // Extract ID if it's an array (from router)
            if (is_array($id)) {
                if (isset($id['id'])) {
                    $id = $id['id'];
                } elseif (isset($id[0])) {
                    $id = $id[0];
                }
            }
            
            $roomId = (int)$id;
            error_log("Processing room ID: $roomId");
            
            if ($roomId <= 0) {
                return json(['error' => 'Invalid room ID'], 400);
            }
            
            // Get current room info
            $stmt = $this->db->prepare(
                "SELECT r.*, h.manager_id, h.name as hotel_name 
                 FROM rooms r 
                 JOIN hotels h ON r.hotel_id = h.id 
                 WHERE r.id = ?"
            );
            $stmt->execute([$roomId]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room) {
                error_log("Room ID $roomId not found");
                return json(['error' => 'Room not found'], 404);
            }
            
            error_log("Room found: " . print_r($room, true));
            
            // Check permissions
            $allowed = false;
            if ($userRole === 'admin') {
                $allowed = true;
                error_log("User is admin, permission granted");
            } elseif ($userRole === 'manager' && $room['manager_id'] == $userId) {
                $allowed = true;
                error_log("User is manager of this hotel, permission granted");
            }
            
            if (!$allowed) {
                error_log("Permission denied. User role: $userRole, Hotel manager ID: {$room['manager_id']}");
                return json(['error' => 'Forbidden - You do not have permission to update this room'], 403);
            }
            
            // Build update query
            $updates = [];
            $params = [];
            
            $allowedFields = ['room_type', 'room_number', 'price_per_night', 'capacity', 'status'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    // Additional validation for specific fields
                    if ($field === 'price_per_night' && floatval($data[$field]) <= 0) {
                        return json(['error' => 'Price must be greater than 0'], 400);
                    }
                    
                    if ($field === 'capacity' && intval($data[$field]) <= 0) {
                        return json(['error' => 'Capacity must be at least 1'], 400);
                    }
                    
                    if ($field === 'room_number' && trim($data[$field]) != $room['room_number']) {
                        // Check if new room number already exists for this hotel
                        $roomCheck = $this->db->prepare(
                            "SELECT id FROM rooms 
                             WHERE hotel_id = ? 
                             AND room_number = ? 
                             AND id != ?"
                        );
                        $roomCheck->execute([$room['hotel_id'], trim($data[$field]), $roomId]);
                        
                        if ($roomCheck->fetch()) {
                            return json([
                                'error' => 'Room number already exists for this hotel',
                                'room_number' => $data[$field]
                            ], 409);
                        }
                    }
                    
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                return json(['error' => 'No fields to update'], 400);
            }
            
            // Add updated_at timestamp
            $updates[] = "updated_at = NOW()";
            
            $params[] = $roomId;
            $sql = "UPDATE rooms SET " . implode(', ', $updates) . " WHERE id = ?";
            
            error_log("Update SQL: $sql");
            error_log("Params: " . print_r($params, true));
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $rowsAffected = $stmt->rowCount();
            error_log("Rows affected: $rowsAffected");
            
            // Get updated room info
            $updatedStmt = $this->db->prepare(
                "SELECT * FROM rooms WHERE id = ?"
            );
            $updatedStmt->execute([$roomId]);
            $updatedRoom = $updatedStmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("=== ROOM UPDATE SUCCESS ===");
            
            return json([
                'status' => 'ok',
                'message' => 'Room updated successfully',
                'room_id' => $roomId,
                'hotel_id' => $room['hotel_id'],
                'hotel_name' => $room['hotel_name'],
                'rows_affected' => $rowsAffected,
                'updated_room' => $updatedRoom
            ]);
            
        } catch (PDOException $e) {
            error_log("PDO Exception in update(): " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            error_log("SQL State: " . $e->errorInfo[0]);
            
            return json([
                'error' => 'Database error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ], 500);
        } catch (Exception $e) {
            error_log("General Exception in update(): " . $e->getMessage());
            return json(['error' => 'Failed to update room: ' . $e->getMessage()], 500);
        }
    }
    
    // DELETE /api/rooms/{id}
    public function delete($id) {
        error_log("=== ROOM DELETE START ===");
        error_log("Room ID received: " . print_r($id, true));
        
        start_session();
        
        if (!isset($_SESSION['uid'])) {
            error_log("Unauthorized: No user session");
            return json(['error' => 'Unauthorized'], 401);
        }
        
        $userId = $_SESSION['uid'];
        $userRole = $_SESSION['role'] ?? '';
        error_log("User ID: $userId, Role: $userRole");
        
        try {
            // Extract ID if it's an array (from router)
            if (is_array($id)) {
                if (isset($id['id'])) {
                    $id = $id['id'];
                } elseif (isset($id[0])) {
                    $id = $id[0];
                }
            }
            
            $roomId = (int)$id;
            error_log("Processing room ID: $roomId");
            
            if ($roomId <= 0) {
                return json(['error' => 'Invalid room ID'], 400);
            }
            
            // Get room info to check permissions and verify existence
            $stmt = $this->db->prepare(
                "SELECT r.*, h.manager_id, h.name as hotel_name 
                 FROM rooms r 
                 JOIN hotels h ON r.hotel_id = h.id 
                 WHERE r.id = ?"
            );
            $stmt->execute([$roomId]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room) {
                error_log("Room ID $roomId not found");
                return json(['error' => 'Room not found'], 404);
            }
            
            error_log("Room found: " . print_r($room, true));
            
            // Check permissions
            $allowed = false;
            if ($userRole === 'admin') {
                $allowed = true;
                error_log("User is admin, permission granted");
            } elseif ($userRole === 'manager' && $room['manager_id'] == $userId) {
                $allowed = true;
                error_log("User is manager of this hotel, permission granted");
            }
            
            if (!$allowed) {
                error_log("Permission denied. User role: $userRole, Hotel manager ID: {$room['manager_id']}");
                return json(['error' => 'Forbidden - You do not have permission to delete this room'], 403);
            }
            
            // Check if room has active bookings
            try {
                $bookingsCheck = $this->db->prepare(
                    "SELECT COUNT(*) as booking_count 
                     FROM bookings 
                     WHERE room_id = ? 
                     AND status NOT IN ('cancelled', 'completed')
                     AND check_out_date >= CURDATE()"
                );
                $bookingsCheck->execute([$roomId]);
                $bookings = $bookingsCheck->fetch();
                
                if ($bookings['booking_count'] > 0) {
                    error_log("Room has active bookings: {$bookings['booking_count']}");
                    return json([
                        'error' => 'Cannot delete room with active bookings',
                        'booking_count' => $bookings['booking_count'],
                        'room_id' => $roomId,
                        'room_number' => $room['room_number']
                    ], 400);
                }
            } catch (Exception $e) {
                // If bookings table doesn't exist, continue
                error_log("Bookings check skipped: " . $e->getMessage());
            }
            
            // Delete room availability records first (if table exists)
            try {
                $availStmt = $this->db->prepare("DELETE FROM room_availability WHERE room_id = ?");
                $availStmt->execute([$roomId]);
                $availRows = $availStmt->rowCount();
                error_log("Deleted $availRows availability records for room $roomId");
            } catch (Exception $e) {
                // If room_availability table doesn't exist, continue
                error_log("Availability deletion skipped: " . $e->getMessage());
            }
            
            // Delete the room
            $deleteStmt = $this->db->prepare("DELETE FROM rooms WHERE id = ?");
            $deleteStmt->execute([$roomId]);
            
            $rowsAffected = $deleteStmt->rowCount();
            error_log("Rows affected: $rowsAffected");
            
            if ($rowsAffected === 0) {
                error_log("No rows affected - room may have already been deleted");
                return json(['error' => 'Room could not be deleted or does not exist'], 500);
            }
            
            error_log("=== ROOM DELETE SUCCESS ===");
            
            return json([
                'status' => 'ok',
                'message' => 'Room deleted successfully',
                'room_id' => $roomId,
                'hotel_id' => $room['hotel_id'],
                'hotel_name' => $room['hotel_name'],
                'room_number' => $room['room_number'],
                'room_type' => $room['room_type'],
                'rows_affected' => $rowsAffected
            ]);
            
        } catch (PDOException $e) {
            error_log("PDO Exception in delete(): " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            error_log("SQL State: " . $e->errorInfo[0]);
            
            return json([
                'error' => 'Database error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ], 500);
        } catch (Exception $e) {
            error_log("General Exception in delete(): " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return json(['error' => 'Failed to delete room: ' . $e->getMessage()], 500);
        }
    }
    
    // GET /api/rooms/{id}/availability
    public function availability($id) {
        error_log("=== ROOM AVAILABILITY START ===");
        error_log("Room ID: " . print_r($id, true));
        
        try {
            // Extract ID if it's an array (from router)
            if (is_array($id)) {
                if (isset($id['id'])) {
                    $id = $id['id'];
                } elseif (isset($id[0])) {
                    $id = $id[0];
                }
            }
            
            $roomId = (int)$id;
            error_log("Processing room ID: $roomId");
            
            if ($roomId <= 0) {
                return json(['error' => 'Invalid room ID'], 400);
            }
            
            // Check if room exists
            $roomCheck = $this->db->prepare("SELECT id FROM rooms WHERE id = ?");
            $roomCheck->execute([$roomId]);
            if (!$roomCheck->fetch()) {
                return json(['error' => 'Room not found'], 404);
            }
            
            $stmt = $this->db->prepare(
                "SELECT date, is_available 
                 FROM room_availability 
                 WHERE room_id = ? 
                 AND date >= CURDATE()
                 ORDER BY date"
            );
            $stmt->execute([$roomId]);
            $availability = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return json([
                'status' => 'ok',
                'room_id' => $roomId,
                'count' => count($availability),
                'availability' => $availability
            ]);
        } catch (Exception $e) {
            error_log("Error in availability: " . $e->getMessage());
            return json(['error' => 'Failed to fetch availability: ' . $e->getMessage()], 500);
        }
    }
    
    // POST /api/rooms/availability
    public function setAvailability($data) {
        error_log("=== SET ROOM AVAILABILITY START ===");
        error_log("Data: " . print_r($data, true));
        
        start_session();
        
        if (!isset($_SESSION['uid'])) {
            error_log("Unauthorized: No user session");
            return json(['error' => 'Unauthorized'], 401);
        }
        
        $userId = $_SESSION['uid'];
        $userRole = $_SESSION['role'] ?? '';
        error_log("User ID: $userId, Role: $userRole");
        
        // Only managers and admins can set availability
        if ($userRole !== 'manager' && $userRole !== 'admin') {
            return json(['error' => 'Forbidden - Only managers and admins can set room availability'], 403);
        }
        
        // Validate required fields
        $required = ['room_id', 'date', 'is_available'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return json(['error' => "Missing field: $field"], 400);
            }
        }
        
        $roomId = (int)$data['room_id'];
        $date = $data['date'];
        $isAvailable = filter_var($data['is_available'], FILTER_VALIDATE_BOOLEAN);
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return json(['error' => 'Invalid date format. Use YYYY-MM-DD'], 400);
        }
        
        try {
            // Check if room exists and user has permission
            $roomCheck = $this->db->prepare(
                "SELECT r.id, h.manager_id 
                 FROM rooms r 
                 JOIN hotels h ON r.hotel_id = h.id 
                 WHERE r.id = ?"
            );
            $roomCheck->execute([$roomId]);
            $room = $roomCheck->fetch();
            
            if (!$room) {
                return json(['error' => 'Room not found'], 404);
            }
            
            // If manager, check if they manage this hotel
            if ($userRole === 'manager') {
                if ($room['manager_id'] != $userId) {
                    return json(['error' => 'You can only set availability for rooms in hotels you manage'], 403);
                }
            }
            
            $stmt = $this->db->prepare(
                "INSERT INTO room_availability (room_id, date, is_available)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_available = VALUES(is_available)"
            );
            
            $stmt->execute([
                $roomId,
                $date,
                $isAvailable ? 1 : 0
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            return json([
                'status' => 'ok',
                'message' => 'Availability updated successfully',
                'room_id' => $roomId,
                'date' => $date,
                'is_available' => $isAvailable,
                'rows_affected' => $rowsAffected
            ]);
            
        } catch (Exception $e) {
            error_log("Error in setAvailability: " . $e->getMessage());
            return json(['error' => 'Failed to set availability: ' . $e->getMessage()], 500);
        }
    }
}
?>