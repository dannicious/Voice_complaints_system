<?php
// Script to apply database schema updates
require 'db_connection.php';

try {
    $sql = file_get_contents('database_updates.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "✓ Database schema updated successfully!<br>";
    echo "Columns added:<br>";
    echo "- approval_status (pending, approved, rejected)<br>";
    echo "- admin_id<br>";
    echo "- admin_notes<br>";
    echo "- admin_reviewed_at<br>";
    echo "- visibility_status (private, public)<br>";
    echo "- complaint_reactions table<br>";
    echo "<br><a href='student_complaint_feed.php'>Go to Community Complaints Feed</a>";
    
} catch (Exception $e) {
    echo "✗ Error: " . htmlspecialchars($e->getMessage());
}
?>
