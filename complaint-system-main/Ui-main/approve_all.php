<?php
// Quick script to approve all complaints for testing
require 'db_connection.php';

try {
    // Update all pending complaints to approved
    $stmt = $pdo->prepare(
        "UPDATE complaints 
         SET approval_status = 'approved', 
             visibility_status = 'public',
             admin_reviewed_at = NOW()
         WHERE approval_status = 'pending'"
    );
    $stmt->execute();
    
    $rowsUpdated = $stmt->rowCount();
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Approve Complaints</title>
        <style>
            body { font-family: Arial; padding: 40px; background: #f5f5f5; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
            .success { color: #22c55e; font-weight: bold; }
            .info { color: #3b82f6; margin: 20px 0; }
            .next { background: #dbeafe; padding: 15px; border-radius: 6px; margin-top: 20px; }
            a { color: #3b82f6; text-decoration: none; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>✅ Complaints Approved</h1>
            <p class='success'>$rowsUpdated complaint(s) approved successfully!</p>
            
            <div class='info'>
                <strong>Changes Made:</strong>
                <ul>
                    <li>approval_status → 'approved'</li>
                    <li>visibility_status → 'public'</li>
                    <li>admin_reviewed_at → NOW()</li>
                </ul>
            </div>
            
            <div class='next'>
                <strong>Next Step:</strong> <br>
                Go to <a href='student/student_complaint_feed.php'>Community Complaints Feed</a> and you should see all approved complaints!
            </div>
            
            <p style='margin-top: 20px; color: #666; font-size: 13px;'>
                After testing, delete this file: <code>approve_all.php</code>
            </p>
        </div>
    </body>
    </html>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
