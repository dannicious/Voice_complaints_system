<?php
// Diagnostic script to check database structure
require 'db_connection.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Database Diagnostic</title>
    <style>
        body { font-family: Arial; padding: 40px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        .section { margin: 30px 0; }
        .error { color: #dc2626; background: #fee2e2; padding: 15px; border-radius: 6px; }
        .success { color: #16a34a; background: #dcfce7; padding: 15px; border-radius: 6px; }
        .warning { color: #d97706; background: #fef3c7; padding: 15px; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #3b82f6; color: white; }
        tr:nth-child(even) { background: #f9fafb; }
        code { background: #f3f4f6; padding: 4px 8px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔍 Database Diagnostic Report</h1>";

try {
    // Check columns in complaints table
    echo "<div class='section'>";
    echo "<h2>Column Structure</h2>";
    
    $columnsSql = "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
                   FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_NAME='complaints' AND TABLE_SCHEMA=DATABASE()
                   ORDER BY ORDINAL_POSITION";
    
    $columnsStmt = $pdo->query($columnsSql);
    $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        echo "<table><tr><th>Column Name</th><th>Type</th><th>Nullable</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            $hasApproval = strpos($col['COLUMN_NAME'], 'approval') !== false;
            $class = $hasApproval ? 'style="background: #fef08a;"' : '';
            echo "<tr $class>";
            echo "<td><code>" . $col['COLUMN_NAME'] . "</code></td>";
            echo "<td>" . $col['COLUMN_TYPE'] . "</td>";
            echo "<td>" . $col['IS_NULLABLE'] . "</td>";
            echo "<td>" . ($col['COLUMN_DEFAULT'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check if approval_status column exists
    $approvalCheckSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_NAME='complaints' 
                        AND COLUMN_NAME='approval_status'
                        AND TABLE_SCHEMA=DATABASE()";
    $approvalCheckStmt = $pdo->query($approvalCheckSql);
    
    if ($approvalCheckStmt->rowCount() > 0) {
        echo "<div class='success'>✓ approval_status column EXISTS</div>";
    } else {
        echo "<div class='error'>✗ approval_status column MISSING - Need to add it!</div>";
    }
    
    echo "</div>";
    
    // Check data in complaints
    echo "<div class='section'>";
    echo "<h2>Complaints Data</h2>";
    
    $dataStmt = $pdo->query("SELECT id, ticket_no, act_complained_of, status, approval_status, visibility_status FROM complaints LIMIT 5");
    $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($data) > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Ticket</th><th>Act Complained Of</th><th>Status</th><th>Approval Status</th><th>Visibility</th></tr>";
        foreach ($data as $row) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td><code>" . $row['ticket_no'] . "</code></td>";
            echo "<td>" . substr($row['act_complained_of'], 0, 20) . "...</td>";
            echo "<td><code>" . $row['status'] . "</code></td>";
            echo "<td><code>" . ($row['approval_status'] ?? 'NULL') . "</code></td>";
            echo "<td><code>" . ($row['visibility_status'] ?? 'NULL') . "</code></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Count approved vs pending
    echo "<div class='section'>";
    echo "<h2>Statistics</h2>";
    
    $statsStmt = $pdo->query("SELECT 
                              approval_status, 
                              COUNT(*) as count 
                              FROM complaints 
                              GROUP BY approval_status");
    $stats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>";
    echo "<tr><th>Approval Status</th><th>Count</th></tr>";
    foreach ($stats as $stat) {
        echo "<tr>";
        echo "<td><code>" . ($stat['approval_status'] ?? 'NULL') . "</code></td>";
        echo "<td><strong>" . $stat['count'] . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>Database Error: " . $e->getMessage() . "</div>";
}

echo "</div></body></html>";
?>
