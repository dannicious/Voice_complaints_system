-- School Year support for student complaints/suggestions.
--
-- Convention: the Philippine SUC academic year runs August 1 - July 31, so a
-- record created on any date in that window belongs to school year
-- "<start year>-<start year + 1>" (e.g. 2026-07-13 -> "2025-2026").
-- See Ui-main/school_year_helpers.php for the matching PHP logic.
--
-- Safe to re-run: each statement only acts if its target doesn't already
-- exist / isn't already set.

ALTER TABLE `complaints`
    ADD COLUMN IF NOT EXISTS `school_year` VARCHAR(9) DEFAULT NULL AFTER `created_at`;

ALTER TABLE `suggestions`
    ADD COLUMN IF NOT EXISTS `school_year` VARCHAR(9) DEFAULT NULL AFTER `created_at`;

CREATE INDEX IF NOT EXISTS `idx_complaints_student_school_year` ON `complaints` (`student_id`, `school_year`);
CREATE INDEX IF NOT EXISTS `idx_suggestions_student_school_year` ON `suggestions` (`student_id`, `school_year`);

-- Backfill existing rows from their created_at, using the Aug1-Jul31 rule.
UPDATE `complaints`
SET `school_year` = CONCAT(
    CASE WHEN MONTH(`created_at`) >= 8 THEN YEAR(`created_at`) ELSE YEAR(`created_at`) - 1 END,
    '-',
    CASE WHEN MONTH(`created_at`) >= 8 THEN YEAR(`created_at`) + 1 ELSE YEAR(`created_at`) END
)
WHERE `school_year` IS NULL;

UPDATE `suggestions`
SET `school_year` = CONCAT(
    CASE WHEN MONTH(`created_at`) >= 8 THEN YEAR(`created_at`) ELSE YEAR(`created_at`) - 1 END,
    '-',
    CASE WHEN MONTH(`created_at`) >= 8 THEN YEAR(`created_at`) + 1 ELSE YEAR(`created_at`) END
)
WHERE `school_year` IS NULL;
