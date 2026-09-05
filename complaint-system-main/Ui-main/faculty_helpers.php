<?php

/**
 * Faculty/Staff records.
 *
 * Faculty are only ever the *subject* of a complaint - they never log in and
 * never file complaints - so they live in their own table rather than in
 * `users`. Complaints about them route to the SAS Director (admin), while
 * complaints about students route to the dean of that student's college.
 */

require_once __DIR__ . '/student_bulk_upload.php'; // shared CSV helpers

/**
 * Creates the faculty tables and the complaint columns they need.
 * Safe to call on every request; mirrors ensure_call_slip_table().
 */
function ensure_faculty_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS faculty_staff (
                id INT(11) NOT NULL AUTO_INCREMENT,
                employee_number VARCHAR(30) DEFAULT NULL,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                middle_name VARCHAR(50) DEFAULT NULL,
                position VARCHAR(100) DEFAULT NULL,
                department VARCHAR(150) DEFAULT NULL,
                college_id INT(11) DEFAULT NULL,
                email VARCHAR(100) DEFAULT NULL,
                contact_number VARCHAR(20) DEFAULT NULL,
                status ENUM('active','inactive') DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_status (status),
                KEY idx_college (college_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS complaint_faculty_links (
                id INT(11) NOT NULL AUTO_INCREMENT,
                complaint_id INT(11) NOT NULL,
                faculty_id INT(11) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_complaint (complaint_id),
                KEY idx_faculty (faculty_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        // Records whether a complaint is about a student or a faculty/staff
        // member, which is what decides where it routes.
        $hasColumn = $pdo->query("SHOW COLUMNS FROM complaints LIKE 'reported_type'")->fetchColumn();
        if (!$hasColumn) {
            $pdo->exec(
                "ALTER TABLE complaints
                 ADD COLUMN reported_type ENUM('student','faculty') NOT NULL DEFAULT 'student'"
            );
        }

        // A Call Slip can now be issued to a faculty/staff member, who has no
        // student profile - so student_id becomes optional and faculty_id is
        // filled in instead. Only applies once call_slips exists.
        $callSlipsExists = $pdo->query("SHOW TABLES LIKE 'call_slips'")->fetchColumn();
        if ($callSlipsExists) {
            $hasFacultyId = $pdo->query("SHOW COLUMNS FROM call_slips LIKE 'faculty_id'")->fetchColumn();
            if (!$hasFacultyId) {
                $pdo->exec('ALTER TABLE call_slips MODIFY student_id INT UNSIGNED NULL');
                $pdo->exec('ALTER TABLE call_slips ADD COLUMN faculty_id INT UNSIGNED DEFAULT NULL');
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_faculty_tables: ' . $e->getMessage());
    }
}

/**
 * Records a Call Slip issued to a faculty/staff member.
 *
 * Mirrors issue_call_slip() for students, minus the in-app notification -
 * faculty have no account to notify, so the emailed slip is the only delivery.
 *
 * @return array{ok: bool, message: string}
 */
function issue_faculty_call_slip(PDO $pdo, int $ticketId, int $facultyId, string $issuedByRole, int $issuedByUserId): array
{
    try {
        ensure_faculty_tables($pdo);

        $facultyStmt = $pdo->prepare('SELECT id FROM faculty_staff WHERE id = :id LIMIT 1');
        $facultyStmt->execute([':id' => $facultyId]);
        if (!$facultyStmt->fetch()) {
            return ['ok' => false, 'message' => 'Faculty or staff record not found.'];
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO call_slips (ticket_type, ticket_id, student_id, faculty_id, issued_by_role, issued_by_user_id, status)
             VALUES (:ticket_type, :ticket_id, NULL, :faculty_id, :issued_by_role, :issued_by_user_id, :status)'
        );
        $insertStmt->execute([
            ':ticket_type' => 'complaint',
            ':ticket_id' => $ticketId,
            ':faculty_id' => $facultyId,
            ':issued_by_role' => $issuedByRole,
            ':issued_by_user_id' => $issuedByUserId > 0 ? $issuedByUserId : null,
            ':status' => 'issued',
        ]);

        return ['ok' => true, 'message' => 'Call Slip issued successfully.'];
    } catch (Throwable $e) {
        error_log('issue_faculty_call_slip: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Unable to issue call slip right now.'];
    }
}

/**
 * The faculty/staff member named in a complaint, if it is a faculty complaint.
 *
 * @return array{id:int,name:string,email:string}|null
 */
function complaint_reported_faculty(PDO $pdo, int $complaintId): ?array
{
    ensure_faculty_tables($pdo);

    try {
        $stmt = $pdo->prepare(
            'SELECT f.id, f.first_name, f.last_name, f.position, f.email
             FROM complaint_faculty_links cfl
             INNER JOIN faculty_staff f ON f.id = cfl.faculty_id
             WHERE cfl.complaint_id = :complaint_id
             ORDER BY cfl.created_at ASC, cfl.faculty_id ASC
             LIMIT 1'
        );
        $stmt->execute([':complaint_id' => $complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => trim(((string)$row['first_name']) . ' ' . ((string)$row['last_name'])),
            'email' => trim((string)($row['email'] ?? '')),
        ];
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Active faculty/staff for the complaint form picker.
 *
 * @return list<array<string,mixed>>
 */
function faculty_staff_options(PDO $pdo): array
{
    ensure_faculty_tables($pdo);

    try {
        $stmt = $pdo->query(
            'SELECT f.id, f.employee_number, f.first_name, f.last_name, f.position, f.department,
                    c.name AS college_name
             FROM faculty_staff f
             LEFT JOIN colleges c ON c.id = f.college_id
             WHERE f.status = \'active\'
             ORDER BY f.first_name ASC, f.last_name ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Display name for one faculty member, e.g. "Maria Santos (Instructor)".
 */
function faculty_display_name(array $faculty): string
{
    $name = trim(((string)($faculty['first_name'] ?? '')) . ' ' . ((string)($faculty['last_name'] ?? '')));
    $position = trim((string)($faculty['position'] ?? ''));

    return $position !== '' ? $name . ' (' . $position . ')' : $name;
}

/**
 * The CSV columns the faculty importer understands. Single source of truth for
 * the on-screen documentation and the downloadable template.
 *
 * @return array{required: array<string,string>, optional: array<string,string>}
 */
function faculty_bulk_upload_columns(): array
{
    return [
        'required' => [
            'first_name' => 'Given name.',
            'last_name' => 'Surname.',
        ],
        'optional' => [
            'middle_name' => 'Middle name.',
            'employee_number' => 'Employee or ID number. Must be unique when given.',
            'position' => 'Job title, e.g. Instructor or Registrar Staff.',
            'department' => 'Department or office, e.g. Registrar.',
            'college' => 'College code or full name, if they belong to one.',
            'email' => 'Email address. Needed for Call Slips.',
            'contact_number' => 'Contact number.',
        ],
    ];
}

/**
 * Builds the downloadable CSV template: a header row plus one example row.
 */
function faculty_bulk_upload_template_csv(): string
{
    $columns = faculty_bulk_upload_columns();
    $headers = array_merge(array_keys($columns['required']), array_keys($columns['optional']));

    $example = [
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'middle_name' => 'Cruz',
        'employee_number' => 'EMP-0001',
        'position' => 'Instructor',
        'department' => 'College of Computing and Information Sciences',
        'college' => 'CCIS',
        'email' => 'maria.santos@bisu.edu.ph',
        'contact_number' => '09123456789',
    ];

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers);
    fputcsv($handle, array_map(static fn(string $column): string => $example[$column] ?? '', $headers));
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return (string)$csv;
}

/**
 * Imports faculty/staff from an uploaded CSV file.
 *
 * Rows that are invalid or already present are skipped and reported back
 * rather than aborting the whole upload.
 *
 * @param PDO   $pdo
 * @param array $file An entry from $_FILES.
 * @return array{ok: bool, message: string, imported: int, skipped: int, notes: list<string>}
 */
function faculty_bulk_upload_import(PDO $pdo, array $file): array
{
    ensure_faculty_tables($pdo);

    $fail = static fn(string $message): array => [
        'ok' => false,
        'message' => $message,
        'imported' => 0,
        'skipped' => 0,
        'notes' => [],
    ];

    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $fail('Please choose a CSV file to upload.');
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return $fail('The CSV upload failed. Please try again.');
    }

    if (!is_file((string)($file['tmp_name'] ?? ''))) {
        return $fail('Uploaded file was not found.');
    }

    $fileName = strtolower((string)($file['name'] ?? ''));
    if (!str_ends_with($fileName, '.csv') && !str_ends_with($fileName, '.txt')) {
        return $fail('Please upload a CSV file.');
    }

    $handle = fopen((string)$file['tmp_name'], 'r');
    if ($handle === false) {
        return $fail('Unable to read the uploaded file.');
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);
        return $fail('The CSV file is empty.');
    }

    $headerMap = [];
    foreach ($headers as $index => $header) {
        $headerMap[normalize_key((string)$header)] = $index;
    }

    $requiredColumns = array_keys(faculty_bulk_upload_columns()['required']);
    $missingColumns = [];
    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $headerMap)) {
            $missingColumns[] = $column;
        }
    }

    if ($missingColumns !== []) {
        fclose($handle);
        return $fail('Missing required CSV columns: ' . implode(', ', $missingColumns) . '.');
    }

    $collegeLookup = [];
    try {
        foreach ($pdo->query('SELECT id, code, name FROM colleges') as $college) {
            $collegeLookup[normalize_key((string)$college['code'])] = $college;
            $collegeLookup[normalize_key((string)$college['name'])] = $college;
        }
    } catch (PDOException $e) {
        fclose($handle);
        return $fail('Could not load colleges. Please try again.');
    }

    $existsStmt = $pdo->prepare('SELECT id FROM faculty_staff WHERE employee_number = :employee_number LIMIT 1');
    $insertStmt = $pdo->prepare(
        'INSERT INTO faculty_staff
            (employee_number, first_name, last_name, middle_name, position, department, college_id, email, contact_number, status)
         VALUES
            (:employee_number, :first_name, :last_name, :middle_name, :position, :department, :college_id, :email, :contact_number, :status)'
    );

    $importedCount = 0;
    $skippedCount = 0;
    $notes = [];
    $rowNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;

        if (is_row_empty($row)) {
            continue;
        }

        $firstName = trim(csv_value($row, $headerMap, ['first_name', 'firstname', 'given_name']));
        $lastName = trim(csv_value($row, $headerMap, ['last_name', 'lastname', 'surname']));
        $middleName = trim(csv_value($row, $headerMap, ['middle_name', 'middlename']));
        $employeeNumber = trim(csv_value($row, $headerMap, ['employee_number', 'employee no', 'employee_id', 'id_number']));
        $position = trim(csv_value($row, $headerMap, ['position', 'title', 'designation']));
        $department = trim(csv_value($row, $headerMap, ['department', 'office', 'unit']));
        $collegeValue = trim(csv_value($row, $headerMap, ['college', 'college_name', 'college_code']));
        $email = trim(csv_value($row, $headerMap, ['email']));
        $contactNumber = trim(csv_value($row, $headerMap, ['contact_number', 'contact', 'phone']));

        if ($firstName === '' || $lastName === '') {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: first_name and last_name are required.";
            continue;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: invalid email address.";
            continue;
        }

        $college = resolve_lookup($collegeLookup, $collegeValue);
        if ($collegeValue !== '' && $college === null) {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: college '{$collegeValue}' was not found.";
            continue;
        }

        if ($employeeNumber !== '') {
            $existsStmt->execute([':employee_number' => $employeeNumber]);
            if ($existsStmt->fetch()) {
                $skippedCount++;
                $notes[] = "Row {$rowNumber}: employee number already exists.";
                continue;
            }
        }

        try {
            $insertStmt->execute([
                ':employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':middle_name' => $middleName !== '' ? $middleName : null,
                ':position' => $position !== '' ? $position : null,
                ':department' => $department !== '' ? $department : null,
                ':college_id' => $college ? (int)$college['id'] : null,
                ':email' => $email !== '' ? $email : null,
                ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
                ':status' => 'active',
            ]);
            $importedCount++;
        } catch (PDOException $e) {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: database error while saving the record.";
        }
    }

    fclose($handle);

    if ($importedCount > 0) {
        $message = 'Bulk upload complete. Imported ' . $importedCount . ' faculty/staff record'
            . ($importedCount === 1 ? '' : 's') . '.';
        if ($skippedCount > 0) {
            $message .= ' Skipped ' . $skippedCount . ' row' . ($skippedCount === 1 ? '' : 's') . '.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'imported' => $importedCount,
            'skipped' => $skippedCount,
            'notes' => $notes,
        ];
    }

    return [
        'ok' => false,
        'message' => 'No faculty/staff records were imported from the CSV file.',
        'imported' => 0,
        'skipped' => $skippedCount,
        'notes' => $notes,
    ];
}
