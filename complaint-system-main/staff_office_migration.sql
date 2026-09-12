-- Staff office routing for suggestions.
-- Run once against the voicedb database.

CREATE TABLE IF NOT EXISTS staff_profiles (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    office VARCHAR(100) NOT NULL,
    name VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_profiles_user (user_id),
    KEY idx_staff_profiles_office (office),
    CONSTRAINT fk_staff_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE suggestion_categories
    ADD COLUMN office VARCHAR(100) DEFAULT NULL AFTER category_type;

ALTER TABLE suggestions
    ADD COLUMN office VARCHAR(100) DEFAULT NULL AFTER category_id;

ALTER TABLE ticket_replies
    MODIFY COLUMN sender_role ENUM('student', 'dean', 'admin', 'staff') NOT NULL;

ALTER TABLE complaints MODIFY COLUMN ticket_no VARCHAR(20) NULL;
ALTER TABLE suggestions MODIFY COLUMN ticket_no VARCHAR(20) NULL;
