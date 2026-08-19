<?php
// Fix script to add missing approval columns and set status
require 'db_connection.php';

try {
    echo "<h2>🔧 Fixing Complaint Approval System...</h2>";
    
    // Check and add missing columns
    $columns = [
        'approval_status' => "VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, approved, rejected'",
        'admin_id' => "INT NULL",
        'admin_notes' => "TEXT NULL",
        'admin_reviewed_at' => "TIMESTAMP NULL",
        'visibility_status' => "VARCHAR(50) DEFAULT 'private' COMMENT 'private, public'"
    ];
    
    foreach ($columns as $column => $definition) {
        try {
            $checkSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_NAME='complaints' AND COLUMN_NAME='$column' AND TABLE_SCHEMA=DATABASE()";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $alterSql = "ALTER TABLE complaints ADD COLUMN $column $definition";
                $pdo->exec($alterSql);
                echo "✓ Added column: <strong>$column</strong><br>";
            } else {
                echo "• Column already exists: <strong>$column</strong><br>";
            }
        } catch (Exception $e) {
            echo "! Warning for $column: " . $e->getMessage() . "<br>";
        }
    }
    
    echo "<hr>";
    
    // Now approve all pending complaints
    $updateSql = "UPDATE complaints 
                  SET approval_status = 'approved', 
                      visibility_status = 'public',
                      admin_reviewed_at = NOW()
                  WHERE approval_status = 'pending' OR approval_status IS NULL";
    
    $pdo->exec($updateSql);
    
    // Get count of approved
    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM complaints WHERE approval_status = 'approved'");
    $count = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h3>✅ Success!</h3>";
    echo "<strong>Total approved complaints:</strong> " . $count['total'] . "<br>";
    echo "<strong>Status:</strong> All pending complaints are now APPROVED<br>";
    
    echo "<hr>";
    echo "<p style='color: green; font-weight: bold;'>
        ✓ Go to <a href='student/student_complaint_feed.php'>Community Feed</a> to see approved complaints!
    </p>";
    
    echo "<p style='color: #666; font-size: 13px; margin-top: 20px;'>
        After confirming it works, delete this file: <code>fix_approval.php</code>
    </p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Error: " . $e->getMessage() . "</h3>";
    echo "<p>Make sure database is imported and connection works.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fix Approval System</title>
    <style>
        body { font-family: Arial; padding: 40px; background: #f5f5f5; }
        .container { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #3b82f6; margin-bottom: 20px; }
        h3 { color: #111827; margin-top: 20px; }
        a { color: #3b82f6; text-decoration: none; font-weight: bold; }
        a:hover { text-decoration: underline; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Results shown above -->
    </div>
</body>
</html>
