-- Academic Calendar: Admin-configured school year and semester date ranges.
--
-- Replaces the old fixed assumption of "August 1 = start of school year,
-- January 1 = start of 2nd semester" with dates the Admin sets per school
-- year in Settings > School Year. See Ui-main/school_year_helpers.php for
-- the matching PHP logic (sy_find_academic_calendar_for_date,
-- sy_label_for_date, semester_label_for_date). When no row here covers a
-- given date, that logic falls back to the legacy Aug 1 - Jul 31 /
-- Aug-Dec / Jan-Jul calculation so existing behavior never changes for
-- school years the Admin hasn't configured yet.
--
-- Safe to re-run: CREATE TABLE only acts if the table doesn't already exist.
-- This table is also created automatically at runtime by
-- sy_ensure_academic_calendar_table() the first time it's needed, so
-- running this file by hand is optional - it's kept for reference and for
-- environments that prefer applying migrations manually.

CREATE TABLE IF NOT EXISTS `academic_calendars` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `school_year` VARCHAR(9) NOT NULL,
    `sy_start_date` DATE NOT NULL,
    `sy_end_date` DATE NOT NULL,
    `sem1_start_date` DATE NOT NULL,
    `sem1_end_date` DATE NOT NULL,
    `sem2_start_date` DATE NOT NULL,
    `sem2_end_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_academic_calendar_school_year` (`school_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
