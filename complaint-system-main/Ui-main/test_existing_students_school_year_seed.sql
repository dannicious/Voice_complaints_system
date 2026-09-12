-- VOICE School Year filter test data for EXISTING students only.
-- This file intentionally contains no INSERT/UPDATE/DELETE against student_profiles.
-- It is rerunnable: ticket_no guards prevent duplicate test records.

START TRANSACTION;

INSERT INTO complaints (
    ticket_no, student_id, complainant_name, complainant_address, complainant_sex,
    complainant_age, complainant_civil_status, complainant_contact_details,
    person_complained_of, date_of_incident, time_of_incident, place_of_incident,
    act_complained_of, narrative_report, college_id, category_id, desired_outcome,
    terms_agreement_accepted, status, approval_status, visibility_status,
    school_year, created_at, reported_type
)
SELECT seed.ticket_no, sp.id, CONCAT(sp.first_name, ' ', sp.last_name),
       'Dummy test address', sp.gender, 20, 'single', sp.contact_number,
       'Dummy test campus service', seed.submitted_on, '09:00:00', 'Dummy test area',
       seed.act_complained_of, seed.narrative_report, sp.college_id, seed.category_id,
       'Please review this dummy test complaint.', 1, 'new', 'approved', 'private',
       seed.school_year, CONCAT(seed.submitted_on, ' 09:00:00'), 'student'
FROM (
    SELECT 'TEST-EX-C01' AS ticket_no, '125347' AS student_number, '2023-09-15' AS submitted_on, '2023-2024' AS school_year, 55 AS category_id, 'Dummy test concern about a campus facility.' AS act_complained_of, 'Dummy historical complaint for School Year filter testing.' AS narrative_report
    UNION ALL SELECT 'TEST-EX-C02', '700001', '2023-10-10', '2023-2024', 56, 'Dummy test concern about grading feedback.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C03', '700002', '2024-02-10', '2023-2024', 58, 'Dummy test concern about fair treatment.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C04', '700003', '2024-03-15', '2023-2024', 55, 'Dummy test concern about library access.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C05', '700004', '2024-05-20', '2023-2024', 56, 'Dummy test concern about assessment instructions.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C06', '700005', '2024-08-15', '2024-2025', 55, 'Dummy test concern about a classroom facility.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C07', '700006', '2024-09-15', '2024-2025', 56, 'Dummy test concern about unclear academic feedback.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C08', '700007', '2024-11-20', '2024-2025', 58, 'Dummy test concern about inconsistent service.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C09', '700008', '2025-02-10', '2024-2025', 55, 'Dummy test concern about study-area availability.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C10', '700009', '2025-05-20', '2024-2025', 56, 'Dummy test concern about an academic process.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C11', '700010', '2025-08-20', '2025-2026', 55, 'Dummy test concern about laboratory access.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C12', '700011', '2025-09-15', '2025-2026', 56, 'Dummy test concern about classroom resources.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C13', '700012', '2025-11-20', '2025-2026', 58, 'Dummy test concern about equal access to services.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C14', '700013', '2026-01-15', '2025-2026', 55, 'Dummy test concern about technology support.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C15', '700014', '2026-03-10', '2025-2026', 56, 'Dummy test concern about examination instructions.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C16', '700015', '2026-08-20', '2026-2027', 55, 'Dummy test concern about current facility access.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C17', '700016', '2026-08-25', '2026-2027', 56, 'Dummy test concern about current course information.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C18', '700017', '2026-09-01', '2026-2027', 58, 'Dummy test concern about current student services.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C19', '700018', '2026-09-05', '2026-2027', 55, 'Dummy test concern about current library facilities.', 'Dummy historical complaint for School Year filter testing.'
    UNION ALL SELECT 'TEST-EX-C20', '700019', '2026-09-08', '2026-2027', 56, 'Dummy test concern about current academic support.', 'Dummy historical complaint for School Year filter testing.'
) AS seed
JOIN student_profiles sp ON sp.student_number = seed.student_number
WHERE NOT EXISTS (
    SELECT 1 FROM complaints existing WHERE existing.ticket_no = seed.ticket_no
);

INSERT INTO suggestions (
    ticket_no, student_id, college_id, category_id, date_of_suggestion,
    subject, description, expected_outcome, status, school_year, created_at
)
SELECT seed.ticket_no, sp.id, sp.college_id, seed.category_id, seed.submitted_on,
       seed.subject, seed.description, 'A clearer and more accessible student service.',
       'approved', seed.school_year, CONCAT(seed.submitted_on, ' 10:00:00')
FROM (
    SELECT 'TEST-EX-S01' AS ticket_no, '125347' AS student_number, '2023-09-20' AS submitted_on, '2023-2024' AS school_year, 24 AS category_id, 'Improve library seating' AS subject, 'Dummy suggestion: add more quiet seating areas in the library.' AS description
    UNION ALL SELECT 'TEST-EX-S02', '700001', '2023-10-15', '2023-2024', 25, 'Publish campus activity dates', 'Dummy suggestion: publish a single calendar for campus activities.'
    UNION ALL SELECT 'TEST-EX-S03', '700002', '2024-02-10', '2023-2024', 24, 'Improve cleanliness supplies', 'Dummy suggestion: make cleaning supplies more visible in shared areas.'
    UNION ALL SELECT 'TEST-EX-S04', '700003', '2024-03-20', '2023-2024', 25, 'Add student service reminders', 'Dummy suggestion: send reminders for common student service deadlines.'
    UNION ALL SELECT 'TEST-EX-S05', '700004', '2024-05-25', '2023-2024', 24, 'Create a library feedback form', 'Dummy suggestion: provide a simple form for library service feedback.'
    UNION ALL SELECT 'TEST-EX-S06', '700005', '2024-08-20', '2024-2025', 25, 'Improve orientation activities', 'Dummy suggestion: include a schedule of orientation and campus activities.'
    UNION ALL SELECT 'TEST-EX-S07', '700006', '2024-09-20', '2024-2025', 24, 'Add study-area schedules', 'Dummy suggestion: display available study-area schedules online.'
    UNION ALL SELECT 'TEST-EX-S08', '700007', '2024-11-25', '2024-2025', 25, 'Improve student announcements', 'Dummy suggestion: centralize student service and activity announcements.'
    UNION ALL SELECT 'TEST-EX-S09', '700008', '2025-02-10', '2024-2025', 24, 'Add academic resource shelves', 'Dummy suggestion: provide a clearly labeled shelf for academic resources.'
    UNION ALL SELECT 'TEST-EX-S10', '700009', '2025-05-25', '2024-2025', 25, 'Add technology help sessions', 'Dummy suggestion: offer scheduled technology help sessions for students.'
    UNION ALL SELECT 'TEST-EX-S11', '700010', '2025-08-25', '2025-2026', 24, 'Improve laboratory availability notices', 'Dummy suggestion: post laboratory availability and maintenance notices.'
    UNION ALL SELECT 'TEST-EX-S12', '700011', '2025-09-20', '2025-2026', 25, 'Add campus activity sign-up details', 'Dummy suggestion: show sign-up steps and deadlines for campus activities.'
    UNION ALL SELECT 'TEST-EX-S13', '700012', '2025-11-25', '2025-2026', 24, 'Improve campus cleanliness reporting', 'Dummy suggestion: provide a simple way to report cleanliness concerns.'
    UNION ALL SELECT 'TEST-EX-S14', '700013', '2026-01-15', '2025-2026', 25, 'Create a student services guide', 'Dummy suggestion: publish a guide covering common student services.'
    UNION ALL SELECT 'TEST-EX-S15', '700014', '2026-03-15', '2025-2026', 24, 'Add quiet study hours', 'Dummy suggestion: designate quiet study hours in shared facilities.'
    UNION ALL SELECT 'TEST-EX-S16', '700015', '2026-08-20', '2026-2027', 25, 'Publish the current activity calendar', 'Dummy suggestion: publish the current school-year activity calendar.'
    UNION ALL SELECT 'TEST-EX-S17', '700016', '2026-08-25', '2026-2027', 24, 'Add current library service updates', 'Dummy suggestion: show current library hours and service updates.'
    UNION ALL SELECT 'TEST-EX-S18', '700017', '2026-09-01', '2026-2027', 25, 'Improve current student service instructions', 'Dummy suggestion: make current student service instructions easier to find.'
    UNION ALL SELECT 'TEST-EX-S19', '700018', '2026-09-05', '2026-2027', 24, 'Add current facility availability notices', 'Dummy suggestion: display current facility availability in one location.'
    UNION ALL SELECT 'TEST-EX-S20', '700019', '2026-09-08', '2026-2027', 25, 'Add current technology support hours', 'Dummy suggestion: publish current technology support hours and contact steps.'
) AS seed
JOIN student_profiles sp ON sp.student_number = seed.student_number
WHERE NOT EXISTS (
    SELECT 1 FROM suggestions existing WHERE existing.ticket_no = seed.ticket_no
);

COMMIT;

-- Verification queries. Test rows are isolated by the TEST-EX- ticket prefix.
SELECT school_year, COUNT(*) AS complaint_count
FROM complaints
WHERE ticket_no LIKE 'TEST-EX-C%'
GROUP BY school_year ORDER BY school_year;

SELECT school_year, COUNT(*) AS suggestion_count
FROM suggestions
WHERE ticket_no LIKE 'TEST-EX-S%'
GROUP BY school_year ORDER BY school_year;

SELECT c.ticket_no, c.school_year, c.created_at, sp.id AS student_id,
       sp.student_number, sp.first_name, sp.last_name, sp.status
FROM complaints c
JOIN student_profiles sp ON sp.id = c.student_id
WHERE c.ticket_no LIKE 'TEST-EX-C%'
ORDER BY c.school_year, c.created_at;

SELECT s.ticket_no, s.school_year, s.date_of_suggestion, s.created_at,
       sp.id AS student_id, sp.student_number, sp.first_name, sp.last_name, sp.status
FROM suggestions s
JOIN student_profiles sp ON sp.id = s.student_id
WHERE s.ticket_no LIKE 'TEST-EX-S%'
ORDER BY s.school_year, s.date_of_suggestion;

-- School Year filter simulations: replace the value in each query as needed.
-- SELECT c.ticket_no, c.school_year, c.created_at, sp.student_number,
--        sp.first_name, sp.last_name, sp.status
-- FROM complaints c JOIN student_profiles sp ON sp.id = c.student_id
-- WHERE c.school_year = '2024-2025' AND c.ticket_no LIKE 'TEST-EX-C%'
-- ORDER BY c.created_at;
-- SELECT s.ticket_no, s.school_year, s.date_of_suggestion, sp.student_number,
--        sp.first_name, sp.last_name, sp.status
-- FROM suggestions s JOIN student_profiles sp ON sp.id = s.student_id
-- WHERE s.school_year = '2024-2025' AND s.ticket_no LIKE 'TEST-EX-S%'
-- ORDER BY s.date_of_suggestion;