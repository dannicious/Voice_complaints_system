-- Terms of Agreement acceptance flag for student suggestions.
--
-- The suggestion form has always required this checkbox (see
-- Ui-main/student/student_complaints.php, suggestion "TERMS & AGREEMENT
-- SECTION", and the required-fields check in
-- Ui-main/student/student_submission_preview.php), but the accepted value
-- was never actually saved anywhere - Ui-main/student/process_suggestion.php
-- didn't have a column to put it in. Mirrors the column that already exists
-- on `complaints` (see complaint_form_migration.sql) so suggestion records
-- can show the same "Terms of Agreement" detail complaints already do.
--
-- Safe to re-run: only acts if the column doesn't already exist. Defaults
-- existing rows to 1 (agreed), matching the fact the checkbox has always
-- been required to submit the form in the first place.

ALTER TABLE `suggestions`
    ADD COLUMN IF NOT EXISTS `terms_agreement_accepted` TINYINT(1) DEFAULT 1 AFTER `expected_outcome`;
