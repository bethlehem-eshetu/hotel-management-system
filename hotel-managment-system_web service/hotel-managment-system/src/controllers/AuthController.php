<?php
// src/controllers/AuthController.php
require_once __DIR__ . '/../bootstrap.php';

class AuthController {

  // ---------------- REGISTER ----------------
  public function register($data) {
    start_session();

    // Check if this is an update request
    if (isset($data['action']) && $data['action'] === 'update' && isset($data['user_id'])) {
        return $this->updateUser($data);
    }

    if (
      empty($data['email']) ||
      empty($data['password']) ||
      !filter_var($data['email'], FILTER_VALIDATE_EMAIL)
    ) {
      return json(['error' => 'Invalid email or password'], 400);
    }

    if (strlen($data['password']) < 8) {
      return json(['error' => 'Password must be at least 8 characters'], 400);
    }

    // 🔒 SECURITY: Only admin can assign roles
    $role = 'customer';
    if (
      isset($_SESSION['role']) &&
      $_SESSION['role'] === 'admin' &&
      isset($data['role'])
    ) {
      $role = $data['role'];
    }

    try {
      $stmt = db()->prepare(
        "INSERT INTO users (email, password_hash, role, name, phone, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
      );
      $stmt->execute([
        $data['email'],
        password_hash($data['password'], PASSWORD_DEFAULT),
        $role,
        $data['name'] ?? '',
        $data['phone'] ?? ''
      ]);
    } catch (PDOException $e) {
      return json(['error' => 'Email already exists'], 409);
    }

    return json([
      'status'  => 'ok',
      'message' => 'Registration successful'
    ]);
  }

  // ---------------- UPDATE USER ----------------
  private function updateUser($data) {
    // Check if user is admin
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
      return json(['error' => 'Forbidden - Admin only'], 403);
    }

    $user_id = $data['user_id'];
    $pdo = db();

    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    if (!$stmt->fetch()) {
      return json(['error' => 'User not found'], 404);
    }

    // Build update query
    $updates = [];
    $params = [];
    
    $allowedFields = ['name', 'email', 'role', 'phone'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
        }
    }
    
    // Handle password update if provided
    if (isset($data['password']) && !empty($data['password'])) {
        if (strlen($data['password']) < 8) {
            return json(['error' => 'Password must be at least 8 characters'], 400);
        }
        $updates[] = 'password_hash = ?';
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    
    if (empty($updates)) {
        return json(['error' => 'No valid fields to update'], 400);
    }
    
    // Add updated_at timestamp
    $updates[] = 'updated_at = NOW()';
    
    // Add user ID to params
    $params[] = $user_id;
    
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    
    try {
        $stmt->execute($params);
        return json([
            'status' => 'ok',
            'message' => 'User updated successfully',
            'user_id' => $user_id
        ]);
    } catch (Exception $e) {
        error_log("User update failed: " . $e->getMessage());
        return json(['error' => 'Update failed: ' . $e->getMessage()], 500);
    }
  }

  // ---------------- LOGIN ----------------
  public function login($data) {
    start_session();

    if (empty($data['email']) || empty($data['password'])) {
      return json(['error' => 'Email and password required'], 400);
    }

    $stmt = db()->prepare(
      "SELECT id, email, password_hash, role, name, phone
       FROM users WHERE email = ?"
    );
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
      return json(['error' => 'Invalid credentials'], 401);
    }

    // 🔐 Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['uid']   = $user['id'];
    $_SESSION['role']  = $user['role'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['name']  = $user['name'];

    return json([
      'status' => 'ok',
      'user'   => [
        'id'    => $user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
        'name'  => $user['name'],
        'phone' => $user['phone']
      ]
    ]);
  }

  // ---------------- SESSION CHECK ----------------
  public function session() {
    start_session();

    if (!isset($_SESSION['uid'])) {
      return json(['error' => 'Not logged in'], 401);
    }

    return json([
      'status' => 'ok',
      'user'   => [
        'id'    => $_SESSION['uid'],
        'email' => $_SESSION['email'],
        'role'  => $_SESSION['role'],
        'name'  => $_SESSION['name']
      ]
    ]);
  }

  // ---------------- LOGOUT ----------------
  public function logout() {
    start_session();
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(
        session_name(), '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
      );
    }

    session_destroy();
    return json(['status' => 'ok', 'message' => 'Logged out']);
  }
}