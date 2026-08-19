-- Database Schema Updates for Enhanced Complaint System

-- 1. ALTER complaints table to add new fields for approval workflow
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS approval_status VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, approved, rejected';
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS admin_id INT NULL AFTER approval_status;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL AFTER admin_id;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS admin_reviewed_at TIMESTAMP NULL AFTER admin_notes;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS visibility_status VARCHAR(50) DEFAULT 'private' COMMENT 'private, public';
ALTER TABLE complaint_categories ADD COLUMN IF NOT EXISTS route ENUM('general','college') DEFAULT NULL AFTER name;
ALTER TABLE complaint_categories ADD COLUMN IF NOT EXISTS category_type ENUM('general','college') DEFAULT NULL AFTER route;
ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS route ENUM('general','college') DEFAULT NULL AFTER name;
ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS category_type ENUM('general','college') DEFAULT NULL AFTER route;

UPDATE complaint_categories SET category_type = route WHERE category_type IS NULL AND route IS NOT NULL;
UPDATE suggestion_categories SET category_type = route WHERE category_type IS NULL AND route IS NOT NULL;

-- Add foreign key for admin_id if not exists
ALTER TABLE complaints ADD CONSTRAINT fk_complaints_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS complaint_reactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    complaint_id INT NOT NULL,
    student_id INT NOT NULL,
    reaction_type VARCHAR(20) NOT NULL COMMENT 'agree, disagree',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (complaint_id, student_id),
    FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES student_profiles(id) ON DELETE CASCADE,
    INDEX idx_complaint_id (complaint_id),
    INDEX idx_student_id (student_id)
);

-- 3. ALTER suggestions table to add expected outcome field and suggestion date
ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS date_of_suggestion DATE NULL AFTER category_id;
ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS expected_outcome TEXT NULL AFTER description;

-- Remove unused suggestion workflow columns from the suggestions table
ALTER TABLE suggestions
    DROP COLUMN IF EXISTS approval_status,
    DROP COLUMN IF EXISTS admin_id,
    DROP COLUMN IF EXISTS admin_notes,
    DROP COLUMN IF EXISTS admin_reviewed_at,
    DROP COLUMN IF EXISTS visibility_status;

-- Ensure reviewed status is accepted for suggestions
ALTER TABLE suggestions MODIFY COLUMN status ENUM('new','under_review','approved','implemented','declined','rejected','reviewed') DEFAULT 'new';

-- 4. Add indexes for better performance
ALTER TABLE complaints ADD INDEX idx_approval_status (approval_status);
ALTER TABLE complaints ADD INDEX idx_visibility_status (visibility_status);
ALTER TABLE complaints ADD INDEX idx_admin_reviewed_at (admin_reviewed_at);
