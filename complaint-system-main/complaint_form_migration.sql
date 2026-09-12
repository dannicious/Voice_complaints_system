-- =====================================================
-- Database Migration: New Complaint Form Structure
-- Based on BISSU Official Complaint Form
-- Date: May 4, 2026
-- =====================================================

-- Backup old complaints table (optional)
-- RENAME TABLE complaints TO complaints_old;

-- Drop old complaints table and related constraints
ALTER TABLE complaint_reactions DROP FOREIGN KEY complaint_reactions_ibfk_1;
ALTER TABLE complaints DROP FOREIGN KEY complaints_ibfk_1;
ALTER TABLE complaints DROP FOREIGN KEY complaints_ibfk_2;
ALTER TABLE complaints DROP FOREIGN KEY complaints_ibfk_3;
ALTER TABLE complaints DROP FOREIGN KEY fk_complaints_admin;

DROP TABLE complaint_reactions;
DROP TABLE complaints;

-- =====================================================
-- New Complaints Table (Formal Complaint Form Structure)
-- =====================================================
CREATE TABLE `complaints` (
  `id` int(11) NOT NULL,
  `ticket_no` varchar(20) DEFAULT NULL UNIQUE,
  
  -- Complainant (Person Filing)
  `student_id` int(11) DEFAULT NULL,
  `complainant_name` varchar(100) NOT NULL,
  `complainant_address` text NOT NULL,
  `complainant_sex` enum('male','female','other') DEFAULT NULL,
  `complainant_age` int(3) DEFAULT NULL,
  `complainant_civil_status` varchar(50) DEFAULT NULL COMMENT 'single, married, widowed, separated, etc',
  `complainant_contact_details` varchar(255) DEFAULT NULL,
  
  -- Complaint Target
  `person_complained_of` varchar(100) NOT NULL COMMENT 'Name/Title of person/office being complained about',
  
  -- Incident Details
  `date_of_incident` date NOT NULL,
  `place_of_incident` varchar(255) NOT NULL,
  `time_of_incident` time DEFAULT NULL,
  `act_complained_of` text NOT NULL COMMENT 'Act/s Complained of / Details of Complaint',
  `narrative_report` longtext NOT NULL COMMENT 'Detailed narrative report of complaint',
  
  -- Classification
  `college_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  
  -- Evidence & Documentation
  `proof_of_complaint` text DEFAULT NULL COMMENT 'Documents/Evidence/Witnesses (JSON or comma-separated)',
  `attachments` varchar(255) DEFAULT NULL COMMENT 'File attachments path',
  
  -- Desired Outcome
  `desired_outcome` text DEFAULT NULL COMMENT 'What outcome would you like to have/expect?',
  
  -- Agreement & Signature
  `terms_agreement_accepted` tinyint(1) DEFAULT 1,
  `signature` varchar(255) DEFAULT NULL COMMENT 'Path to signature image if captured digitally',
  
  -- Processing
  `status` enum('new','pending','under_review','resolved','closed') DEFAULT 'new',
  `is_anonymous` tinyint(1) DEFAULT 0,
  `visibility_status` varchar(50) DEFAULT 'private' COMMENT 'private, public',
  `approval_status` varchar(50) DEFAULT 'pending' COMMENT 'pending, approved, rejected',
  `admin_id` int(11) DEFAULT NULL COMMENT 'Admin who reviewed',
  `admin_notes` text DEFAULT NULL,
  `admin_reviewed_at` timestamp NULL DEFAULT NULL,
  
  -- Metadata
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- Complaint Reactions Table (for public complaints)
-- =====================================================
CREATE TABLE `complaint_reactions` (
  `id` int(11) NOT NULL,
  `complaint_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `reaction_type` varchar(20) NOT NULL COMMENT 'agree, disagree, helpful',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- Indexes
-- =====================================================
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_no` (`ticket_no`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `college_id` (`college_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date_of_incident` (`date_of_incident`),
  ADD KEY `idx_created_at` (`created_at`);

ALTER TABLE `complaint_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reaction` (`complaint_id`,`student_id`),
  ADD KEY `complaint_id` (`complaint_id`),
  ADD KEY `student_id` (`student_id`);

ALTER TABLE `complaint_categories`
  ADD COLUMN `route` enum('general','college') DEFAULT NULL AFTER `name`;

ALTER TABLE `complaint_categories`
  ADD COLUMN `category_type` enum('general','college') DEFAULT NULL AFTER `route`;

UPDATE `complaint_categories`
  SET `category_type` = `route`
  WHERE `category_type` IS NULL AND `route` IS NOT NULL;

-- =====================================================
-- AUTO_INCREMENT
-- =====================================================
ALTER TABLE `complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `complaint_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

-- =====================================================
-- Foreign Key Constraints
-- =====================================================
ALTER TABLE `complaints`
  ADD CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_profiles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `complaints_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `complaint_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `complaints_ibfk_4` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `complaint_reactions`
  ADD CONSTRAINT `complaint_reactions_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `complaint_reactions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `student_profiles` (`id`) ON DELETE CASCADE;

-- =====================================================
-- Migration Complete
-- =====================================================
-- All tables have been recreated with the new formal complaint form structure.
-- The complaints table now captures all required fields from the BISSU Complaint Form.
