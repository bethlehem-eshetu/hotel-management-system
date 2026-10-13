<?php
// src/controllers/BookingController.php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/ExternalBooking.php';
class BookingController {
    // ---------- GET /api/bookings ----------
    public function index() {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        $pdo  = db();
        $uid  = $_SESSION['uid'];
        $role = $_SESSION['role'];
        if ($role === 'admin') {
            $stmt = $pdo->query(
                "SELECT b.*, h.name as hotel_name, r.room_type,
                        u.name as customer_name, u.email as customer_email
                 FROM bookings b
                 JOIN hotels h ON b.hotel_id = h.id
                 JOIN rooms r ON b.room_id = r.id
                 JOIN users u ON b.customer_id = u.id
                 ORDER BY b.created_at DESC"
            );
            return json($stmt->fetchAll());
        }
        if ($role === 'manager') {
            $stmt = $pdo->prepare(
                "SELECT b.*, h.name as hotel_name, r.room_type,
                        u.name as customer_name, u.email as customer_email
                 FROM bookings b
                 JOIN hotels h ON b.hotel_id = h.id
                 JOIN rooms r ON b.room_id = r.id
                 JOIN users u ON b.customer_id = u.id
                 WHERE h.manager_id = ?
                 ORDER BY b.created_at DESC"
            );
            $stmt->execute([$uid]);
            return json($stmt->fetchAll());
        }
        // customer - with hotel and room details
        $stmt = $pdo->prepare(
            "SELECT b.*, h.name as hotel_name, h.city as hotel_city,
                    r.room_type, r.room_number, r.price_per_night as room_price,
                    h.address as hotel_address, h.contact_phone as hotel_phone,
                    h.email as hotel_email
             FROM bookings b
             JOIN hotels h ON b.hotel_id = h.id
             JOIN rooms r ON b.room_id = r.id
             WHERE b.customer_id = ?
             ORDER BY b.created_at DESC"
        );
        $stmt->execute([$uid]);
        $bookings = $stmt->fetchAll();
        return json($bookings);
    }

    // ---------- GET /api/bookings/{id} ----------
    public function show($id) {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT b.*, h.name as hotel_name, h.address, h.city, h.country,
                    h.contact_phone as hotel_phone, h.email as hotel_email,
                    r.room_type, r.room_number, r.price_per_night,
                    u.name as customer_name, u.email as customer_email, u.phone as customer_phone
             FROM bookings b
             JOIN hotels h ON b.hotel_id = h.id
             JOIN rooms r ON b.room_id = r.id
             JOIN users u ON b.customer_id = u.id
             WHERE b.id = ?"
        );
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            return json(['error' => 'Booking not found'], 404);
        }
        $role = $_SESSION['role'];
        $uid  = $_SESSION['uid'];
        if (
            $role === 'admin' ||
            ($role === 'customer' && $b['customer_id'] == $uid) ||
            ($role === 'manager' && $this->managesHotel($b['hotel_id']))
        ) {
            return json($b);
        }
        return json(['error' => 'Forbidden'], 403);
    }

    // ---------- POST /api/bookings ----------
    public function create() {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        // Required fields
        $roomId   = (int)($data['room_id'] ?? 0);
        $checkIn  = $data['check_in_date'] ?? null;
        $checkOut = $data['check_out_date'] ?? null;
        if (!$roomId || !$checkIn || !$checkOut) {
            return json(['error' => 'Missing required fields: room_id, check_in_date, check_out_date'], 400);
        }
        // Optional fields with defaults
        $adults = (int)($data['adults'] ?? 1);
        $children = (int)($data['children'] ?? 0);
        $specialRequests = $data['special_requests'] ?? '';
        $transactionId = $data['transaction_id'] ?? null;
        $roomType = $data['room_type'] ?? 'Standard';
        $roomNumber = $data['room_number'] ?? '101';
        $start = new DateTime($checkIn);
        $end   = new DateTime($checkOut);
        if ($end <= $start) {
            return json(['error' => 'Invalid date range: check-out must be after check-in'], 400);
        }
        $pdo = db();
        // Room + hotel info
        $stmt = $pdo->prepare(
            "SELECT r.id, r.hotel_id, r.price_per_night, r.capacity,
                    h.manager_id, h.name as hotel_name
             FROM rooms r
             JOIN hotels h ON r.hotel_id = h.id
             WHERE r.id = ? AND r.status = 'available'"
        );
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        if (!$room) {
            return json(['error' => 'Room not available'], 404);
        }
        // Check capacity
        $totalGuests = $adults + $children;
        if ($totalGuests > $room['capacity']) {
            return json(['error' => "Room capacity exceeded. Maximum: {$room['capacity']} guests"], 400);
        }
        // Prevent managers from booking others' hotels
        if (
            $_SESSION['role'] === 'manager' &&
            $room['manager_id'] != $_SESSION['uid']
        ) {
            return json(['error' => 'You can only book rooms in hotels you manage'], 403);
        }
        $dates = $this->dateRange($checkIn, $checkOut);
        if (!$dates) {
            return json(['error' => 'Invalid date range'], 400);
        }
        // Check availability for all dates
        $in = implode(',', array_fill(0, count($dates), '?'));
        $params = array_merge([$roomId], $dates);
        $av = $pdo->prepare(
            "SELECT COUNT(*) AS cnt
             FROM room_availability
             WHERE room_id = ? AND date IN ($in) AND is_available = 1"
        );
        $av->execute($params);
        if ((int)$av->fetch()['cnt'] !== count($dates)) {
            return json(['error' => 'Room not available for selected dates'], 409);
        }
        $nights = count($dates);
        $total  = $nights * (float)$room['price_per_night'];
        $pdo->beginTransaction();
        try {
            // Insert booking with ALL columns
            $stmt = $pdo->prepare(
                "INSERT INTO bookings
                 (customer_id, hotel_id, room_id,
                  check_in_date, check_out_date,
                  adults, children,
                  total_price, special_requests, transaction_id,
                  status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $_SESSION['uid'],
                $room['hotel_id'],
                $roomId,
                $checkIn,
                $checkOut,
                $adults,
                $children,
                $total,
                $specialRequests,
                $transactionId,
                'confirmed'
            ]);
            $bookingId = $pdo->lastInsertId();
            // Mark dates as unavailable
            $stmt = $pdo->prepare(
                "UPDATE room_availability
                 SET is_available = 0
                 WHERE room_id = ? AND date IN ($in)"
            );
            $stmt->execute($params);
            // Log to booking history
            $stmt = $pdo->prepare(
                "INSERT INTO booking_history
                 (booking_id, action, changed_by, note)
                 VALUES (?, 'created', ?, ?)"
            );
            $stmt->execute([
                $bookingId,
                $_SESSION['uid'],
                "Booking created for {$room['hotel_name']}"
            ]);
            $pdo->commit();
            // Return complete booking info with hotel name
            $stmt = $pdo->prepare(
                "SELECT b.*, h.name as hotel_name, r.room_type, r.room_number
                 FROM bookings b
                 JOIN hotels h ON b.hotel_id = h.id
                 JOIN rooms r ON b.room_id = r.id
                 WHERE b.id = ?"
            );
            $stmt->execute([$bookingId]);
            $newBooking = $stmt->fetch();
            return json([
                'status' => 'ok',
                'booking_id' => $bookingId,
                'message' => 'Booking created successfully',
                'booking' => $newBooking
            ], 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Booking failed: " . $e->getMessage());
            return json(['error' => 'Booking failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------- PATCH /api/bookings/{id} ----------
    public function update($id, $data) {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        
        // Data is passed from router
        $newStatus = $data['status'] ?? null;
        $cancellationReason = $data['cancellation_reason'] ?? '';
        
        // Validate status transitions - UPDATED with complete workflow
        $allowedTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['checked_in', 'cancelled'],
            'checked_in' => ['checked_out', 'completed'],
            'checked_out' => ['completed'],
            'completed' => [],
            'cancelled' => []
        ];
        
        $pdo = db();
        
        // Get current booking
        $stmt = $pdo->prepare(
            "SELECT b.*, r.room_number, h.name as hotel_name
             FROM bookings b
             JOIN rooms r ON b.room_id = r.id
             JOIN hotels h ON b.hotel_id = h.id
             WHERE b.id = ?"
        );
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            return json(['error' => 'Booking not found'], 404);
        }
        
        $role = $_SESSION['role'];
        $uid  = $_SESSION['uid'];
        
        // Check permissions
        $allowed = false;
        $note = '';
        
        if ($role === 'admin') {
            $allowed = true;
            $note = 'Changed by admin';
        } elseif ($role === 'manager' && $this->managesHotel($booking['hotel_id'])) {
            $allowed = true;
            $note = 'Changed by hotel manager';
        } elseif ($role === 'customer' && $booking['customer_id'] == $uid) {
            // Customers can only cancel their own bookings
            if ($newStatus === 'cancelled' && $booking['status'] !== 'cancelled') {
                $allowed = true;
                $note = 'Cancelled by customer' . ($cancellationReason ? ': ' . $cancellationReason : '');
            }
        }
        
        if (!$allowed) {
            return json(['error' => 'You do not have permission to modify this booking'], 403);
        }
        
        // Validate status transition
        if ($newStatus && isset($allowedTransitions[$booking['status']])) {
            if (!in_array($newStatus, $allowedTransitions[$booking['status']])) {
                return json([
                    'error' => "Invalid status transition from {$booking['status']} to $newStatus"
                ], 400);
            }
        }
        
        // Check if customer is trying to cancel on or after check-in
        if ($role === 'customer' && $newStatus === 'cancelled') {
            $checkinDate = new DateTime($booking['check_in_date']);
            $today = new DateTime();
            
            if ($checkinDate <= $today) {
                return json(['error' => 'Cannot cancel on or after check-in date'], 400);
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update booking
            $updates = [];
            $params = [];
            
            if ($newStatus) {
                $updates[] = 'status = ?';
                $params[] = $newStatus;
                
                // Set timestamps for specific statuses
                if ($newStatus === 'checked_in') {
                    $updates[] = 'checked_in_at = NOW()';
                } elseif ($newStatus === 'checked_out') {
                    $updates[] = 'checked_out_at = NOW()';
                } elseif ($newStatus === 'completed') {
                    $updates[] = 'completed_at = NOW()';
                }
                
                // Add cancellation reason if provided
                if ($newStatus === 'cancelled' && $cancellationReason) {
                    $updates[] = 'cancellation_reason = ?';
                    $params[] = $cancellationReason;
                    
                    $updates[] = 'cancellation_date = NOW()';
                }
            }
            
            // Removed payment_status update

            
            if (!empty($updates)) {
                $updates[] = 'updated_at = NOW()';
                $params[] = $id;
                
                $sql = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                // Free up room availability if cancelled
                if ($newStatus === 'cancelled' && $booking['status'] !== 'cancelled') {
                    $dates = $this->dateRange($booking['check_in_date'], $booking['check_out_date']);
                    if (!empty($dates)) {
                        $in = implode(',', array_fill(0, count($dates), '?'));
                        $updateParams = array_merge([$booking['room_id']], $dates);
                        
                        $stmt = $pdo->prepare(
                            "UPDATE room_availability
                             SET is_available = 1
                             WHERE room_id = ? AND date IN ($in)"
                        );
                        $stmt->execute($updateParams);
                    }
                }
                
                // Log to history
                $action = $newStatus ?? 'modified';
                
                $stmt = $pdo->prepare(
                    "INSERT INTO booking_history
                     (booking_id, action, old_status, new_status, changed_by, note)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $id,
                    $action,
                    $booking['status'],
                    $newStatus,
                    $uid,
                    $note
                ]);
            }
            
            $pdo->commit();
            
            return json([
                'status' => 'ok',
                'message' => 'Booking updated successfully',
                'booking_id' => $id
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Update failed: " . $e->getMessage());
            return json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------- PATCH /api/bookings/{id}/status ----------
    public function updateStatus($id, $data) {
        start_session();
        
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        
        // Only allow status-related fields
        $allowedFields = ['status', 'cancellation_reason'];
        $updateData = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (empty($updateData)) {
            return json(['error' => 'No valid status fields provided for update'], 400);
        }
        
        $pdo = db();
        
        // Get current booking to check permissions
        $stmt = $pdo->prepare(
            "SELECT b.*, h.name as hotel_name
             FROM bookings b
             JOIN hotels h ON b.hotel_id = h.id
             WHERE b.id = ?"
        );
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            return json(['error' => 'Booking not found'], 404);
        }
        
        $role = $_SESSION['role'];
        $uid  = $_SESSION['uid'];
        
        // Check permissions
        $allowed = false;
        
        if ($role === 'admin') {
            $allowed = true;
        } elseif ($role === 'manager' && $this->managesHotel($booking['hotel_id'])) {
            $allowed = true;
        } elseif ($role === 'customer' && $booking['customer_id'] == $uid) {
            // Customers can only cancel their own bookings
            if (isset($updateData['status']) && $updateData['status'] === 'cancelled') {
                $allowed = true;
            }
        }
        
        if (!$allowed) {
            return json(['error' => 'You do not have permission to update this booking status'], 403);
        }
        
        // Validate status transitions - UPDATED with complete workflow
        $allowedTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['checked_in', 'cancelled'],
            'checked_in' => ['checked_out', 'completed'],
            'checked_out' => ['completed'],
            'completed' => [],
            'cancelled' => []
        ];
        
        if (isset($updateData['status']) && isset($allowedTransitions[$booking['status']])) {
            if (!in_array($updateData['status'], $allowedTransitions[$booking['status']])) {
                return json([
                    'error' => "Invalid status transition from {$booking['status']} to {$updateData['status']}"
                ], 400);
            }
        }
        
        // Check if customer is trying to cancel on or after check-in
        if ($role === 'customer' && isset($updateData['status']) && $updateData['status'] === 'cancelled') {
            $checkinDate = new DateTime($booking['check_in_date']);
            $today = new DateTime();
            
            if ($checkinDate <= $today) {
                return json(['error' => 'Cannot cancel on or after check-in date'], 400);
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Build update query
            $updates = [];
            $params = [];
            
            if (isset($updateData['status'])) {
                $updates[] = 'status = ?';
                $params[] = $updateData['status'];
                
                // Set timestamps for specific statuses
                if ($updateData['status'] === 'checked_in') {
                    $updates[] = 'checked_in_at = NOW()';
                } elseif ($updateData['status'] === 'checked_out') {
                    $updates[] = 'checked_out_at = NOW()';
                } elseif ($updateData['status'] === 'completed') {
                    $updates[] = 'completed_at = NOW()';
                }
                
                // Add cancellation reason if provided
                if ($updateData['status'] === 'cancelled' && isset($updateData['cancellation_reason'])) {
                    $updates[] = 'cancellation_reason = ?';
                    $params[] = $updateData['cancellation_reason'];
                    $updates[] = 'cancellation_date = NOW()';
                }
            }
            
            // Removed payment_status update

            
            if (!empty($updates)) {
                $updates[] = 'updated_at = NOW()';
                $params[] = $id;
                
                $sql = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                // Free up room availability if cancelled
                if (isset($updateData['status']) && $updateData['status'] === 'cancelled' && $booking['status'] !== 'cancelled') {
                    $dates = $this->dateRange($booking['check_in_date'], $booking['check_out_date']);
                    if (!empty($dates)) {
                        $in = implode(',', array_fill(0, count($dates), '?'));
                        $updateParams = array_merge([$booking['room_id']], $dates);
                        
                        $stmt = $pdo->prepare(
                            "UPDATE room_availability
                             SET is_available = 1
                             WHERE room_id = ? AND date IN ($in)"
                        );
                        $stmt->execute($updateParams);
                    }
                }
                
                // Log to history
                $action = isset($updateData['status']) ? $updateData['status'] : 'modified';
                
                $note = "Status updated by {$role}";
                if (isset($updateData['cancellation_reason'])) {
                    $note .= ": " . $updateData['cancellation_reason'];
                }
                
                $stmt = $pdo->prepare(
                    "INSERT INTO booking_history
                     (booking_id, action, old_status, new_status, changed_by, note)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $id,
                    $action,
                    $booking['status'],
                    $updateData['status'] ?? $booking['status'],
                    $uid,
                    $note
                ]);
            }
            
            $pdo->commit();
            
            // Get updated booking
            $stmt = $pdo->prepare(
                "SELECT b.*, h.name as hotel_name, r.room_number
                 FROM bookings b
                 JOIN hotels h ON b.hotel_id = h.id
                 JOIN rooms r ON b.room_id = r.id
                 WHERE b.id = ?"
            );
            $stmt->execute([$id]);
            $updatedBooking = $stmt->fetch();
            
            return json([
                'status' => 'ok',
                'message' => 'Booking status updated successfully',
                'booking_id' => $id,
                'booking' => $updatedBooking
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Status update failed: " . $e->getMessage());
            return json(['error' => 'Status update failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------- DELETE /api/bookings/{id} ----------
    public function delete($id) {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        $pdo = db();
        
        // Get booking info
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            return json(['error' => 'Booking not found'], 404);
        }
        $role = $_SESSION['role'];
        $uid  = $_SESSION['uid'];
        // Check permissions (only admin or manager of the hotel)
        $allowed = $role === 'admin' ||
                  ($role === 'manager' && $this->managesHotel($booking['hotel_id']));
        if (!$allowed) {
            return json(['error' => 'You do not have permission to delete this booking'], 403);
        }
        try {
            // Bookings are typically not deleted, but cancelled
            // Instead of DELETE, we should cancel it
            $stmt = $pdo->prepare(
                "UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$id]);
            
            // Free up room availability
            $dates = $this->dateRange($booking['check_in_date'], $booking['check_out_date']);
            if (!empty($dates)) {
                $in = implode(',', array_fill(0, count($dates), '?'));
                $params = array_merge([$booking['room_id']], $dates);
                
                $stmt = $pdo->prepare(
                    "UPDATE room_availability
                     SET is_available = 1
                     WHERE room_id = ? AND date IN ($in)"
                );
                $stmt->execute($params);
            }
            
            // Log to history
            $stmt = $pdo->prepare(
                "INSERT INTO booking_history
                 (booking_id, action, changed_by, note)
                 VALUES (?, 'cancelled', ?, 'Deleted by {$role}')"
            );
            $stmt->execute([$id, $uid]);
            
            return json([
                'status' => 'ok',
                'message' => 'Booking cancelled successfully'
            ]);
        } catch (Exception $e) {
            error_log("Delete failed: " . $e->getMessage());
            return json(['error' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------- POST /api/bookings/{id}/cancel ----------
    public function cancel($id) {
        start_session();
        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $reason = $data['reason'] ?? '';
        $pdo = db();
        
        // Get booking
        $stmt = $pdo->prepare(
            "SELECT b.*, h.name as hotel_name
             FROM bookings b
             JOIN hotels h ON b.hotel_id = h.id
             WHERE b.id = ? AND b.customer_id = ?"
        );
        $stmt->execute([$id, $_SESSION['uid']]);
        $booking = $stmt->fetch();
        if (!$booking) {
            return json(['error' => 'Booking not found or not yours'], 404);
        }
        // Check if already cancelled
        if ($booking['status'] === 'cancelled') {
            return json(['error' => 'Booking already cancelled'], 400);
        }
        // Check if can be cancelled (not on or after check-in)
        $checkinDate = new DateTime($booking['check_in_date']);
        $today = new DateTime();
        
        if ($checkinDate <= $today) {
            return json(['error' => 'Cannot cancel on or after check-in date'], 400);
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update booking status
            $stmt = $pdo->prepare(
                "UPDATE bookings
                 SET status = 'cancelled',
                     cancellation_reason = ?,
                     cancellation_date = NOW(),
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$reason, $id]);
            // Free up room availability
            $dates = $this->dateRange($booking['check_in_date'], $booking['check_out_date']);
            if (!empty($dates)) {
                $in = implode(',', array_fill(0, count($dates), '?'));
                $params = array_merge([$booking['room_id']], $dates);
                
                $stmt = $pdo->prepare(
                    "UPDATE room_availability
                     SET is_available = 1
                     WHERE room_id = ? AND date IN ($in)"
                );
                $stmt->execute($params);
            }
            // Log to history
            $stmt = $pdo->prepare(
                "INSERT INTO booking_history
                 (booking_id, action, old_status, new_status, changed_by, note)
                 VALUES (?, 'cancelled', ?, 'cancelled', ?, ?)"
            );
            $stmt->execute([
                $id,
                'cancelled',
                $booking['status'],
                'cancelled',
                $_SESSION['uid'],
                $reason ? "Cancelled: $reason" : "Cancelled by customer"
            ]);
            $pdo->commit();
            
            return json([
                'status' => 'ok',
                'message' => 'Booking cancelled successfully',
                'booking_id' => $id
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Cancel failed: " . $e->getMessage());
            return json(['error' => 'Cancellation failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------- HELPERS ----------
    private function managesHotel($hotelId): bool {
        $stmt = db()->prepare(
            "SELECT 1 FROM hotels WHERE id = ? AND manager_id = ?"
        );
        $stmt->execute([$hotelId, $_SESSION['uid']]);
        return (bool)$stmt->fetch();
    }

    private function dateRange($start, $end): array {
        $dates = [];
        try {
            $s = new DateTime($start);
            $e = new DateTime($end);
            
            if ($s >= $e) {
                return $dates;
            }
            
            while ($s < $e) {
                $dates[] = $s->format('Y-m-d');
                $s->modify('+1 day');
            }
        } catch (Exception $e) {
            error_log("Date range error: " . $e->getMessage());
        }
        return $dates;
    }

    // ---------- Quick Status Action Methods ----------
    
    // Quick check-in method
    public function quickCheckIn($id) {
        return $this->updateStatus($id, ['status' => 'checked_in']);
    }
    
    // Quick check-out method
    public function quickCheckOut($id) {
        return $this->updateStatus($id, ['status' => 'checked_out']);
    }
    
    // Quick complete method
    public function quickComplete($id) {
        return $this->updateStatus($id, ['status' => 'completed']);
    }

    // ---------- Test method ----------
    public function test() {
        return json(['message' => 'BookingController test OK']);
    }
}
?>