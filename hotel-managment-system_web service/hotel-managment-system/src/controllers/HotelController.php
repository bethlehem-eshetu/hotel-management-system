<?php
// src/controllers/HotelController.php
require_once __DIR__ . '/../bootstrap.php';

class HotelController {

    // ---------------- GET /api/hotels ----------------
    public function index() {
        $pdo  = db();
        $city = $_GET['city'] ?? null;

        $sql = "SELECT id, name, city, country, description FROM hotels";
        $params = [];

        if ($city) {
            $sql .= " WHERE city = ?";
            $params[] = $city;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $hotels = $stmt->fetchAll();

        // Attach amenities
        foreach ($hotels as &$hotel) {
            $a = $pdo->prepare(
                "SELECT a.name
                 FROM amenities a
                 JOIN hotel_amenities ha ON ha.amenity_id = a.id
                 WHERE ha.hotel_id = ?"
            );
            $a->execute([$hotel['id']]);
            $hotel['amenities'] = array_column($a->fetchAll(), 'name');
        }

        return json($hotels);
    }

    // ---------------- GET /api/hotels/{id} ----------------
    public function show($id) {
        error_log("HotelController::show() called with: " . print_r($id, true));
        
        // Extract ID if it's an array (from router)
        if (is_array($id)) {
            if (isset($id['id'])) {
                $id = $id['id'];
            } elseif (isset($id[0])) {
                $id = $id[0];
            }
        }
        
        $id = (int)$id;
        error_log("Processing hotel ID: $id");
        
        if ($id <= 0) {
            return json(['error' => 'Invalid hotel ID'], 400);
        }
        
        $pdo = db();

        $stmt = $pdo->prepare(
            "SELECT id, name, city, country, description, address, 
                    contact_phone as phone, email
             FROM hotels WHERE id = ?"
        );
        $stmt->execute([$id]);
        $hotel = $stmt->fetch();

        if (!$hotel) {
            return json(['error' => 'Hotel not found'], 404);
        }

        // Get amenities
        $a = $pdo->prepare(
            "SELECT a.name, a.description
             FROM amenities a
             JOIN hotel_amenities ha ON ha.amenity_id = a.id
             WHERE ha.hotel_id = ?"
        );
        $a->execute([$id]);
        $hotel['amenities'] = $a->fetchAll();

        // Get rooms for this hotel
        try {
            $r = $pdo->prepare(
                "SELECT id, name, type, price_per_night, capacity, description 
                 FROM rooms WHERE hotel_id = ?"
            );
            $r->execute([$id]);
            $hotel['rooms'] = $r->fetchAll();
        } catch (Exception $e) {
            error_log("❌ Error loading rooms: " . $e->getMessage());
            $hotel['rooms'] = [];
        }

        // Calculate price_per_night from the cheapest room or use default
        $hotel['price_per_night'] = 150; // Default
        if (!empty($hotel['rooms'])) {
            $prices = array_column($hotel['rooms'], 'price_per_night');
            if (!empty($prices)) {
                $hotel['price_per_night'] = min($prices);
            }
        }

        // Add location field (combine city and country)
        $hotel['location'] = (isset($hotel['city']) ? $hotel['city'] : '') . ', ' . (isset($hotel['country']) ? $hotel['country'] : '');
        
        // Add area field (use city as area)
        $hotel['area'] = isset($hotel['city']) ? $hotel['city'] : 'Unknown';

        // Return a consistent format that your frontend expects
        return json([
            'status' => 'ok',
            'hotel' => $hotel
        ]);
    }

    // ---------------- POST /api/hotels ----------------
    public function create($data = null) {
        start_session();

        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, ['admin', 'manager'])) {
            return json(['error' => 'Forbidden'], 403);
        }

        // Get data if not passed
        if ($data === null) {
            $data = json_decode(file_get_contents('php://input'), true);
        }

        if (empty($data['name'])) {
            return json(['error' => 'Hotel name is required'], 400);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO hotels
                 (name, description, address, city, country, manager_id, contact_phone, email, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['country'] ?? null,
                $role === 'manager' ? $_SESSION['uid'] : ($data['manager_id'] ?? null),
                $data['contact_phone'] ?? null,
                $data['email'] ?? null
            ]);

            $hotelId = $pdo->lastInsertId();

            // Link amenities
            if (!empty($data['amenities']) && is_array($data['amenities'])) {
                $link = $pdo->prepare(
                    "INSERT IGNORE INTO hotel_amenities (hotel_id, amenity_id)
                     VALUES (?, ?)"
                );
                foreach ($data['amenities'] as $amenityId) {
                    $link->execute([$hotelId, $amenityId]);
                }
            }

            $pdo->commit();
            return json(['status' => 'ok', 'id' => $hotelId], 201);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error creating hotel: " . $e->getMessage());
            return json(['error' => 'Failed to create hotel: ' . $e->getMessage()], 500);
        }
    }

    // ---------------- PATCH /api/hotels/{id} ----------------
    // Updated parameter order: $id first, then $data
    public function update($id, $data = null) {
        error_log("=== HOTEL UPDATE START ===");
        error_log("Received ID: " . print_r($id, true));
        
        start_session();

        $role = $_SESSION['role'] ?? '';
        $uid  = $_SESSION['uid'] ?? null;

        if (!in_array($role, ['admin', 'manager'])) {
            return json(['error' => 'Forbidden'], 403);
        }

        // Extract ID if it's an array (from router)
        if (is_array($id)) {
            if (isset($id['id'])) {
                $id = $id['id'];
            } elseif (isset($id[0])) {
                $id = $id[0];
            }
        }
        
        $id = (int)$id;
        error_log("Processing hotel ID: $id");
        
        if ($id <= 0) {
            return json(['error' => 'Invalid hotel ID'], 400);
        }

        // Get data if not passed
        if ($data === null) {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                return json(['error' => 'Invalid JSON'], 400);
            }
        }

        $pdo = db();

        error_log("Update data: " . print_r($data, true));
        error_log("User ID: $uid");
        error_log("User Role: $role");

        // Managers can update only their hotels
        if ($role === 'manager') {
            $check = $pdo->prepare(
                "SELECT manager_id FROM hotels WHERE id = ?"
            );
            $check->execute([$id]);
            $hotel = $check->fetch();
            
            if (!$hotel) {
                return json(['error' => 'Hotel not found'], 404);
            }
            
            if ($hotel['manager_id'] != $uid) {
                return json(['error' => 'Forbidden - You can only update hotels you manage'], 403);
            }
        }

        // Check if hotel exists
        $hotelCheck = $pdo->prepare("SELECT name FROM hotels WHERE id = ?");
        $hotelCheck->execute([$id]);
        $hotelExists = $hotelCheck->fetch();
        
        if (!$hotelExists) {
            return json(['error' => 'Hotel not found'], 404);
        }

        // Build update query
        $fields = [];
        $params = [];

        // Define allowed fields for update
        $allowedFields = [
            'name', 'description', 'address', 'city', 'country', 
            'contact_phone', 'phone', 'email',
            'amenities_text', 'location', 'area', 'manager_id'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return json(['error' => 'No data to update'], 400);
        }

        // Add updated_at timestamp
        $fields[] = "updated_at = NOW()";
        
        // Add hotel ID to params
        $params[] = $id;
        
        // Build and execute SQL
        $sql = "UPDATE hotels SET " . implode(', ', $fields) . " WHERE id = ?";
        
        error_log("Update SQL: $sql");
        error_log("Params: " . print_r($params, true));

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $rowsAffected = $stmt->rowCount();
            error_log("Rows affected: $rowsAffected");

            // Update amenities if provided
            if (isset($data['amenities']) && is_array($data['amenities'])) {
                // Delete existing amenities
                $pdo->prepare("DELETE FROM hotel_amenities WHERE hotel_id = ?")
                    ->execute([$id]);

                // Insert new amenities
                $link = $pdo->prepare(
                    "INSERT IGNORE INTO hotel_amenities (hotel_id, amenity_id)
                     VALUES (?, ?)"
                );
                foreach ($data['amenities'] as $amenityId) {
                    if (is_numeric($amenityId)) {
                        $link->execute([$id, $amenityId]);
                    }
                }
            }

            // Get updated hotel data
            $updatedHotel = $pdo->prepare(
                "SELECT id, name, city, country, price_per_night FROM hotels WHERE id = ?"
            );
            $updatedHotel->execute([$id]);
            $hotelData = $updatedHotel->fetch();

            error_log("=== HOTEL UPDATE SUCCESS ===");
            
            return json([
                'status' => 'ok',
                'message' => 'Hotel updated successfully',
                'hotel_id' => $id,
                'hotel_name' => $hotelExists['name'],
                'rows_affected' => $rowsAffected,
                'updated_data' => $hotelData
            ]);
            
        } catch (Exception $e) {
            error_log("Error updating hotel: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return json(['error' => 'Failed to update hotel: ' . $e->getMessage()], 500);
        }
    }

    // ---------------- DELETE /api/hotels/{id} ----------------
    public function delete($id) {
        error_log("=== HOTEL DELETE START ===");
        error_log("Received ID: " . print_r($id, true));
        
        start_session();

        $role = $_SESSION['role'] ?? '';
        
        if ($role !== 'admin') {
            return json(['error' => 'Forbidden - Only admins can delete hotels'], 403);
        }

        // Extract ID if it's an array (from router)
        if (is_array($id)) {
            if (isset($id['id'])) {
                $id = $id['id'];
            } elseif (isset($id[0])) {
                $id = $id[0];
            }
        }
        
        $id = (int)$id;
        error_log("Processing hotel ID: $id");
        
        if ($id <= 0) {
            return json(['error' => 'Invalid hotel ID'], 400);
        }

        try {
            $pdo = db();
            
            // Check if hotel exists
            $check = $pdo->prepare("SELECT name FROM hotels WHERE id = ?");
            $check->execute([$id]);
            $hotel = $check->fetch();
            
            if (!$hotel) {
                return json(['error' => 'Hotel not found'], 404);
            }

            $hotelName = $hotel['name'];
            error_log("Hotel found: $hotelName (ID: $id)");

            // Check if hotel has rooms (optional safety check)
            try {
                $roomsCheck = $pdo->prepare("SELECT COUNT(*) as room_count FROM rooms WHERE hotel_id = ?");
                $roomsCheck->execute([$id]);
                $roomsCount = $roomsCheck->fetch();
                
                if ($roomsCount['room_count'] > 0) {
                    return json([
                        'error' => 'Cannot delete hotel with rooms. Delete rooms first.',
                        'room_count' => $roomsCount['room_count']
                    ], 400);
                }
            } catch (Exception $e) {
                // If rooms table doesn't exist, continue with deletion
                error_log("Rooms check skipped: " . $e->getMessage());
            }

            // Check if hotel has bookings (optional safety check)
            try {
                $bookingsCheck = $pdo->prepare(
                    "SELECT COUNT(*) as booking_count FROM bookings WHERE hotel_id = ?"
                );
                $bookingsCheck->execute([$id]);
                $bookingsCount = $bookingsCheck->fetch();
                
                if ($bookingsCount['booking_count'] > 0) {
                    return json([
                        'error' => 'Cannot delete hotel with bookings.',
                        'booking_count' => $bookingsCount['booking_count']
                    ], 400);
                }
            } catch (Exception $e) {
                // If bookings table doesn't exist, continue with deletion
                error_log("Bookings check skipped: " . $e->getMessage());
            }

            // Delete hotel amenities first (if table exists)
            try {
                $pdo->prepare("DELETE FROM hotel_amenities WHERE hotel_id = ?")
                    ->execute([$id]);
                error_log("Deleted hotel amenities for hotel ID: $id");
            } catch (Exception $e) {
                // If hotel_amenities table doesn't exist, continue
                error_log("Hotel amenities deletion skipped: " . $e->getMessage());
            }

            // Delete the hotel
            $stmt = $pdo->prepare("DELETE FROM hotels WHERE id = ?");
            $stmt->execute([$id]);
            
            $rowsAffected = $stmt->rowCount();
            error_log("Rows affected: $rowsAffected");

            if ($rowsAffected === 0) {
                return json(['error' => 'Hotel could not be deleted'], 500);
            }

            error_log("=== HOTEL DELETE SUCCESS ===");
            
            return json([
                'status' => 'ok',
                'message' => 'Hotel deleted successfully',
                'hotel_id' => $id,
                'hotel_name' => $hotelName,
                'rows_affected' => $rowsAffected
            ]);

        } catch (Exception $e) {
            error_log("Error deleting hotel: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return json(['error' => 'Failed to delete hotel: ' . $e->getMessage()], 500);
        }
    }
}
?>