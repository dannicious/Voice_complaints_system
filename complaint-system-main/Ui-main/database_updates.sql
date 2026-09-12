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
ALTER TABLE complaint_categories ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER category_type;
ALTER TABLE complaint_categories ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active;
ALTER TABLE complaint_categories ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER category_type;
ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active;
ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE complaint_categories SET category_type = route WHERE category_type IS NULL AND route IS NOT NULL;
UPDATE suggestion_categories SET category_type = route WHERE category_type IS NULL AND route IS NOT NULL;

INSERT INTO complaint_categories (name, route, category_type, description, is_active)
SELECT seed.name, seed.scope, seed.scope, seed.description, 1
FROM (
    SELECT 'Bullying' AS name, 'college' AS scope, 'Bullying or repeated intimidation.' AS description
    UNION ALL SELECT 'Harassment', 'college', 'Harassment or unwanted conduct.'
    UNION ALL SELECT 'Disrespect / Insult', 'college', 'Disrespectful or insulting behavior.'
    UNION ALL SELECT 'Threat / Intimidation', 'college', 'Threatening or intimidating conduct.'
    UNION ALL SELECT 'Physical Misconduct', 'college', 'Physical misconduct or unsafe behavior.'
    UNION ALL SELECT 'Discrimination / Unfair Treatment', 'college', 'Discrimination or unfair treatment.'
    UNION ALL SELECT 'Academic Concern', 'college', 'Concerns related to academic matters.'
    UNION ALL SELECT 'Abuse of Authority', 'college', 'Abuse or misuse of authority.'
    UNION ALL SELECT 'Inappropriate Behavior', 'college', 'Other inappropriate behavior.'
    UNION ALL SELECT 'Other', 'general', 'Other complaint types.'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM complaint_categories existing WHERE LOWER(existing.name) = LOWER(seed.name));

INSERT INTO suggestion_categories (name, route, category_type, description, is_active)
SELECT seed.name, seed.scope, seed.scope, seed.description, 1
FROM (
    SELECT 'Academic Improvement' AS name, 'college' AS scope, 'Ideas to improve teaching and learning.' AS description
    UNION ALL SELECT 'Facilities Improvement', 'college', 'Ideas to improve campus facilities.'
    UNION ALL SELECT 'Student Services', 'general', 'Ideas to improve student services.'
    UNION ALL SELECT 'Campus Activities', 'general', 'Ideas for campus activities and engagement.'
    UNION ALL SELECT 'Administrative Services', 'general', 'Ideas to improve administrative services.'
    UNION ALL SELECT 'Other', 'general', 'Other suggestion types.'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM suggestion_categories existing WHERE LOWER(existing.name) = LOWER(seed.name));

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

-- 5. Expand the student feedback rating scale from 3 to 4 tiers
-- (Very Satisfied / Satisfied / Unsatisfied / Very Unsatisfied). 'neutral' is
-- kept in the enum only so previously submitted feedback still displays correctly;
-- it is no longer offered as a choice in the rating UI.
ALTER TABLE ticket_feedback MODIFY COLUMN satisfaction ENUM('very_satisfied','satisfied','neutral','not_satisfied','very_unsatisfied') NOT NULL;
ALTER TABLE ticket_feedback_history MODIFY COLUMN satisfaction ENUM('very_satisfied','satisfied','neutral','not_satisfied','very_unsatisfied') NOT NULL;

-- 6. Store what a Call Slip actually communicates (who issued it, when to
-- report, and where) instead of only ever emailing it - so the summoned
-- student can see it again from their "Call Slip issued" notification
-- instead of only from the one-time email.
ALTER TABLE call_slips ADD COLUMN IF NOT EXISTS issued_by_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE call_slips ADD COLUMN IF NOT EXISTS report_date DATE DEFAULT NULL;
ALTER TABLE call_slips ADD COLUMN IF NOT EXISTS report_time VARCHAR(20) DEFAULT NULL;
ALTER TABLE call_slips ADD COLUMN IF NOT EXISTS office_message VARCHAR(255) DEFAULT NULL;
ALTER TABLE call_slips ADD COLUMN IF NOT EXISTS reason_note TEXT DEFAULT NULL;

-- 7. Suggestion flow upgrade: real decision + implementation statuses,
-- duplicate-submission warnings, SLA reminders, and admin reporting.
-- Suggestion-only - complaints are untouched. See suggestion_flow.php,
-- which also applies these idempotently at runtime, so this file is a
-- durable record rather than the only place this ever runs.
ALTER TABLE suggestions MODIFY status ENUM(
    'new','under_review','approved','implemented','declined','rejected','reviewed',
    'accepted','not_feasible','needs_info','planned','in_progress'
) DEFAULT 'under_review';
ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS decided_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS implemented_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS overdue_notified_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS escalated_notified_at TIMESTAMP NULL DEFAULT NULL;

-- 8. Complaint list status badges now decay with time instead of reading
-- "New" indefinitely: "New" for the first hour, then a short elapsed-time
-- label ("1 hr", "2 hrs", "1 day", ...), until a dean/admin actually acts on
-- it. A complaint left untouched for 24+ hours also triggers a one-time
-- notification to whoever handles it. See complaint_age_helpers.php, which
-- also applies this column idempotently at runtime.
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS overdue_notified_at TIMESTAMP NULL DEFAULT NULL;

-- 9. AI-assisted complaint triage: category classification (external API,
-- see complaint_groq_helpers.php) plus a rule-based urgency scorer from
-- weighted keywords (local). See complaint_ai_helpers.php, which also
-- applies all of this idempotently at runtime.
--
-- This originally also included a self-built local Naive Bayes classifier
-- (as a fallback for when the external API was unavailable), with its own
-- admin page (admin/admin_ai_tools.php) for training/testing it. Both were
-- removed - if the external API fails, a complaint just gets no AI-assigned
-- category rather than a lower-quality local guess. Drop its tables on any
-- database that still has them from before this change.
DROP TABLE IF EXISTS ai_training_examples;
DROP TABLE IF EXISTS ai_word_stats;
DROP TABLE IF EXISTS ai_model_meta;

ALTER TABLE complaints ADD COLUMN IF NOT EXISTS ai_suggested_category_id INT NULL DEFAULT NULL;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS ai_suggestion_confidence DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS urgency_score INT NOT NULL DEFAULT 0;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS urgency_level ENUM('low','medium','high') NULL DEFAULT NULL;

-- AI-based multilingual classification (English/Tagalog/Bisaya/mixed) - see
-- complaint_groq_helpers.php. ai_classification_source records which
-- provider (currently "groq") produced ai_suggested_category_id/
-- ai_suggestion_confidence, or NULL if the API call failed and no category
-- was assigned. Kept as a plain VARCHAR rather than an ENUM so switching
-- providers again later (as happened once already: Gemini -> Groq) never
-- requires an ALTER.
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS ai_detected_language ENUM('english','tagalog','bisaya','mixed','unknown') NULL DEFAULT NULL;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS ai_classification_source VARCHAR(20) NULL DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN ai_classification_source VARCHAR(20) NULL DEFAULT NULL;
