<?php
// public/api/auth/forgot-password.php - FIXED VERSION
require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json');

// Enable CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

// Debug: Log everything
error_log("=== FORGOT PASSWORD DEBUG ===");
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get raw input
    $rawInput = file_get_contents('php://input');
    error_log("Raw Input: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Parse Error: " . json_last_error_msg());
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }
    
    $email = trim($data['email'] ?? '');
    error_log("Email after trim: '$email'");
    
    // Validate email
    if (empty($email)) {
        error_log("Email is empty");
        echo json_encode(['error' => 'Please provide your email address']);
        exit;
    }
    
    // First, normalize the email (lowercase)
    $normalizedEmail = strtolower(trim($email));
    error_log("Normalized email: '$normalizedEmail'");
    
    try {
        // Get database connection
        $pdo = db();
        
        // Debug: Show what database we're connected to
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        error_log("Connected to database: $dbName");
        
        // Show all users for debugging
        $allUsers = $pdo->query("SELECT email FROM users")->fetchAll();
        error_log("All users in database: " . json_encode($allUsers));
        
        // FIX: Use case-insensitive comparison
        $stmt = $pdo->prepare(
            "SELECT id, email, name 
             FROM users 
             WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        error_log("Query executed with email: '$email'");
        error_log("Found user: " . ($user ? 'YES' : 'NO'));
        
        if (!$user) {
            // Try with normalized email
            $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$normalizedEmail]);
            $user = $stmt->fetch();
            
            if (!$user) {
                error_log("User not found even with normalized email");
                
                // Return helpful error
                echo json_encode([
                    'error' => 'Email not found in our system',
                    'debug' => [
                        'requested_email' => $email,
                        'normalized_email' => $normalizedEmail,
                        'database' => $dbName
                    ]
                ]);
                exit;
            }
        }
        
        error_log("User found: ID=" . $user['id'] . ", Email=" . $user['email']);
        
        // Create password_resets table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                used BOOLEAN DEFAULT FALSE,
                INDEX idx_token (token),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token
        $stmt = $pdo->prepare(
            "INSERT INTO password_resets (email, token, expires_at, created_at) 
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
             token = VALUES(token), 
             expires_at = VALUES(expires_at)"
        );
        $stmt->execute([$user['email'], $token, $expires_at]);
        
        // For testing - return the reset link
        $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/HOTEL-SYSTEM/public/reset-password.html?token=" . $token;
        
        error_log("Token generated: $token");
        error_log("Reset link: $resetLink");
        
        echo json_encode([
            'success' => true,
            'message' => 'Password reset link has been sent to your email',
            'email' => $user['email'],
            'debug' => [
                'actual_email_in_db' => $user['email'],
                'reset_link' => $resetLink,
                'database' => $dbName
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        echo json_encode([
            'error' => 'Database error',
            'debug' => $e->getMessage()
        ]);
    }
    
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>