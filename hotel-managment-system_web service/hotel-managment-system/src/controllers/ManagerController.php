<?php
// src/controllers/ManagerController.php
require_once __DIR__ . '/../bootstrap.php';

class ManagerController {

    // ---------------- GET /api/manager/dashboard ----------------
    public function dashboard() {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        
        // Get manager's hotels
        $hotels = $pdo->prepare(
            "SELECT id, name, city, country, created_at 
             FROM hotels WHERE manager_id = ?"
        );
        $hotels->execute([$uid]);
        $hotelsList = $hotels->fetchAll();
        
        $dashboardData = [
            'hotels' => [],
            'summary' => [
                'total_hotels' => 0,
                'total_rooms' => 0,
                'total_bookings' => 0,
                'total_revenue' => 0,
                'pending_requests' => 0
            ]
        ];
        
        foreach ($hotelsList as $hotel) {
            $hotelId = $hotel['id'];
            
            // Hotel stats
            $stats = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM rooms WHERE hotel_id = ?) as total_rooms,
                    (SELECT COUNT(*) FROM bookings WHERE hotel_id = ? AND status = 'confirmed') as active_bookings,
                    (SELECT COUNT(*) FROM bookings WHERE hotel_id = ? AND status = 'pending') as pending_bookings,
                    (SELECT COALESCE(SUM(total_price), 0) FROM bookings 
                     WHERE hotel_id = ? AND status = 'completed' 
                     AND MONTH(check_in_date) = MONTH(CURDATE())) as monthly_revenue
            ");
            $stats->execute([$hotelId, $hotelId, $hotelId, $hotelId]);
            $hotelStats = $stats->fetch();
            
            // Recent bookings
            $recentBookings = $pdo->prepare("
                SELECT b.id, b.check_in_date, b.check_out_date, b.total_price, b.status,
                       u.name as customer_name, r.room_number
                FROM bookings b
                JOIN users u ON b.customer_id = u.id
                JOIN rooms r ON b.room_id = r.id
                WHERE b.hotel_id = ?
                ORDER BY b.created_at DESC
                LIMIT 5
            ");
            $recentBookings->execute([$hotelId]);
            
            // Room occupancy
            $occupancy = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT r.id) as total_rooms,
                    COUNT(DISTINCT CASE WHEN b.status = 'confirmed' AND 
                        CURDATE() BETWEEN b.check_in_date AND b.check_out_date 
                        THEN b.room_id END) as occupied_rooms
                FROM rooms r
                LEFT JOIN bookings b ON r.id = b.room_id
                WHERE r.hotel_id = ?
            ");
            $occupancy->execute([$hotelId]);
            $occupancyData = $occupancy->fetch();
            
            $occupancyRate = $occupancyData['total_rooms'] > 0 
                ? round(($occupancyData['occupied_rooms'] / $occupancyData['total_rooms']) * 100, 2)
                : 0;
            
            $hotelData = [
                'id' => $hotelId,
                'name' => $hotel['name'],
                'city' => $hotel['city'],
                'country' => $hotel['country'],
                'stats' => [
                    'total_rooms' => (int)$hotelStats['total_rooms'],
                    'active_bookings' => (int)$hotelStats['active_bookings'],
                    'pending_bookings' => (int)$hotelStats['pending_bookings'],
                    'monthly_revenue' => (float)$hotelStats['monthly_revenue'],
                    'occupancy_rate' => $occupancyRate
                ],
                'recent_bookings' => $recentBookings->fetchAll()
            ];
            
            $dashboardData['hotels'][] = $hotelData;
            
            // Update summary
            $dashboardData['summary']['total_hotels']++;
            $dashboardData['summary']['total_rooms'] += (int)$hotelStats['total_rooms'];
            $dashboardData['summary']['total_bookings'] += (int)$hotelStats['active_bookings'];
            $dashboardData['summary']['total_revenue'] += (float)$hotelStats['monthly_revenue'];
            $dashboardData['summary']['pending_requests'] += (int)$hotelStats['pending_bookings'];
        }
        
        return json([
            'status' => 'ok',
            'manager_id' => $uid,
            'data' => $dashboardData
        ]);
    }

    // ---------------- GET /api/manager/hotels ----------------
    public function myHotels() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT h.*, 
                   (SELECT COUNT(*) FROM rooms WHERE hotel_id = h.id) as room_count,
                   (SELECT COUNT(*) FROM bookings WHERE hotel_id = h.id 
                    AND status = 'confirmed' 
                    AND CURDATE() BETWEEN check_in_date AND check_out_date) as current_guests
            FROM hotels h
            WHERE h.manager_id = ?
            ORDER BY h.created_at DESC
        ");
        $stmt->execute([$_SESSION['uid']]);
        
        $hotels = $stmt->fetchAll();
        
        return json([
            'status' => 'ok',
            'hotels' => $hotels
        ]);
    }

    // ---------------- GET /api/manager/hotels/{hotelId}/performance ----------------
    public function hotelPerformance($hotelId) {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        
        // Verify manager owns this hotel
        $check = $pdo->prepare(
            "SELECT 1 FROM hotels WHERE id = ? AND manager_id = ?"
        );
        $check->execute([$hotelId, $uid]);
        
        if (!$check->fetch()) {
            return json(['error' => 'Forbidden'], 403);
        }
        
        // Performance metrics for last 6 months
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(b.check_in_date, '%Y-%m') as month,
                COUNT(DISTINCT b.id) as bookings_count,
                COALESCE(SUM(b.total_price), 0) as revenue,
                COUNT(DISTINCT b.customer_id) as unique_customers,
                AVG(DATEDIFF(b.check_out_date, b.check_in_date)) as avg_stay_length
            FROM bookings b
            WHERE b.hotel_id = ? 
                AND b.check_in_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                AND b.status IN ('confirmed', 'completed')
            GROUP BY month
            ORDER BY month DESC
        ");
        $stmt->execute([$hotelId]);
        
        $monthlyData = $stmt->fetchAll();
        
        // Room type performance
        $roomPerformance = $pdo->prepare("
            SELECT 
                r.room_type,
                COUNT(DISTINCT b.id) as bookings_count,
                COALESCE(SUM(b.total_price), 0) as revenue,
                ROUND(COUNT(DISTINCT b.id) * 100.0 / 
                      (SELECT COUNT(*) FROM bookings WHERE hotel_id = ?), 2) as percentage
            FROM rooms r
            LEFT JOIN bookings b ON r.id = b.room_id AND b.status IN ('confirmed', 'completed')
            WHERE r.hotel_id = ?
            GROUP BY r.room_type
            ORDER BY bookings_count DESC
        ");
        $roomPerformance->execute([$hotelId, $hotelId]);
        
        // Customer demographics
        $demographics = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT u.id) as total_customers,
                COUNT(DISTINCT CASE WHEN b.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                    THEN u.id END) as repeat_customers_last_month,
                COUNT(DISTINCT CASE WHEN b.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) 
                    THEN u.id END) as repeat_customers_last_quarter
            FROM bookings b
            JOIN users u ON b.customer_id = u.id
            WHERE b.hotel_id = ? AND b.status IN ('confirmed', 'completed')
        ");
        $demographics->execute([$hotelId]);
        
        return json([
            'status' => 'ok',
            'hotel_id' => $hotelId,
            'monthly_performance' => $monthlyData,
            'room_type_performance' => $roomPerformance->fetchAll(),
            'customer_demographics' => $demographics->fetch(),
            'timeframe' => 'last_6_months'
        ]);
    }

    // ---------------- GET /api/manager/requests/pending ----------------
    public function pendingRequests() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT 
                br.id as request_id,
                br.booking_id,
                br.request_type,
                br.details,
                br.status as request_status,
                br.created_at as request_date,
                b.customer_id,
                b.check_in_date,
                b.check_out_date,
                b.total_price,
                u.name as customer_name,
                u.email as customer_email,
                r.room_number,
                h.name as hotel_name
            FROM booking_requests br
            JOIN bookings b ON br.booking_id = b.id
            JOIN users u ON b.customer_id = u.id
            JOIN rooms r ON b.room_id = r.id
            JOIN hotels h ON b.hotel_id = h.id
            WHERE h.manager_id = ? 
                AND br.status = 'pending'
            ORDER BY br.created_at DESC
        ");
        $stmt->execute([$uid]);
        
        return json([
            'status' => 'ok',
            'requests' => $stmt->fetchAll()
        ]);
    }

    // ---------------- PATCH /api/manager/requests/{requestId} ----------------
    public function handleRequest($requestId) {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? ''; // 'approve' or 'reject'
        $note = $data['note'] ?? '';
        
        if (!in_array($action, ['approve', 'reject'])) {
            return json(['error' => 'Invalid action'], 400);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        // Get request details and verify ownership
        $stmt = $pdo->prepare("
            SELECT br.*, h.manager_id
            FROM booking_requests br
            JOIN bookings b ON br.booking_id = b.id
            JOIN hotels h ON b.hotel_id = h.id
            WHERE br.id = ? AND h.manager_id = ?
        ");
        $stmt->execute([$requestId, $uid]);
        $request = $stmt->fetch();
        
        if (!$request) {
            return json(['error' => 'Request not found or access denied'], 404);
        }
        
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        
        $pdo->beginTransaction();
        try {
            // Update request status
            $update = $pdo->prepare("
                UPDATE booking_requests 
                SET status = ?, processed_at = NOW(), manager_note = ?
                WHERE id = ?
            ");
            $update->execute([$newStatus, $note, $requestId]);
            
            // Log action
            $log = $pdo->prepare("
                INSERT INTO manager_actions 
                (manager_id, action_type, target_id, details, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $log->execute([
                $uid,
                'request_' . $action,
                $requestId,
                json_encode(['request_type' => $request['request_type'], 'note' => $note])
            ]);
            
            $pdo->commit();
            
            return json([
                'status' => 'ok',
                'message' => "Request {$action}d successfully",
                'request_id' => $requestId,
                'new_status' => $newStatus
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            return json(['error' => 'Failed to process request'], 500);
        }
    }

    // ---------------- GET /api/manager/staff ----------------
    public function staff() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT 
                hs.*,
                u.name,
                u.email,
                u.phone,
                h.name as hotel_name,
                h.id as hotel_id
            FROM hotel_staff hs
            JOIN users u ON hs.user_id = u.id
            JOIN hotels h ON hs.hotel_id = h.id
            WHERE h.manager_id = ?
            ORDER BY hs.role, u.name
        ");
        $stmt->execute([$uid]);
        
        $staff = $stmt->fetchAll();
        
        // Decode permissions JSON
        foreach ($staff as &$member) {
            if (!empty($member['permissions'])) {
                $member['permissions'] = json_decode($member['permissions'], true);
            }
        }
        
        return json([
            'status' => 'ok',
            'count' => count($staff),
            'staff' => $staff
        ]);
    }

    // ---------------- POST /api/manager/staff ----------------
    public function addStaff() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['user_id', 'hotel_id', 'role', 'department'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return json(['error' => "Missing field: {$field}"], 400);
            }
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        // Verify manager owns the hotel
        $check = $pdo->prepare(
            "SELECT 1 FROM hotels WHERE id = ? AND manager_id = ?"
        );
        $check->execute([$data['hotel_id'], $uid]);
        
        if (!$check->fetch()) {
            return json(['error' => 'Forbidden'], 403);
        }
        
        // Check if staff already exists
        $exists = $pdo->prepare(
            "SELECT 1 FROM hotel_staff WHERE user_id = ? AND hotel_id = ?"
        );
        $exists->execute([$data['user_id'], $data['hotel_id']]);
        
        if ($exists->fetch()) {
            return json(['error' => 'Staff member already assigned to this hotel'], 409);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO hotel_staff 
            (user_id, hotel_id, role, department, permissions, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $permissions = json_encode($data['permissions'] ?? []);
        
        $stmt->execute([
            $data['user_id'],
            $data['hotel_id'],
            $data['role'],
            $data['department'],
            $permissions
        ]);
        
        return json([
            'status' => 'ok',
            'message' => 'Staff member added successfully',
            'staff_id' => $pdo->lastInsertId()
        ], 201);
    }

    // ---------------- PATCH /api/manager/staff/{id} ----------------
    public function updateStaff($staffId, $data = null) {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        // If $data is null, get it from input
        if ($data === null) {
            $data = json_decode(file_get_contents('php://input'), true);
        }
        
        if (empty($data)) {
            return json(['error' => 'No data provided'], 400);
        }
        
        $pdo = db();
        
        // Verify manager owns this staff member
        $check = $pdo->prepare("
            SELECT hs.id 
            FROM hotel_staff hs
            JOIN hotels h ON hs.hotel_id = h.id
            WHERE hs.id = ? AND h.manager_id = ?
        ");
        $check->execute([$staffId, $uid]);
        
        if (!$check->fetch()) {
            return json(['error' => 'Staff member not found or access denied'], 404);
        }
        
        // Build update query
        $fields = [];
        $params = [];
        
        if (isset($data['role'])) {
            $fields[] = 'role = ?';
            $params[] = $data['role'];
        }
        
        if (isset($data['department'])) {
            $fields[] = 'department = ?';
            $params[] = $data['department'];
        }
        
        if (isset($data['permissions'])) {
            $fields[] = 'permissions = ?';
            $params[] = json_encode($data['permissions']);
        }
        
        if (empty($fields)) {
            return json(['error' => 'No data to update'], 400);
        }
        
        $params[] = $staffId;
        
        try {
            $stmt = $pdo->prepare("
                UPDATE hotel_staff 
                SET " . implode(', ', $fields) . ", updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute($params);
            
            // Get updated staff data
            $updated = $pdo->prepare("
                SELECT hs.*, u.name, u.email, h.name as hotel_name
                FROM hotel_staff hs
                JOIN users u ON hs.user_id = u.id
                JOIN hotels h ON hs.hotel_id = h.id
                WHERE hs.id = ?
            ");
            $updated->execute([$staffId]);
            $staffData = $updated->fetch();
            
            return json([
                'status' => 'ok',
                'message' => 'Staff member updated successfully',
                'staff' => $staffData
            ]);
            
        } catch (Exception $e) {
            return json(['error' => 'Failed to update staff: ' . $e->getMessage()], 500);
        }
    }

    // ---------------- DELETE /api/manager/staff/{id} ----------------
    public function deleteStaff($staffId, $data = null) {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        
        // Verify manager owns this staff member
        $check = $pdo->prepare("
            SELECT hs.id 
            FROM hotel_staff hs
            JOIN hotels h ON hs.hotel_id = h.id
            WHERE hs.id = ? AND h.manager_id = ?
        ");
        $check->execute([$staffId, $uid]);
        
        if (!$check->fetch()) {
            return json(['error' => 'Staff member not found or access denied'], 404);
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM hotel_staff WHERE id = ?");
            $stmt->execute([$staffId]);
            
            if ($stmt->rowCount() > 0) {
                return json([
                    'status' => 'ok',
                    'message' => 'Staff member deleted successfully'
                ]);
            } else {
                return json(['error' => 'Staff member not found'], 404);
            }
            
        } catch (Exception $e) {
            return json(['error' => 'Failed to delete staff: ' . $e->getMessage()], 500);
        }
    }

    // ---------------- GET /api/manager/reports/daily ----------------
    public function dailyReport() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        // Get manager's hotels
        $hotels = $pdo->prepare(
            "SELECT id, name FROM hotels WHERE manager_id = ?"
        );
        $hotels->execute([$uid]);
        $hotelsList = $hotels->fetchAll();
        
        $report = [
            'date' => $date,
            'hotels' => []
        ];
        
        foreach ($hotelsList as $hotel) {
            $hotelId = $hotel['id'];
            
            // Today's check-ins
            $checkins = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM bookings 
                WHERE hotel_id = ? 
                    AND DATE(check_in_date) = ?
                    AND status = 'confirmed'
            ");
            $checkins->execute([$hotelId, $date]);
            
            // Today's check-outs
            $checkouts = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM bookings 
                WHERE hotel_id = ? 
                    AND DATE(check_out_date) = ?
                    AND status = 'confirmed'
            ");
            $checkouts->execute([$hotelId, $date]);
            
            // Today's revenue
            $revenue = $pdo->prepare("
                SELECT COALESCE(SUM(total_price), 0) as revenue
                FROM bookings 
                WHERE hotel_id = ? 
                    AND DATE(created_at) = ?
                    AND status IN ('confirmed', 'completed')
            ");
            $revenue->execute([$hotelId, $date]);
            
            // Occupancy for the date
            $occupancy = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT r.id) as total_rooms,
                    COUNT(DISTINCT b.room_id) as occupied_rooms
                FROM rooms r
                LEFT JOIN bookings b ON r.id = b.room_id 
                    AND ? BETWEEN b.check_in_date AND b.check_out_date
                    AND b.status = 'confirmed'
                WHERE r.hotel_id = ?
            ");
            $occupancy->execute([$date, $hotelId]);
            $occData = $occupancy->fetch();
            
            $occupancyRate = $occData['total_rooms'] > 0 
                ? round(($occData['occupied_rooms'] / $occData['total_rooms']) * 100, 2)
                : 0;
            
            $report['hotels'][] = [
                'id' => $hotelId,
                'name' => $hotel['name'],
                'checkins_today' => (int)$checkins->fetch()['count'],
                'checkouts_today' => (int)$checkouts->fetch()['count'],
                'revenue_today' => (float)$revenue->fetch()['revenue'],
                'occupancy_rate' => $occupancyRate,
                'total_rooms' => (int)$occData['total_rooms'],
                'occupied_rooms' => (int)$occData['occupied_rooms']
            ];
        }
        
        return json([
            'status' => 'ok',
            'report' => $report
        ]);
    }

    // ---------------- GET /api/manager/total-rooms ----------------
    public function totalRooms() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(r.id) as total_rooms
            FROM rooms r
            JOIN hotels h ON r.hotel_id = h.id
            WHERE h.manager_id = ?
        ");
        $stmt->execute([$uid]);
        
        $result = $stmt->fetch();
        
        return json([
            'status' => 'ok',
            'total_rooms' => (int)$result['total_rooms'] || 0
        ]);
    }

    // ---------------- GET /api/manager/bookings/today ----------------
    public function todaysBookings() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(b.id) as count
            FROM bookings b
            JOIN hotels h ON b.hotel_id = h.id
            WHERE h.manager_id = ? 
                AND DATE(b.check_in_date) = ?
        ");
        $stmt->execute([$uid, $date]);
        
        $result = $stmt->fetch();
        
        return json([
            'status' => 'ok',
            'date' => $date,
            'count' => (int)$result['count'] || 0
        ]);
    }

    // ---------------- GET /api/manager/bookings ----------------
    public function myBookings() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT 
                b.*,
                u.name as customer_name,
                u.email as customer_email,
                r.room_number,
                r.room_type,
                h.name as hotel_name,
                h.city as hotel_city
            FROM bookings b
            JOIN users u ON b.customer_id = u.id
            JOIN rooms r ON b.room_id = r.id
            JOIN hotels h ON b.hotel_id = h.id
            WHERE h.manager_id = ?
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$uid]);
        
        return json([
            'status' => 'ok',
            'bookings' => $stmt->fetchAll()
        ]);
    }

    // ---------------- GET /api/manager/reviews ----------------
    public function myReviews() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT 
                rv.*,
                u.name as customer_name,
                h.name as hotel_name,
                h.city as hotel_city
            FROM reviews rv
            JOIN users u ON rv.customer_id = u.id
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE h.manager_id = ?
            ORDER BY rv.created_at DESC
        ");
        $stmt->execute([$uid]);
        
        return json([
            'status' => 'ok',
            'reviews' => $stmt->fetchAll()
        ]);
    }

    // ---------------- GET /api/manager/reports/overview ----------------
    public function reportsOverview() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $period = $_GET['period'] ?? 'monthly'; // daily, weekly, monthly, yearly
        $date = $_GET['date'] ?? date('Y-m-d');
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        // Get manager's hotels
        $hotels = $pdo->prepare(
            "SELECT id, name FROM hotels WHERE manager_id = ?"
        );
        $hotels->execute([$uid]);
        $hotelsList = $hotels->fetchAll();
        
        $overview = [
            'period' => $period,
            'date' => $date,
            'summary' => [
                'total_hotels' => count($hotelsList),
                'total_revenue' => 0,
                'total_bookings' => 0,
                'average_occupancy' => 0,
                'pending_requests' => 0
            ],
            'hotels' => []
        ];
        
        foreach ($hotelsList as $hotel) {
            $hotelId = $hotel['id'];
            
            // Revenue based on period
            $revenueSql = "
                SELECT COALESCE(SUM(total_price), 0) as revenue
                FROM bookings 
                WHERE hotel_id = ? AND status IN ('confirmed', 'completed')
            ";
            
            $bookingSql = "
                SELECT COUNT(*) as count
                FROM bookings 
                WHERE hotel_id = ? AND status = 'confirmed'
            ";
            
            switch ($period) {
                case 'daily':
                    $revenueSql .= " AND DATE(created_at) = ?";
                    $bookingSql .= " AND DATE(created_at) = ?";
                    $params = [$hotelId, $date];
                    break;
                case 'weekly':
                    $revenueSql .= " AND YEARWEEK(created_at, 1) = YEARWEEK(?, 1)";
                    $bookingSql .= " AND YEARWEEK(created_at, 1) = YEARWEEK(?, 1)";
                    $params = [$hotelId, $date];
                    break;
                case 'monthly':
                    $revenueSql .= " AND MONTH(created_at) = MONTH(?) AND YEAR(created_at) = YEAR(?)";
                    $bookingSql .= " AND MONTH(created_at) = MONTH(?) AND YEAR(created_at) = YEAR(?)";
                    $params = [$hotelId, $date, $date];
                    break;
                case 'yearly':
                    $revenueSql .= " AND YEAR(created_at) = YEAR(?)";
                    $bookingSql .= " AND YEAR(created_at) = YEAR(?)";
                    $params = [$hotelId, $date];
                    break;
                default:
                    $params = [$hotelId];
            }
            
            $revenueStmt = $pdo->prepare($revenueSql);
            $revenueStmt->execute($params);
            $revenue = $revenueStmt->fetch();
            
            $bookingStmt = $pdo->prepare($bookingSql);
            $bookingStmt->execute($params);
            $bookings = $bookingStmt->fetch();
            
            // Occupancy
            $occupancy = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT r.id) as total_rooms,
                    COUNT(DISTINCT b.room_id) as occupied_rooms
                FROM rooms r
                LEFT JOIN bookings b ON r.id = b.room_id 
                    AND ? BETWEEN b.check_in_date AND b.check_out_date
                    AND b.status = 'confirmed'
                WHERE r.hotel_id = ?
            ");
            $occupancy->execute([$date, $hotelId]);
            $occData = $occupancy->fetch();
            
            $occupancyRate = $occData['total_rooms'] > 0 
                ? round(($occData['occupied_rooms'] / $occData['total_rooms']) * 100, 2)
                : 0;
            
            $hotelData = [
                'id' => $hotelId,
                'name' => $hotel['name'],
                'revenue' => (float)$revenue['revenue'],
                'bookings' => (int)$bookings['count'],
                'occupancy_rate' => $occupancyRate,
                'total_rooms' => (int)$occData['total_rooms']
            ];
            
            $overview['hotels'][] = $hotelData;
            
            // Update summary
            $overview['summary']['total_revenue'] += (float)$revenue['revenue'];
            $overview['summary']['total_bookings'] += (int)$bookings['count'];
            $overview['summary']['average_occupancy'] += $occupancyRate;
        }
        
        if (count($hotelsList) > 0) {
            $overview['summary']['average_occupancy'] = 
                round($overview['summary']['average_occupancy'] / count($hotelsList), 2);
        }
        
        // Get pending requests
        $pending = $pdo->prepare("
            SELECT COUNT(br.id) as count
            FROM booking_requests br
            JOIN bookings b ON br.booking_id = b.id
            JOIN hotels h ON b.hotel_id = h.id
            WHERE h.manager_id = ? AND br.status = 'pending'
        ");
        $pending->execute([$uid]);
        $pendingData = $pending->fetch();
        $overview['summary']['pending_requests'] = (int)$pendingData['count'];
        
        return json([
            'status' => 'ok',
            'overview' => $overview
        ]);
    }

    // ---------------- GET /api/manager/this-months-revenue ----------------
    public function thisMonthsRevenue() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(b.total_price), 0) as revenue
            FROM bookings b
            JOIN hotels h ON b.hotel_id = h.id
            WHERE h.manager_id = ? 
                AND b.status IN ('confirmed', 'completed')
                AND MONTH(b.created_at) = MONTH(CURDATE())
                AND YEAR(b.created_at) = YEAR(CURDATE())
        ");
        $stmt->execute([$uid]);
        
        $result = $stmt->fetch();
        
        return json([
            'status' => 'ok',
            'revenue' => (float)$result['revenue'] || 0,
            'month' => date('F Y')
        ]);
    }

    // ---------------- GET /api/manager/pending-reviews ----------------
    public function pendingReviews() {
        start_session();
        
        if ($_SESSION['role'] !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        $uid = $_SESSION['uid'];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(rv.id) as count
            FROM reviews rv
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE h.manager_id = ? AND rv.status = 'pending'
        ");
        $stmt->execute([$uid]);
        
        $result = $stmt->fetch();
        
        return json([
            'status' => 'ok',
            'count' => (int)$result['count'] || 0
        ]);
    }
}
?>