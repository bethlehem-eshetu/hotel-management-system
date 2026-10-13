<?php
// src/controllers/UserController.php
require_once __DIR__ . '/../bootstrap.php';

class UserController {
    
    // Set maximum execution time for this controller
    private $maxExecutionTime = 30; // seconds

    public function __construct() {
        // Set time limit for all methods in this controller
        set_time_limit($this->maxExecutionTime);
    }

    // ---------------- GET /api/users ----------------
    public function index() {
        start_session();

        if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'admin') {
            return json(['error' => 'Unauthorized'], 401);
        }

        try {
            $pdo = db();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Pagination params with limits
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(50, (int)($_GET['limit'] ?? 10)); // Max 50 per page
            $offset = ($page - 1) * $limit;

            // Total users count with timeout
            $startTime = microtime(true);
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
            $totalUsers = (int)$stmt->fetch()['count'];
            
            // Check if query took too long
            if ((microtime(true) - $startTime) > 5) {
                error_log("Slow query detected in UserController::index: COUNT(*) took " . (microtime(true) - $startTime) . " seconds");
            }

            $totalPages = (int)ceil($totalUsers / $limit);

            // Fetch paginated users with timeout protection
            $stmt = $pdo->prepare(
                "SELECT id, email, name, role, phone, created_at
                 FROM users
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset"
            );

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            // Execute with timeout check
            $startTime = microtime(true);
            $stmt->execute();
            
            if ((microtime(true) - $startTime) > 3) {
                error_log("Slow query detected in UserController::index: SELECT took " . (microtime(true) - $startTime) . " seconds");
            }

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json([
                'status' => 'ok',
                'users' => $users,
                'page' => $page,
                'total_pages' => $totalPages,
                'total_users' => $totalUsers
            ]);

        } catch (PDOException $e) {
            error_log("Database error in UserController::index: " . $e->getMessage());
            return json(['error' => 'Database error occurred'], 500);
        } catch (Exception $e) {
            error_log("General error in UserController::index: " . $e->getMessage());
            return json(['error' => 'Failed to fetch users'], 500);
        }
    }

    // ---------------- GET /api/users/{id} ----------------
    public function show($id) {
        start_session();
        
        // Extract ID
        if (is_array($id)) {
            $id = $id['id'] ?? $id[0] ?? null;
        }
        
        $userId = (int)$id;

        try {
            // Users can view their own profile, admins can view any
            if (!isset($_SESSION['uid']) || ($_SESSION['uid'] != $userId && $_SESSION['role'] !== 'admin')) {
                return json(['error' => 'Unauthorized'], 401);
            }

            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT id, email, name, role, phone, created_at
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );
            
            $startTime = microtime(true);
            $stmt->execute([$userId]);
            
            if ((microtime(true) - $startTime) > 2) {
                error_log("Slow query in UserController::show: " . (microtime(true) - $startTime) . " seconds");
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return json(['error' => 'User not found'], 404);
            }

            return json([
                'status' => 'ok',
                'user' => $user
            ]);

        } catch (PDOException $e) {
            error_log("Database error in UserController::show: " . $e->getMessage());
            return json(['error' => 'Database error occurred'], 500);
        } catch (Exception $e) {
            error_log("Error in UserController::show: " . $e->getMessage());
            return json(['error' => 'Failed to fetch user'], 500);
        }
    }

    // ---------------- GET /api/users/me ----------------
    public function me() {
        start_session();

        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT id, email, name, role, phone, created_at
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );
            
            $startTime = microtime(true);
            $stmt->execute([$_SESSION['uid']]);
            
            if ((microtime(true) - $startTime) > 2) {
                error_log("Slow query in UserController::me: " . (microtime(true) - $startTime) . " seconds");
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                // Clear session if user doesn't exist
                session_destroy();
                return json(['error' => 'User not found'], 404);
            }

            return json([
                'status' => 'ok',
                'user' => $user
            ]);

        } catch (PDOException $e) {
            error_log("Database error in UserController::me: " . $e->getMessage());
            return json(['error' => 'Database error occurred'], 500);
        } catch (Exception $e) {
            error_log("Error in UserController::me: " . $e->getMessage());
            return json(['error' => 'Failed to fetch user data'], 500);
        }
    }

    // ---------------- PATCH /api/users/me ----------------
    public function update() {
        start_session();

        if (!isset($_SESSION['uid'])) {
            return json(['error' => 'Unauthorized'], 401);
        }

        // Get request body
        $rawInput = file_get_contents('php://input');
        if (empty($rawInput)) {
            return json(['error' => 'Empty request body'], 400);
        }

        $data = json_decode($rawInput, true);
        if (!$data) {
            return json(['error' => 'Invalid JSON body'], 400);
        }

        // Validate required fields
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return json(['error' => 'Invalid email format'], 400);
        }

        // Start transaction for atomic update
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $fields = [];
            $params = [];
            $sessionUpdates = [];

            // ---- Name ----
            if (isset($data['name'])) {
                $name = trim($data['name']);
                if (empty($name)) {
                    throw new Exception('Name cannot be empty');
                }
                if (strlen($name) > 100) {
                    throw new Exception('Name is too long (max 100 characters)');
                }
                $fields[] = 'name = ?';
                $params[] = $name;
                $sessionUpdates['name'] = $name;
            }

            // ---- Phone ----
            if (isset($data['phone'])) {
                $phone = trim($data['phone']);
                if (!empty($phone)) {
                    // Basic phone validation (adjust for your country)
                    if (!preg_match('/^[0-9\s\-\+\(\)]{10,20}$/', $phone)) {
                        throw new Exception('Invalid phone number format');
                    }
                }
                $fields[] = 'phone = ?';
                $params[] = $phone ?: null;
            }

            // ---- Email ----
            if (isset($data['email'])) {
                $email = trim($data['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address');
                }

                // Check uniqueness (skip for current user)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $stmt->execute([$email, $_SESSION['uid']]);
                if ($stmt->fetch()) {
                    throw new Exception('Email already in use by another account');
                }

                $fields[] = 'email = ?';
                $params[] = $email;
                $sessionUpdates['email'] = $email;
            }

            // ---- Password ----
            if (isset($data['password'])) {
                $password = trim($data['password']);
                if (strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters');
                }

                if (!isset($data['password_confirm']) || $password !== $data['password_confirm']) {
                    throw new Exception('Password confirmation does not match');
                }

                $fields[] = 'password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            if (empty($fields)) {
                throw new Exception('No changes provided');
            }

            // Add user ID to params
            $params[] = $_SESSION['uid'];

            // Build and execute update query
            $query = "UPDATE users SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($query);
            
            $startTime = microtime(true);
            $stmt->execute($params);
            
            if ((microtime(true) - $startTime) > 3) {
                error_log("Slow update in UserController::update: " . (microtime(true) - $startTime) . " seconds");
            }

            // Get updated user data
            $stmt = $pdo->prepare(
                "SELECT id, email, name, role, phone, created_at
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );
            $stmt->execute([$_SESSION['uid']]);
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updatedUser) {
                throw new Exception('User not found after update');
            }

            // Update session if needed
            foreach ($sessionUpdates as $key => $value) {
                $_SESSION[$key] = $value;
            }

            // Commit transaction
            $pdo->commit();

            return json([
                'status'  => 'ok',
                'message' => 'Profile updated successfully',
                'user' => $updatedUser
            ]);
            
        } catch (Exception $e) {
            // Rollback on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Error in UserController::update: " . $e->getMessage());
            return json(['error' => $e->getMessage()], 400);
        }
    }

    // ---------------- DELETE /api/users/{id} ----------------
    public function delete($id) {
        start_session();
        
        if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'admin') {
            return json(['error' => 'Unauthorized'], 401);
        }

        // Extract ID
        if (is_array($id)) {
            $id = $id['id'] ?? $id[0] ?? null;
        }
        
        $userId = (int)$id;

        // Prevent deleting yourself
        if ($userId == $_SESSION['uid']) {
            return json(['error' => 'Cannot delete your own account'], 400);
        }

        try {
            $pdo = db();
            $pdo->beginTransaction();

            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }

            // Check if user is a manager of any hotel
            $stmt = $pdo->prepare("SELECT COUNT(*) as hotel_count FROM hotels WHERE manager_id = ?");
            $stmt->execute([$userId]);
            $hotelCount = $stmt->fetch();
            
            if ($hotelCount['hotel_count'] > 0) {
                throw new Exception('Cannot delete user who is managing hotels');
            }

            // Delete the user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                return json([
                    'status' => 'ok',
                    'message' => 'User deleted successfully',
                    'user_id' => $userId,
                    'user_name' => $user['name']
                ]);
            } else {
                throw new Exception('Failed to delete user');
            }
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Delete user error: " . $e->getMessage());
            return json(['error' => $e->getMessage()], 400);
        }
    }

    // ---------------- Test endpoint ----------------
    public function test() {
        return json([
            'status' => 'ok',
            'message' => 'UserController is working',
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ]);
    }
}
?>