<?php
// public/api/auth/reset-password.php
require_once __DIR__ . '/../../../src/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = trim($data['token'] ?? '');
    $newPassword = $data['newPassword'] ?? '';
    $confirmPassword = $data['confirmPassword'] ?? '';
    
    // Validate inputs
    if (empty($token)) {
        echo json_encode(['error' => 'Invalid reset token']);
        exit;
    }
    
    if (empty($newPassword) || strlen($newPassword) < 8) {
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        exit;
    }
    
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['error' => 'Passwords do not match']);
        exit;
    }
    
    try {
        // Check if token exists and is valid
        $stmt = db()->prepare(
            "SELECT pr.email, pr.expires_at, pr.used 
             FROM password_resets pr 
             WHERE pr.token = ? AND pr.used = FALSE"
        );
        $stmt->execute([$token]);
        $resetRequest = $stmt->fetch();
        
        if (!$resetRequest) {
            echo json_encode(['error' => 'Invalid or expired reset token']);
            exit;
        }
        
        // Check if token has expired
        if (strtotime($resetRequest['expires_at']) < time()) {
            echo json_encode(['error' => 'Reset token has expired']);
            exit;
        }
        
        // Hash the new password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update user's password
        $stmt = db()->prepare(
            "UPDATE users 
             SET password_hash = ? 
             WHERE email = ?"
        );
        $stmt->execute([$passwordHash, $resetRequest['email']]);
        
        // Mark token as used
        $stmt = db()->prepare(
            "UPDATE password_resets 
             SET used = TRUE 
             WHERE token = ?"
        );
        $stmt->execute([$token]);
        
        // Log the password change (optional)
        error_log("Password reset successful for: " . $resetRequest['email']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Password has been reset successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Reset password error: " . $e->getMessage());
        echo json_encode(['error' => 'Server error. Please try again later.']);
    }
    
} else {
    echo json_encode(['error' => 'Invalid request method']);
}