-- Add the Staff role used by staff/login.php.
-- Run once against the voicedb database.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'dean', 'student', 'staff') NOT NULL;

ALTER TABLE activity_logs
    MODIFY COLUMN role ENUM('admin', 'dean', 'staff') NOT NULL;

ALTER TABLE ticket_replies
    MODIFY COLUMN sender_role ENUM('student', 'dean', 'admin', 'staff') NOT NULL;
