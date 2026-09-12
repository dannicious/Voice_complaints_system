-- Semester support for student complaints/suggestions.
--
-- Convention: within the Aug 1 - Jul 31 school year (see
-- school_year_migration.sql), August-December is the 1st semester and
-- January-July is the 2nd. See Ui-main/school_year_helpers.php for the
-- matching PHP logic (semester_label_for_date).
--
-- Safe to re-run: each statement only acts if its target doesn't already
-- exist / isn't already set.

ALTER TABLE `complaints`
    ADD COLUMN IF NOT EXISTS `semester` ENUM('1','2') DEFAULT NULL AFTER `school_year`;

ALTER TABLE `suggestions`
    ADD COLUMN IF NOT EXISTS `semester` ENUM('1','2') DEFAULT NULL AFTER `school_year`;

CREATE INDEX IF NOT EXISTS `idx_complaints_student_semester` ON `complaints` (`student_id`, `school_year`, `semester`);
CREATE INDEX IF NOT EXISTS `idx_suggestions_student_semester` ON `suggestions` (`student_id`, `school_year`, `semester`);

-- Backfill existing rows from their created_at, using the Aug-Dec / Jan-Jul rule.
UPDATE `complaints`
SET `semester` = IF(MONTH(`created_at`) >= 8, '1', '2')
WHERE `semester` IS NULL;

UPDATE `suggestions`
SET `semester` = IF(MONTH(`created_at`) >= 8, '1', '2')
WHERE `semester` IS NULL;
