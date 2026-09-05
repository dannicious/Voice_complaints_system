<?php

/**
 * Bulk upload of student records from a CSV file.
 *
 * Used by the Admin Dashboard's Settings > Bulk Upload section so a whole
 * intake of new enrollees can be added at once instead of one at a time.
 *
 * The helper functions below are guarded with function_exists() because
 * admin_students.php declares its own copies of the same helpers.
 */

if (!function_exists('normalize_key')) {
    function normalize_key(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
        return trim($value, '_');
    }
}

if (!function_exists('csv_value')) {
    function csv_value(array $row, array $headerMap, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $key = normalize_key($alias);
            if (array_key_exists($key, $headerMap)) {
                $index = $headerMap[$key];
                return trim((string)($row[$index] ?? ''));
            }
        }

        return '';
    }
}

if (!function_exists('is_row_empty')) {
    function is_row_empty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('resolve_lookup')) {
    function resolve_lookup(array $lookupByKey, string $value): ?array
    {
        $key = normalize_key($value);
        if ($key === '') {
            return null;
        }

        return $lookupByKey[$key] ?? null;
    }
}

if (!function_exists('generate_unique_username')) {
    function generate_unique_username(PDO $pdo, string $firstName, string $lastName, string $studentNumber = ''): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . '.' . $lastName));
        if ($base === '') {
            $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $studentNumber));
        }
        if ($base === '') {
            $base = 'student';
        }

        $candidate = substr($base, 0, 40);
        $attempt = 0;

        while (true) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $candidate]);

            if (!$stmt->fetch()) {
                return $candidate;
            }

            $attempt++;
            $suffix = (string)random_int(100, 999);
            $candidate = substr($base, 0, max(1, 40 - strlen($suffix))) . $suffix;

            if ($attempt > 10) {
                return 'student' . random_int(10000, 99999);
            }
        }
    }
}

/**
 * The CSV columns the importer understands. Single source of truth for the
 * on-screen documentation and the downloadable template.
 *
 * @return array{required: array<string,string>, optional: array<string,string>}
 */
function student_bulk_upload_columns(): array
{
    return [
        'required' => [
            'student_number' => 'School-issued student number. Must be unique.',
            'first_name' => 'Given name.',
            'last_name' => 'Surname.',
            'email' => 'Email address. Must be unique and valid.',
        ],
        'optional' => [
            'middle_name' => 'Middle name.',
            'college' => 'College code or full name, e.g. CCIS or CCJ.',
            'program' => 'Program code or full name. Fills in the college if that column is blank.',
            'year_level' => 'Year level as a number, e.g. 1.',
            'section' => 'Section, e.g. A.',
            'school_year' => 'School year, e.g. 2026-2027.',
            'gender' => 'male, female, or other.',
            'contact_number' => 'Contact number.',
            'username' => 'Login username. Generated from the name when left blank.',
            'password' => 'Initial password. Defaults to the student number when left blank.',
        ],
    ];
}

/**
 * Builds the downloadable CSV template: a header row plus one example row.
 */
function student_bulk_upload_template_csv(): string
{
    $columns = student_bulk_upload_columns();
    $headers = array_merge(array_keys($columns['required']), array_keys($columns['optional']));

    $example = [
        'student_number' => '2026-00001',
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juan.delacruz@bisu.edu.ph',
        'middle_name' => 'Santos',
        'college' => 'CCIS',
        'program' => 'BSIT',
        'year_level' => '1',
        'section' => 'A',
        'school_year' => '2026-2027',
        'gender' => 'male',
        'contact_number' => '09123456789',
        'username' => '',
        'password' => '',
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
 * Imports students from an uploaded CSV file.
 *
 * Each student is created as a users row plus a matching student_profiles row
 * inside one transaction, so a row either lands completely or not at all.
 * Rows that are invalid or already exist are skipped and reported back rather
 * than aborting the whole upload.
 *
 * @param PDO   $pdo
 * @param array $file An entry from $_FILES.
 * @return array{ok: bool, message: string, imported: int, skipped: int, notes: list<string>}
 */
function student_bulk_upload_import(PDO $pdo, array $file): array
{
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

    $requiredColumns = array_keys(student_bulk_upload_columns()['required']);
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

    // Colleges and programs can be given by code or by full name.
    $collegeLookup = [];
    $collegeLookupById = [];
    $programLookup = [];
    try {
        foreach ($pdo->query('SELECT id, code, name FROM colleges') as $college) {
            $collegeLookup[normalize_key((string)$college['code'])] = $college;
            $collegeLookup[normalize_key((string)$college['name'])] = $college;
            $collegeLookupById[(int)$college['id']] = $college;
        }
        foreach ($pdo->query('SELECT id, college_id, code, name FROM programs') as $program) {
            $programLookup[normalize_key((string)$program['code'])] = $program;
            $programLookup[normalize_key((string)$program['name'])] = $program;
        }
    } catch (PDOException $e) {
        fclose($handle);
        return $fail('Could not load colleges and programs. Please try again.');
    }

    $studentExistsStmt = $pdo->prepare('SELECT id FROM student_profiles WHERE student_number = :student_number LIMIT 1');
    $accountExistsStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $insertUserStmt = $pdo->prepare(
        'INSERT INTO users (username, email, password, role, profile_pic, is_active)
         VALUES (:username, :email, :password, :role, :profile_pic, :is_active)'
    );
    $insertProfileStmt = $pdo->prepare(
        'INSERT INTO student_profiles
            (user_id, student_number, first_name, last_name, middle_name, gender, contact_number, college_id, year_level, section, school_year, id_document, status, program_id)
         VALUES
            (:user_id, :student_number, :first_name, :last_name, :middle_name, :gender, :contact_number, :college_id, :year_level, :section, :school_year, :id_document, :status, :program_id)'
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

        $studentNumber = trim(csv_value($row, $headerMap, ['student_number', 'student no', 'student id', 'student_id']));
        $firstName = trim(csv_value($row, $headerMap, ['first_name', 'firstname', 'given_name']));
        $lastName = trim(csv_value($row, $headerMap, ['last_name', 'lastname', 'surname']));
        $email = trim(csv_value($row, $headerMap, ['email']));
        $username = trim(csv_value($row, $headerMap, ['username']));
        $password = trim(csv_value($row, $headerMap, ['password']));
        $middleName = trim(csv_value($row, $headerMap, ['middle_name', 'middlename']));
        $gender = strtolower(trim(csv_value($row, $headerMap, ['gender'])));
        $contactNumber = trim(csv_value($row, $headerMap, ['contact_number', 'contact', 'phone']));
        $yearLevelValue = trim(csv_value($row, $headerMap, ['year_level', 'yearlevel']));
        $section = trim(csv_value($row, $headerMap, ['section']));
        $schoolYear = trim(csv_value($row, $headerMap, ['school_year', 'schoolyear']));
        $collegeValue = trim(csv_value($row, $headerMap, ['college', 'college_name', 'college_code']));
        $programValue = trim(csv_value($row, $headerMap, ['program', 'program_name', 'program_code']));

        if ($studentNumber === '' || $firstName === '' || $lastName === '' || $email === '') {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: missing a required value.";
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        $program = resolve_lookup($programLookup, $programValue);
        if ($programValue !== '' && $program === null) {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: program '{$programValue}' was not found.";
            continue;
        }

        if ($college === null && $program !== null) {
            $college = $collegeLookupById[(int)$program['college_id']] ?? null;
        }

        $collegeId = $college ? (int)$college['id'] : null;
        $programId = $program ? (int)$program['id'] : null;

        if ($username === '') {
            $username = generate_unique_username($pdo, $firstName, $lastName, $studentNumber);
        }

        if ($password === '') {
            $password = $studentNumber;
        }

        $yearLevel = $yearLevelValue !== '' ? (int)$yearLevelValue : null;

        $accountExistsStmt->execute([':username' => $username, ':email' => $email]);
        if ($accountExistsStmt->fetch()) {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: username or email already exists.";
            continue;
        }

        $studentExistsStmt->execute([':student_number' => $studentNumber]);
        if ($studentExistsStmt->fetch()) {
            $skippedCount++;
            $notes[] = "Row {$rowNumber}: student number already exists.";
            continue;
        }

        try {
            $pdo->beginTransaction();

            $insertUserStmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => 'student',
                ':profile_pic' => null,
                ':is_active' => 1,
            ]);

            $userId = (int)$pdo->lastInsertId();

            $insertProfileStmt->execute([
                ':user_id' => $userId,
                ':student_number' => $studentNumber,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':middle_name' => $middleName !== '' ? $middleName : null,
                ':gender' => in_array($gender, ['male', 'female', 'other'], true) ? $gender : null,
                ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
                ':college_id' => $collegeId,
                ':year_level' => $yearLevel,
                ':section' => $section !== '' ? $section : null,
                ':school_year' => $schoolYear !== '' ? $schoolYear : null,
                ':id_document' => null,
                ':status' => 'active',
                ':program_id' => $programId,
            ]);

            $pdo->commit();
            $importedCount++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $skippedCount++;
            $notes[] = "Row {$rowNumber}: database error while saving the student.";
        }
    }

    fclose($handle);

    if ($importedCount > 0) {
        $message = 'Bulk upload complete. Imported ' . $importedCount . ' student' . ($importedCount === 1 ? '' : 's') . '.';
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
        'message' => 'No students were imported from the CSV file.',
        'imported' => 0,
        'skipped' => $skippedCount,
        'notes' => $notes,
    ];
}
