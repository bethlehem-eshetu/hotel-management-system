<?php
// src/controllers/AdminController.php
require_once __DIR__ . '/../bootstrap.php';

class AdminController {

    // ---------------- GET /api/admin/users ----------------
    public function users() {
        start_session();
        
        error_log("=== ADMIN USERS API ===");
        error_log("Session uid: " . ($_SESSION['uid'] ?? 'not set'));
        error_log("Session role: " . ($_SESSION['role'] ?? 'not set'));
        
        if (!isset($_SESSION['uid'])) {
            error_log("No user session (uid not set)");
            return json(['error'=>'Unauthorized'],401);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            error_log("Access denied - Not admin, role is: " . $_SESSION['role']);
            return json(['error'=>'Forbidden - Admin access required'],403);
        }

        $pdo = db();

        // Optional pagination
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;
        $offset = ($page - 1) * $perPage;

        // Check if name columns exist
        try {
            $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'");
            if ($checkStmt->rowCount() > 0) {
                $nameField = "name";
            } else {
                // Try first_name + last_name
                $checkFirst = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'");
                $checkLast = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_name'");
                if ($checkFirst->rowCount() > 0 && $checkLast->rowCount() > 0) {
                    $nameField = "CONCAT(first_name, ' ', last_name) as name";
                } else {
                    $nameField = "email as name";
                }
            }
        } catch (Exception $e) {
            $nameField = "email as name";
        }

        // Check if is_active column exists
        try {
            $checkActive = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
            if ($checkActive->rowCount() > 0) {
                $statusField = "is_active as status";
            } else {
                $statusField = "'active' as status";
            }
        } catch (Exception $e) {
            $statusField = "'active' as status";
        }

        $stmt = $pdo->prepare("
            SELECT id, email, role, 
                   $nameField,
                   phone, $statusField, created_at
            FROM users
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Users found: " . count($users));
        
        return json([
            'status' => 'ok',
            'page' => $page,
            'per_page' => $perPage,
            'users' => $users
        ]);
    }

    // ---------------- GET /api/admin/bookings ----------------
    public function bookings() {
        start_session();
        
        error_log("=== ADMIN BOOKINGS API ===");
        error_log("Session role: " . ($_SESSION['role'] ?? 'not set'));
        
        if (!isset($_SESSION['uid'])) {
            error_log("No user session (uid not set)");
            return json(['error'=>'Unauthorized'],401);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            error_log("Access denied - Not admin");
            return json(['error'=>'Forbidden'],403);
        }

        $pdo = db();

        // Filters
        $filters = [];
        $params = [];

        if (isset($_GET['user_id'])) {
            $filters[] = "b.user_id = ?";
            $params[] = (int)$_GET['user_id'];
        }
        if (isset($_GET['hotel_id'])) {
            $filters[] = "b.hotel_id = ?";
            $params[] = (int)$_GET['hotel_id'];
        }
        if (isset($_GET['status'])) {
            $filters[] = "b.status = ?";
            $params[] = trim($_GET['status']);
        }

        $where = $filters ? "WHERE " . implode(" AND ", $filters) : "";

        // Pagination
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;
        $offset = ($page - 1) * $perPage;

        // Try to get user name
        try {
            // Check if users table has name columns
            $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'");
            if ($checkStmt->rowCount() > 0) {
                $userNameField = "u.name as user_name";
            } else {
                // Try first_name + last_name
                $checkFirst = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'");
                $checkLast = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_name'");
                if ($checkFirst->rowCount() > 0 && $checkLast->rowCount() > 0) {
                    $userNameField = "CONCAT(u.first_name, ' ', u.last_name) as user_name";
                } else {
                    $userNameField = "u.email as user_name";
                }
            }
        } catch (Exception $e) {
            $userNameField = "u.email as user_name";
        }

        $sql = "
            SELECT b.*,
                   $userNameField,
                   u.email as user_email,
                   h.name as hotel_name,
                   r.room_number,
                   r.room_type
            FROM bookings b
            LEFT JOIN users u ON b.user_id = u.id
            LEFT JOIN hotels h ON b.hotel_id = h.id
            LEFT JOIN rooms r ON b.room_id = r.id
            $where
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ";

        error_log("Bookings query: " . $sql);
        
        $stmt = $pdo->prepare($sql);

        // Bind filter params
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }

        // Bind pagination params
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i++, $offset, PDO::PARAM_INT);

        $stmt->execute();
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Bookings found: " . count($bookings));
        
        return json([
            'status' => 'ok',
            'page' => $page,
            'per_page' => $perPage,
            'bookings' => $bookings
        ]);
    }
    
    // ---------------- GET /api/admin/hotels ----------------
    public function hotels() {
        start_session();
        
        error_log("=== ADMIN HOTELS API ===");
        error_log("Session role: " . ($_SESSION['role'] ?? 'not set'));
        
        if (!isset($_SESSION['uid'])) {
            error_log("No user session (uid not set)");
            return json(['error'=>'Unauthorized'],401);
        }
        
        if ($_SESSION['role'] !== 'admin') {
            error_log("Access denied - Not admin");
            return json(['error'=>'Forbidden'],403);
        }

        $pdo = db();

        try {
            // Try to get manager name
            $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'");
            if ($checkStmt->rowCount() > 0) {
                $managerNameField = "u.name as manager_name";
            } else {
                $checkFirst = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'");
                $checkLast = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_name'");
                if ($checkFirst->rowCount() > 0 && $checkLast->rowCount() > 0) {
                    $managerNameField = "CONCAT(u.first_name, ' ', u.last_name) as manager_name";
                } else {
                    $managerNameField = "u.email as manager_name";
                }
            }

            $sql = "
                SELECT h.*, 
                       $managerNameField,
                       u.email as manager_email
                FROM hotels h
                LEFT JOIN users u ON h.manager_id = u.id
                ORDER BY h.created_at DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Hotels found: " . count($hotels));
            
            return json([
                'status' => 'ok',
                'hotels' => $hotels
            ]);
            
        } catch (Exception $e) {
            error_log("Error in admin hotels: " . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => 'Failed to fetch hotels',
                'hotels' => []
            ], 500);
        }
    }
    
    // ---------------- GET /api/admin/stats ----------------
    public function stats() {
        start_session();

        if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'admin') {
            return json(['error' => 'Unauthorized'], 401);
        }

        try {
            $pdo = db();

            // Get total users count
            $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            // Get managers count
            $managersStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='manager'");
            $totalManagers = (int)($managersStmt ? $managersStmt->fetchColumn() : 0);
            
            // Get customers count
            $customersStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'");
            $totalCustomers = (int)($customersStmt ? $customersStmt->fetchColumn() : 0);
            
            // Get hotels count
            $totalHotels = (int)$pdo->query("SELECT COUNT(*) FROM hotels")->fetchColumn();
            
            // Get bookings count
            $totalBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
            
            // Get rooms count
            $totalRooms = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
            
            // Get revenue - try different column names
            $revenueColumns = ['total_price', 'price', 'amount'];
            $totalRevenue = 0;
            
            foreach ($revenueColumns as $col) {
                try {
                    $checkCol = $pdo->query("SHOW COLUMNS FROM bookings LIKE '$col'");
                    if ($checkCol->rowCount() > 0) {
                        $totalRevenue = (float)$pdo->query("
                            SELECT IFNULL(SUM($col),0)
                            FROM bookings
                            WHERE status IN ('confirmed','completed','active')
                        ")->fetchColumn();
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }

            // Get booking counts by status
            $confirmedBookings = 0;
            $pendingBookings = 0;
            $cancelledBookings = 0;
            
            $bookingStatusQuery = $pdo->query("
                SELECT status, COUNT(*) as count
                FROM bookings
                GROUP BY status
            ");
            
            if ($bookingStatusQuery) {
                $statusCounts = $bookingStatusQuery->fetchAll(PDO::FETCH_ASSOC);
                foreach ($statusCounts as $status) {
                    $statusName = strtolower($status['status']);
                    $count = (int)$status['count'];
                    
                    if (in_array($statusName, ['confirmed', 'completed', 'active'])) {
                        $confirmedBookings += $count;
                    } elseif (in_array($statusName, ['pending', 'waiting'])) {
                        $pendingBookings += $count;
                    } elseif (in_array($statusName, ['cancelled', 'canceled', 'rejected'])) {
                        $cancelledBookings += $count;
                    }
                }
            }

            // Get occupied rooms count
            $occupiedRooms = 0;
            try {
                $occupiedStmt = $pdo->query("
                    SELECT COUNT(DISTINCT room_id)
                    FROM bookings
                    WHERE status IN ('confirmed','completed','active')
                      AND CURDATE() BETWEEN check_in_date AND check_out_date
                ");
                $occupiedRooms = (int)($occupiedStmt ? $occupiedStmt->fetchColumn() : 0);
            } catch (Exception $e) {
                error_log("Error getting occupied rooms: " . $e->getMessage());
            }

            // Calculate occupancy rate
            $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0;

            return json([
                'status' => 'ok',
                'stats' => [
                    'total_users' => $totalUsers,
                    'managers_count' => $totalManagers,
                    'customers_count' => $totalCustomers,
                    'total_hotels' => $totalHotels,
                    'total_bookings' => $totalBookings,
                    'total_revenue' => $totalRevenue,
                    'total_rooms' => $totalRooms,
                    'occupancy_rate' => $occupancyRate,
                    'confirmed_bookings' => $confirmedBookings,
                    'pending_bookings' => $pendingBookings,
                    'cancelled_bookings' => $cancelledBookings
                ]
            ]);

        } catch (Throwable $e) {
            error_log("Error in admin stats: " . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    // ---------------- GET /api/admin/analytics ----------------
    public function analytics() {
        start_session();

        if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'admin') {
            return json(['error' => 'Unauthorized'], 401);
        }

        try {
            $pdo = db();
            
            $fromDate = $_GET['from_date'] ?? date('Y-m-01', strtotime('-1 month'));
            $toDate = $_GET['to_date'] ?? date('Y-m-t');
            $hotelId = $_GET['hotel_id'] ?? null;

            // Base conditions
            $conditions = [];
            $params = [];
            
            if ($hotelId && $hotelId !== 'all') {
                $conditions[] = "hotel_id = ?";
                $params[] = (int)$hotelId;
            }
            
            $conditions[] = "DATE(check_in_date) BETWEEN ? AND ?";
            $params[] = $fromDate;
            $params[] = $toDate;
            
            $whereClause = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
            
            // Get revenue data for chart
            $revenueStmt = $pdo->prepare("
                SELECT DATE_FORMAT(check_in_date, '%Y-%m') as month,
                       SUM(CASE 
                           WHEN total_price IS NOT NULL THEN total_price
                           WHEN price IS NOT NULL THEN price
                           ELSE 0 END) as revenue
                FROM bookings
                $whereClause
                GROUP BY month
                ORDER BY month ASC
            ");
            $revenueStmt->execute($params);
            $revenueTrend = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get booking counts by status for period
            $bookingStmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN status IN ('confirmed','completed','active') THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN status IN ('pending','waiting') THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status IN ('cancelled','canceled','rejected') THEN 1 ELSE 0 END) as cancelled
                FROM bookings
                $whereClause
            ");
            $bookingStmt->execute($params);
            $bookingStats = $bookingStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get total stats for period
            $totalStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_bookings,
                    COUNT(DISTINCT user_id) as unique_customers,
                    SUM(CASE 
                        WHEN total_price IS NOT NULL THEN total_price
                        WHEN price IS NOT NULL THEN price
                        ELSE 0 END) as total_revenue
                FROM bookings
                $whereClause
            ");
            $totalStmt->execute($params);
            $totalStats = $totalStmt->fetch(PDO::FETCH_ASSOC);
            
            return json([
                'status' => 'ok',
                'period' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate
                ],
                'revenue_trend' => $revenueTrend,
                'booking_stats' => $bookingStats,
                'total_stats' => $totalStats
            ]);

        } catch (Throwable $e) {
            error_log("Error in admin analytics: " . $e->getMessage());
            return json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}