<?php
// Script to add sample complaint data for demo purposes
require 'db_connection.php';

$samples = [
    [
        'ticket' => 'VOX-C-' . date('Y') . '-0001',
        'subject' => 'Poor Lighting in Library Study Areas',
        'description' => 'The lighting in the library study sections is very dim, making it difficult to read and study for long hours. Many students have complained about eye strain. We need better lighting fixtures or LED upgrades.',
        'category' => 'Facilities & Maintenance',
        'is_anonymous' => 1,
    ],
    [
        'ticket' => 'VOX-C-' . date('Y') . '-0002',
        'subject' => 'Cafeteria Food Quality Issues',
        'description' => 'The quality of food in the cafeteria has deteriorated significantly. Portion sizes are smaller, and the food doesn\'t taste fresh. We need better sourcing of ingredients and stricter quality control measures.',
        'category' => 'Facilities & Maintenance',
        'is_anonymous' => 0,
    ],
    [
        'ticket' => 'VOX-C-' . date('Y') . '-0003',
        'subject' => 'Delayed Course Registration System',
        'description' => 'The course registration system is extremely slow and often crashes during peak hours. Students miss enrollment windows because they can\'t log in. The system needs urgent optimization.',
        'category' => 'Administrative / Registrar',
        'is_anonymous' => 0,
    ],
    [
        'ticket' => 'VOX-C-' . date('Y') . '-0004',
        'subject' => 'Unsafe Parking Area at Night',
        'description' => 'The parking area lacks adequate lighting at night, making students feel unsafe. Some areas don\'t have CCTV coverage. We request improved security measures and better illumination.',
        'category' => 'Safety & Security',
        'is_anonymous' => 1,
    ],
];

$results = [];

foreach ($samples as $i => $sample) {
    try {
        // Get a sample student
        $studentStmt = $pdo->prepare('SELECT id, college_id FROM student_profiles LIMIT 1');
        $studentStmt->execute();
        $student = $studentStmt->fetch();
        
        if (!$student) {
            $results[] = 'No students found';
            break;
        }
        
        // Get category ID
        $catStmt = $pdo->prepare('SELECT id FROM complaint_categories WHERE name = ?');
        $catStmt->execute([$sample['category']]);
        $cat = $catStmt->fetch();
        $categoryId = $cat ? $cat['id'] : null;
        
        // Check if ticket already exists
        $checkStmt = $pdo->prepare('SELECT id FROM complaints WHERE ticket_no = ?');
        $checkStmt->execute([$sample['ticket']]);
        if ($checkStmt->fetch()) {
            $results[] = 'Skipped (already exists): ' . $sample['subject'];
            continue;
        }
        
        // Insert complaint
        $stmt = $pdo->prepare(
            'INSERT INTO complaints (
                ticket_no, student_id, college_id, category_id, subject, description,
                is_anonymous, status, approval_status, visibility_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))'
        );
        
        $stmt->execute([
            $sample['ticket'],
            $student['id'],
            $student['college_id'],
            $categoryId,
            $sample['subject'],
            $sample['description'],
            $sample['is_anonymous'],
            'pending',
            'approved',
            'public',
            (4 - $i)  // Make older complaints appear first
        ]);
        
        $complaintId = (int)$pdo->lastInsertId();
        
        // Add sample reactions (random agree/disagree from different students)
        $reactionCount = rand(3, 8);
        $agreeCount = rand(2, 6);
        
        for ($j = 0; $j < $reactionCount; $j++) {
            $reaction = $j < $agreeCount ? 'agree' : 'disagree';
            $reaction_student = ($j % 3) + 1; // Sample different students
            
            try {
                $reactStmt = $pdo->prepare(
                    'INSERT INTO complaint_reactions (complaint_id, student_id, reaction_type)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE reaction_type = ?'
                );
                $reactStmt->execute([$complaintId, $reaction_student, $reaction, $reaction]);
            } catch (Exception $e) {
                // Skip duplicate reactions silently
            }
        }
        
        $results[] = '✓ Added: ' . $sample['subject'] . ' (' . $reactionCount . ' reactions)';
        
    } catch (Exception $e) {
        $results[] = '✗ Error: ' . $e->getMessage();
    }
}

// Output results
echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Sample Data Import</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .success { color: #22c55e; }
        .info { color: #3b82f6; }
        .error { color: #ef4444; }
        pre { background: #f9fafb; padding: 15px; border-radius: 6px; border-left: 4px solid #3b82f6; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Sample Complaint Data Import</h1>
        <pre>";

foreach ($results as $result) {
    if (strpos($result, '✓') === 0) {
        echo "<span class='success'>$result</span>\n";
    } elseif (strpos($result, '✗') === 0) {
        echo "<span class='error'>$result</span>\n";
    } else {
        echo "<span class='info'>$result</span>\n";
    }
}

echo "
        </pre>
        <p style='color: #666; font-size: 14px;'>
            <strong>Next Steps:</strong><br>
            1. Login as a student and visit the <strong>Community Complaints Feed</strong><br>
            2. You should see the sample complaints with agree/disagree buttons<br>
            3. Admin can review pending complaints at <strong>Admin Dashboard > Complaints</strong><br>
            4. Delete this file after testing: <code>add_sample_complaints.php</code>
        </p>
    </div>
</body>
</html>";
?>
