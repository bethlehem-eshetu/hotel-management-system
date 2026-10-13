<?php
// debug_forgot_password.php
require_once __DIR__ . '/src/bootstrap.php';

echo "🔍 DEBUG: Forgot Password Issue\n";
echo "===============================\n\n";

// Test email
$testEmail = 'admin@gmail.com';

try {
    $pdo = db();
    
    // 1. Check database connection
    echo "1. Database Connection:\n";
    echo "   Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
    echo "   ✅ Connected successfully\n\n";
    
    // 2. Check ALL users in database (case sensitivity)
    echo "2. All users in database:\n";
    $stmt = $pdo->query("SELECT id, email, LOWER(email) as lower_email, role FROM users ORDER BY id");
    $allUsers = $stmt->fetchAll();
    
    if (empty($allUsers)) {
        echo "   ❌ NO USERS FOUND IN DATABASE\n";
    } else {
        foreach ($allUsers as $user) {
            $match = (strtolower($user['email']) === strtolower($testEmail)) ? '✅' : '  ';
            echo "   $match ID: {$user['id']}, Email: '{$user['email']}', Lowercase: '{$user['lower_email']}', Role: {$user['role']}\n";
        }
    }
    echo "\n";
    
    // 3. Check exact match (case-sensitive)
    echo "3. Exact match (case-sensitive) for '$testEmail':\n";
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ?");
    $stmt->execute([$testEmail]);
    $exactMatch = $stmt->fetch();
    
    if ($exactMatch) {
        echo "   ✅ FOUND: ID={$exactMatch['id']}, Email='{$exactMatch['email']}', Role={$exactMatch['role']}\n";
    } else {
        echo "   ❌ NOT FOUND with case-sensitive match\n";
    }
    echo "\n";
    
    // 4. Check case-insensitive match
    echo "4. Case-insensitive match for '$testEmail':\n";
    $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$testEmail]);
    $caseInsensitiveMatch = $stmt->fetch();
    
    if ($caseInsensitiveMatch) {
        echo "   ✅ FOUND (case-insensitive): ID={$caseInsensitiveMatch['id']}, Email='{$caseInsensitiveMatch['email']}'\n";
        echo "   💡 Database has: '{$caseInsensitiveMatch['email']}' but you're looking for: '$testEmail'\n";
    } else {
        echo "   ❌ NOT FOUND even with case-insensitive match\n";
    }
    echo "\n";
    
    // 5. Check for whitespace or invisible characters
    echo "5. Checking for whitespace/invisible characters:\n";
    $stmt = $pdo->query("SELECT id, email, HEX(email) as email_hex, LENGTH(email) as email_len FROM users");
    $usersWithHex = $stmt->fetchAll();
    
    foreach ($usersWithHex as $user) {
        echo "   Email: '{$user['email']}' (Length: {$user['email_len']}, Hex: {$user['email_hex']})\n";
    }
    echo "\n";
    
    // 6. Test the exact query from forgot-password.php
    echo "6. Testing forgot-password.php query:\n";
    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE email = ?");
    $stmt->execute([$testEmail]);
    $forgotPasswordResult = $stmt->fetch();
    
    if ($forgotPasswordResult) {
        echo "   ✅ Query would return: ID={$forgotPasswordResult['id']}, Email='{$forgotPasswordResult['email']}'\n";
    } else {
        echo "   ❌ Query returns NO RESULTS\n";
        echo "   💡 This is why 'Email not found in our system' appears\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
}

echo "\n🎯 Possible Solutions:\n";
echo "1. The email in database might have different case (Admin@Gmail.com vs admin@gmail.com)\n";
echo "2. There might be whitespace in the stored email\n";
echo "3. The forgot-password.php is connecting to a different database\n";
echo "4. There's a typo in the email\n";
?>