-- VOICE School Year filter test data.
-- Uses only existing tables and columns. Safe to run more than once.
-- Dummy users are marked with TEST-SY- in username and email.

START TRANSACTION;

-- Create student login rows without changing existing users.
INSERT INTO users (username, email, password, role, is_active)
SELECT seed.username, seed.email, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'student', 1
FROM (
    SELECT 'TEST-SY-G01' AS username, 'test-sy-g01@example.invalid' AS email
    UNION ALL SELECT 'TEST-SY-G02', 'test-sy-g02@example.invalid'
    UNION ALL SELECT 'TEST-SY-G03', 'test-sy-g03@example.invalid'
    UNION ALL SELECT 'TEST-SY-G04', 'test-sy-g04@example.invalid'
    UNION ALL SELECT 'TEST-SY-G05', 'test-sy-g05@example.invalid'
    UNION ALL SELECT 'TEST-SY-A01', 'test-sy-a01@example.invalid'
    UNION ALL SELECT 'TEST-SY-A02', 'test-sy-a02@example.invalid'
    UNION ALL SELECT 'TEST-SY-A03', 'test-sy-a03@example.invalid'
    UNION ALL SELECT 'TEST-SY-A04', 'test-sy-a04@example.invalid'
    UNION ALL SELECT 'TEST-SY-A05', 'test-sy-a05@example.invalid'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users existing WHERE existing.username = seed.username);

-- Graduated records use the existing inactive status and retain their last school year.
INSERT INTO student_profiles (
    user_id, student_number, first_name, last_name, middle_name, gender,
    contact_number, college_id, year_level, section, school_year, status, program_id
)
SELECT u.id, seed.student_number, seed.first_name, seed.last_name, seed.middle_name,
       seed.gender, seed.contact_number, seed.college_id, seed.year_level, seed.section,
       seed.school_year, 'inactive', seed.program_id
FROM (
    SELECT 'TEST-SY-G01' AS username, 'TEST-SY-G001' AS student_number, 'Ari' AS first_name, 'Santos' AS last_name, 'Miguel' AS middle_name, 'male' AS gender, '09000000001' AS contact_number, 1 AS college_id, 4 AS year_level, 'A' AS section, '2023-2024' AS school_year, 1 AS program_id
    UNION ALL SELECT 'TEST-SY-G02', 'TEST-SY-G002', 'Bea', 'Reyes', 'Luna', 'female', '09000000002', 1, 4, 'B', '2024-2025', 2
    UNION ALL SELECT 'TEST-SY-G03', 'TEST-SY-G003', 'Carlo', 'Garcia', 'Dizon', 'male', '09000000003', 2, 4, 'A', '2024-2025', 3
    UNION ALL SELECT 'TEST-SY-G04', 'TEST-SY-G004', 'Dana', 'Cruz', 'Mendoza', 'female', '09000000004', 3, 4, 'C', '2025-2026', 5
    UNION ALL SELECT 'TEST-SY-G05', 'TEST-SY-G005', 'Eli', 'Navarro', 'Ramos', 'other', '09000000005', 2, 4, 'B', '2025-2026', 8
) AS seed
JOIN users u ON u.username = seed.username
WHERE NOT EXISTS (SELECT 1 FROM student_profiles existing WHERE existing.student_number = seed.student_number);

-- Active records intentionally have both historical and current-school-year submissions.
INSERT INTO student_profiles (
    user_id, student_number, first_name, last_name, middle_name, gender,
    contact_number, college_id, year_level, section, school_year, status, program_id
)
SELECT u.id, seed.student_number, seed.first_name, seed.last_name, seed.middle_name,
       seed.gender, seed.contact_number, seed.college_id, seed.year_level, seed.section,
       '2026-2027', 'active', seed.program_id
FROM (
    SELECT 'TEST-SY-A01' AS username, 'TEST-SY-A001' AS student_number, 'Faye' AS first_name, 'Molina' AS last_name, 'Rae' AS middle_name, 'female' AS gender, '09000000011' AS contact_number, 1 AS college_id, 3 AS year_level, 'A' AS section, 1 AS program_id
    UNION ALL SELECT 'TEST-SY-A02', 'TEST-SY-A002', 'Gio', 'Villanueva', 'Lee', 'male', '09000000012', 1, 3, 'B', 2
    UNION ALL SELECT 'TEST-SY-A03', 'TEST-SY-A003', 'Hana', 'Torres', 'Mae', 'female', '09000000013', 2, 2, 'A', 3
    UNION ALL SELECT 'TEST-SY-A04', 'TEST-SY-A004', 'Ian', 'Flores', 'Kai', 'male', '09000000014', 3, 2, 'B', 5
    UNION ALL SELECT 'TEST-SY-A05', 'TEST-SY-A005', 'Jessa', 'Lim', 'Anne', 'female', '09000000015', 2, 1, 'C', 8
) AS seed
JOIN users u ON u.username = seed.username
WHERE NOT EXISTS (SELECT 1 FROM student_profiles existing WHERE existing.student_number = seed.student_number);

-- One complaint and one suggestion for every graduated student.
INSERT INTO complaints (
    ticket_no, student_id, complainant_name, complainant_address, complainant_sex,
    complainant_age, complainant_civil_status, complainant_contact_details,
    person_complained_of, date_of_incident, time_of_incident, place_of_incident,
    act_complained_of, narrative_report, college_id, category_id, desired_outcome,
    terms_agreement_accepted, status, approval_status, visibility_status, school_year, created_at
)
SELECT seed.ticket_no, sp.id, CONCAT(sp.first_name, ' ', sp.last_name), 'Test address only', sp.gender,
       22, 'single', sp.contact_number, 'Test campus service', seed.incident_date, '09:00:00',
       'Test campus area', seed.act_text, seed.narrative, sp.college_id, 56,
       'Please review this test record.', 1, 'resolved', 'approved', 'private', seed.school_year, seed.created_at
FROM (
    SELECT 'TEST-C-G01' AS ticket_no, 'TEST-SY-G001' AS student_number, '2023-09-15' AS incident_date, 'Access to a campus facility was delayed.' AS act_text, 'Dummy historical complaint for 2023-2024.' AS narrative, '2023-2024' AS school_year, '2023-09-15 10:00:00' AS created_at
    UNION ALL SELECT 'TEST-C-G02', 'TEST-SY-G002', '2024-09-15', 'A classroom resource needed maintenance.', 'Dummy historical complaint for 2024-2025.', '2024-2025', '2024-09-15 10:00:00'
    UNION ALL SELECT 'TEST-C-G03', 'TEST-SY-G003', '2025-02-10', 'A student service response took too long.', 'Dummy historical complaint for 2024-2025.', '2024-2025', '2025-02-10 10:00:00'
    UNION ALL SELECT 'TEST-C-G04', 'TEST-SY-G004', '2025-08-20', 'A campus facility needed attention.', 'Dummy historical complaint for 2025-2026.', '2025-2026', '2025-08-20 10:00:00'
    UNION ALL SELECT 'TEST-C-G05', 'TEST-SY-G005', '2026-01-15', 'A process required clearer instructions.', 'Dummy historical complaint for 2025-2026.', '2025-2026', '2026-01-15 10:00:00'
) AS seed
JOIN student_profiles sp ON sp.student_number = seed.student_number
WHERE NOT EXISTS (SELECT 1 FROM complaints existing WHERE existing.ticket_no = seed.ticket_no);

INSERT INTO suggestions (
    ticket_no, student_id, college_id, category_id, date_of_suggestion, subject,
    description, expected_outcome, status, school_year, created_at
)
SELECT seed.ticket_no, sp.id, sp.college_id, 25, seed.suggestion_date, seed.subject,
       seed.description, 'A clearer, more accessible service for students.', 'approved', seed.school_year, seed.created_at
FROM (
    SELECT 'TEST-S-G01' AS ticket_no, 'TEST-SY-G001' AS student_number, '2024-02-10' AS suggestion_date, 'Improve facility booking information' AS subject, 'Publish a simple schedule for shared campus facilities.' AS description, '2023-2024' AS school_year, '2024-02-10 10:00:00' AS created_at
    UNION ALL SELECT 'TEST-S-G02', 'TEST-SY-G002', '2025-02-10', 'Add classroom maintenance reporting', 'Provide a small reporting channel for classroom maintenance needs.', '2024-2025', '2025-02-10 10:00:00'
    UNION ALL SELECT 'TEST-S-G03', 'TEST-SY-G003', '2025-05-20', 'Improve student service queue updates', 'Show expected waiting times for common student services.', '2024-2025', '2025-05-20 10:00:00'
    UNION ALL SELECT 'TEST-S-G04', 'TEST-SY-G004', '2026-01-15', 'Publish service process checklists', 'Make the requirements for common requests easy to find.', '2025-2026', '2026-01-15 10:00:00'
    UNION ALL SELECT 'TEST-S-G05', 'TEST-SY-G005', '2026-03-10', 'Add more student consultation hours', 'Offer additional consultation times during busy periods.', '2025-2026', '2026-03-10 10:00:00'
) AS seed
JOIN student_profiles sp ON sp.student_number = seed.student_number
WHERE NOT EXISTS (SELECT 1 FROM suggestions existing WHERE existing.ticket_no = seed.ticket_no);

-- Two complaints and two suggestions per active student: one prior year and one current year.
INSERT INTO complaints (
    ticket_no, student_id, complainant_name, complainant_address, complainant_sex,
    complainant_age, complainant_civil_status, complainant_contact_details,
    person_complained_of, date_of_incident, time_of_incident, place_of_incident,
    act_complained_of, narrative_report, college_id, category_id, desired_outcome,
    terms_agreement_accepted, status, approval_status, visibility_status, school_year, created_at
)
SELECT seed.ticket_no, sp.id, CONCAT(sp.first_name, ' ', sp.last_name), 'Test address only', sp.gender,
       20, 'single', sp.contact_number, 'Test campus service', seed.incident_date, '11:00:00',
       'Test campus area', 'A dummy test concern.', 'Dummy complaint for School Year filter testing.', sp.college_id, 55,
       'Please review this test record.', 1, 'resolved', 'approved', 'private', seed.school_year, seed.created_at
FROM (
    SELECT 'TEST-C-A01H' AS ticket_no, 'TEST-SY-A001' AS student_number, '2025-08-20' AS incident_date, '2025-2026' AS school_year, '2025-08-20 11:00:00' AS created_at
    UNION ALL SELECT 'TEST-C-A01C', 'TEST-SY-A001', '2026-08-20', '2026-2027', '2026-08-20 11:00:00'
    UNION ALL SELECT 'TEST-C-A02H', 'TEST-SY-A002', '2024-09-15', '2024-2025', '2024-09-15 11:00:00'
    UNION ALL SELECT 'TEST-C-A02C', 'TEST-SY-A002', '2026-08-20', '2026-2027', '2026-08-20 11:00:00'
    UNION ALL SELECT 'TEST-C-A03H', 'TEST-SY-A003', '2025-02-10', '2024-2025', '2025-02-10 11:00:00'
    UNION ALL SELECT 'TEST-C-A03C', 'TEST-SY-A003', '2026-08-20', '2026-2027', '2026-08-20 11:00:00'
    UNION ALL SELECT 'TEST-C-A04H', 'TEST-SY-A004', '2025-08-20', '2025-2026', '2025-08-20 11:00:00'
    UNION ALL SELECT 'TEST-C-A04C', 'TEST-SY-A004', '2026-08-20', '2026-2027', '2026-08-20 11:00:00'
    UNION ALL SELECT 'TEST-C-A05H', 'TEST-SY-A005', '2025-05-20', '2024-2025', '2025-05-20 11:00:00'
    UNION ALL SELECT 'TEST-C-A05C', 'TEST-SY-A005', '2026-08-20', '2026-2027', '2026-08-20 11:00:00'
) AS seed
JOIN student_profiles sp ON sp.student_number = seed.student_number
WHERE NOT EXISTS (SELECT 1 FROM complaints existing WHERE existing.ticket_no = seed.ticket_no);

INSERT INTO suggestions (
    ticket_no, student_id, college_id, category_id, date_of_suggestion, subject,
    description, expected_outcome, status, school_year, created_at
)
SELECT seed.ticket_no, sp.id, sp.college_id, 24, seed.suggestion_date, seed.subject,
       'This is a clearly marked dummy suggestion for filter testing.', 'A clearer, more accessible service for students.', 'approved', seed.school_year, seed.created_at
FROM (
    SELECT 'TEST-S-A01H' AS ticket_no, 'TEST-SY-A001' AS student_number, '2026-01-15' AS suggestion_date, 'Improve study area access' AS subject, '2025-2026' AS school_year, '2026-01-15 12:00:00' AS created_at
    UNION ALL SELECT 'TEST-S-A01C', 'TEST-SY-A001', '2026-09-05', 'Add current study area updates', '2026-2027', '2026-09-05 12:00:00'
    UNION ALL SELECT 'TEST-S-A02H', 'TEST-SY-A002', '2025-02-10', 'Improve event announcements', '2024-2025', '2025-02-10 12:00:00'
    UNION ALL SELECT 'TEST-S-A02C', 'TEST-SY-A002', '2026-09-05', 'Publish current event calendar', '2026-2027', '2026-09-05 12:00:00'
    UNION ALL SELECT 'TEST-S-A03H', 'TEST-SY-A003', '2025-05-20', 'Add online service instructions', '2024-2025', '2025-05-20 12:00:00'
    UNION ALL SELECT 'TEST-S-A03C', 'TEST-SY-A003', '2026-09-05', 'Add current online service status', '2026-2027', '2026-09-05 12:00:00'
    UNION ALL SELECT 'TEST-S-A04H', 'TEST-SY-A004', '2026-01-15', 'Improve campus cleanliness notices', '2025-2026', '2026-01-15 12:00:00'
    UNION ALL SELECT 'TEST-S-A04C', 'TEST-SY-A004', '2026-09-05', 'Add current cleanliness schedule', '2026-2027', '2026-09-05 12:00:00'
    UNION ALL SELECT 'TEST-S-A05H', 'TEST-SY-A005', '2025-08-20', 'Improve technology lab access', '2025-2026', '2025-08-20 12:00:00'
    UNION ALL SELECT 'TEST-S-A05C', 'TEST-SY-A005', '2026-09-05', 'Add current lab availability updates', '2026-2027', '2026-09-05 12:00:00'
) AS seed
JOIN student_profiles sp ON sp.student_number = seed.student_number
WHERE NOT EXISTS (SELECT 1 FROM suggestions existing WHERE existing.ticket_no = seed.ticket_no);

COMMIT;

-- Verification: counts by stored School Year.
SELECT school_year, COUNT(*) AS complaint_count
FROM complaints WHERE ticket_no LIKE 'TEST-C-%'
GROUP BY school_year ORDER BY school_year;

SELECT school_year, COUNT(*) AS suggestion_count
FROM suggestions WHERE ticket_no LIKE 'TEST-S-%'
GROUP BY school_year ORDER BY school_year;

-- Verification: graduated and active students with historical records.
SELECT sp.student_number, sp.first_name, sp.last_name, sp.status,
       COUNT(DISTINCT c.id) AS complaints, COUNT(DISTINCT s.id) AS suggestions
FROM student_profiles sp
LEFT JOIN complaints c ON c.student_id = sp.id AND c.ticket_no LIKE 'TEST-%'
LEFT JOIN suggestions s ON s.student_id = sp.id AND s.ticket_no LIKE 'TEST-%'
WHERE sp.student_number LIKE 'TEST-SY-%'
GROUP BY sp.id, sp.student_number, sp.first_name, sp.last_name, sp.status
ORDER BY sp.student_number;

-- Filter simulation: replace the value with 2023-2024, 2024-2025, 2025-2026, or 2026-2027.
-- SELECT c.ticket_no, c.school_year, sp.student_number, sp.first_name, sp.last_name
-- FROM complaints c JOIN student_profiles sp ON sp.id = c.student_id
-- WHERE c.school_year = '2024-2025' AND c.ticket_no LIKE 'TEST-C-%' ORDER BY c.created_at;
-- SELECT s.ticket_no, s.school_year, sp.student_number, sp.first_name, sp.last_name
-- FROM suggestions s JOIN student_profiles sp ON sp.id = s.student_id
-- WHERE s.school_year = '2024-2025' AND s.ticket_no LIKE 'TEST-S-%' ORDER BY s.date_of_suggestion;