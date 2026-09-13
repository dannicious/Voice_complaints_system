<?php

declare(strict_types=1);

/**
 * School Year helpers for the student portal.
 *
 * The academic calendar is admin-configurable (see the "Academic Calendar"
 * section of Settings > School Year, and sy_save_academic_calendar() below):
 * each school year has its own start/end date and 1st/2nd semester
 * start/end dates, stored in the `academic_calendars` table. Given a date,
 * sy_label_for_date()/semester_label_for_date() look up which configured
 * calendar row covers it and read the answer straight from that row - no
 * date math, no assumption that a school year starts in any particular
 * month.
 *
 * Legacy fallback: if no calendar row covers the date in question (nothing
 * configured yet, or a gap between two configured years), both functions
 * fall back to the original hardcoded convention - August 1 - July 31 as
 * the school year, August-December as the 1st semester, January-July as
 * the 2nd - exactly as this file worked before the Academic Calendar table
 * existed. This is what keeps every existing call site working unchanged:
 * a fresh install with no calendar configured yet behaves identically to
 * before. See Ui-main/academic_calendar_migration.sql for the table's SQL
 * and Ui-main/school_year_migration.sql for the original date columns.
 *
 * complaints.school_year / suggestions.school_year (and .semester) are set
 * once at submission time (see process_complaint.php / process_suggestion.php)
 * and never change afterward, so a record's school year/semester always
 * reflects the calendar that was in effect when it was actually filed - even
 * if the admin edits or adds calendar rows later.
 */

if (!function_exists('sy_label_for_date')) {
    function sy_label_for_date(string $date, ?PDO $pdo = null): string
    {
        if ($pdo !== null) {
            $calendar = sy_find_academic_calendar_for_date($pdo, $date);
            if ($calendar !== null) {
                return (string)$calendar['school_year'];
            }
        }

        return sy_legacy_label_for_date($date);
    }
}

if (!function_exists('sy_legacy_label_for_date')) {
    /**
     * The original Aug 1 - Jul 31 date math, kept as the fallback for any
     * date that no configured academic_calendars row covers.
     */
    function sy_legacy_label_for_date(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            $ts = time();
        }

        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $startYear = $month >= 8 ? $year : $year - 1;

        return $startYear . '-' . ($startYear + 1);
    }
}

if (!function_exists('sy_ensure_settings_table')) {
    function sy_ensure_settings_table(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('sy_get_override')) {
    /**
     * The admin-set "current school year" override, if any - takes
     * precedence over the Aug 1 date math everywhere sy_current() is called
     * with a $pdo. Returns '' when there's no override set (or it's not a
     * valid label), in which case the automatic calculation is used as
     * before - so leaving this untouched changes nothing.
     */
    function sy_get_override(PDO $pdo): string
    {
        try {
            sy_ensure_settings_table($pdo);
            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
            $stmt->execute([':key' => 'school_year_override']);
            $value = trim((string)($stmt->fetchColumn() ?: ''));
            return sy_is_valid_label($value) ? $value : '';
        } catch (PDOException $e) {
            return '';
        }
    }
}

if (!function_exists('sy_set_override')) {
    function sy_set_override(PDO $pdo, string $schoolYear): bool
    {
        if (!sy_is_valid_label($schoolYear)) {
            return false;
        }
        sy_ensure_settings_table($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([':key' => 'school_year_override', ':value' => $schoolYear]);
        return true;
    }
}

if (!function_exists('sy_clear_override')) {
    function sy_clear_override(PDO $pdo): void
    {
        sy_ensure_settings_table($pdo);
        $pdo->prepare('DELETE FROM system_settings WHERE setting_key = :key')->execute([':key' => 'school_year_override']);
    }
}

/**
 * Academic Calendar: the admin-configured source of truth for when each
 * school year and its two semesters actually start/end. One row per school
 * year. See Ui-main/academic_calendar_migration.sql for the matching SQL.
 */

if (!function_exists('sy_ensure_academic_calendar_table')) {
    function sy_ensure_academic_calendar_table(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS academic_calendars (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                school_year VARCHAR(9) NOT NULL,
                sy_start_date DATE NOT NULL,
                sy_end_date DATE NOT NULL,
                sem1_start_date DATE NOT NULL,
                sem1_end_date DATE NOT NULL,
                sem2_start_date DATE NOT NULL,
                sem2_end_date DATE NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_academic_calendar_school_year (school_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (PDOException $e) {
        }

        // First-run seed only: a calendar row matching exactly what the old
        // hardcoded Aug1-Jul31 / Aug-Dec-Jan-Jul math would have produced for
        // today, so nothing about "what's the current school year/semester"
        // changes the moment this ships - an admin only needs to add rows
        // for a *future* or *corrected* year going forward. Never runs again
        // once at least one calendar row exists (even if the admin later
        // deletes all of them - that's a deliberate "start over" action).
        try {
            $existingCount = (int)$pdo->query('SELECT COUNT(*) FROM academic_calendars')->fetchColumn();
            if ($existingCount === 0) {
                $todayLabel = sy_legacy_label_for_date(date('Y-m-d'));
                $startYear = (int)substr($todayLabel, 0, 4);
                $pdo->prepare(
                    'INSERT INTO academic_calendars
                        (school_year, sy_start_date, sy_end_date, sem1_start_date, sem1_end_date, sem2_start_date, sem2_end_date)
                     VALUES (:sy, :sy_start, :sy_end, :s1_start, :s1_end, :s2_start, :s2_end)'
                )->execute([
                    ':sy' => $todayLabel,
                    ':sy_start' => sprintf('%04d-08-01', $startYear),
                    ':sy_end' => sprintf('%04d-07-31', $startYear + 1),
                    ':s1_start' => sprintf('%04d-08-01', $startYear),
                    ':s1_end' => sprintf('%04d-12-31', $startYear),
                    ':s2_start' => sprintf('%04d-01-01', $startYear + 1),
                    ':s2_end' => sprintf('%04d-07-31', $startYear + 1),
                ]);
            }
        } catch (PDOException $e) {
        }
    }
}

if (!function_exists('sy_get_academic_calendars')) {
    /**
     * Every configured academic calendar row, newest school year first.
     *
     * @return array<int, array{id:int, school_year:string, sy_start_date:string, sy_end_date:string, sem1_start_date:string, sem1_end_date:string, sem2_start_date:string, sem2_end_date:string}>
     */
    function sy_get_academic_calendars(PDO $pdo): array
    {
        sy_ensure_academic_calendar_table($pdo);
        try {
            return $pdo->query('SELECT * FROM academic_calendars ORDER BY sy_start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('sy_get_academic_calendar_by_year')) {
    function sy_get_academic_calendar_by_year(PDO $pdo, string $schoolYear): ?array
    {
        sy_ensure_academic_calendar_table($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM academic_calendars WHERE school_year = :sy LIMIT 1');
            $stmt->execute([':sy' => $schoolYear]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('sy_find_academic_calendar_for_date')) {
    /**
     * The school year "in effect" on this date: the configured calendar row
     * with the latest sy_start_date that has already started by this date
     * (regardless of whether this date is also past that row's own
     * sy_end_date). This deliberately does NOT require the date to also be
     * <= sy_end_date - a school year stays "current" until the *next*
     * configured school year actually begins, not until its own nominal end
     * date, so a gap between one year's end date and the next year's start
     * date (e.g. an admin only adds next year's row without also moving
     * this year's end date) still resolves to the outgoing year rather than
     * falling through to the legacy guess. Returns null only when no
     * calendar row has started as of this date at all (before the earliest
     * configured school year, or nothing configured yet).
     */
    function sy_find_academic_calendar_for_date(PDO $pdo, string $date): ?array
    {
        sy_ensure_academic_calendar_table($pdo);
        $ts = strtotime($date);
        $d = date('Y-m-d', $ts === false ? time() : $ts);
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM academic_calendars WHERE sy_start_date <= :d ORDER BY sy_start_date DESC LIMIT 1'
            );
            $stmt->execute([':d' => $d]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('sy_calendar_configured_for_date')) {
    /**
     * Whether this date's school year AND semester can both be determined
     * purely from configured calendar data - i.e. a calendar row is in
     * effect (see sy_find_academic_calendar_for_date()) *and* this date
     * falls inside that row's own 1st or 2nd semester range. Used by the
     * filing pages to fail safely (block filing with a clear message)
     * instead of silently falling back to a guessed semester for a brand
     * new submission. Read-only/reporting call sites don't use this; they
     * keep the legacy-math fallback baked into sy_current()/
     * semester_current() so old pages never see a behavior change.
     */
    function sy_calendar_configured_for_date(PDO $pdo, string $date): bool
    {
        $calendar = sy_find_academic_calendar_for_date($pdo, $date);
        if ($calendar === null) {
            return false;
        }

        $ts = strtotime($date);
        $d = date('Y-m-d', $ts === false ? time() : $ts);

        return ($d >= (string)$calendar['sem1_start_date'] && $d <= (string)$calendar['sem1_end_date'])
            || ($d >= (string)$calendar['sem2_start_date'] && $d <= (string)$calendar['sem2_end_date']);
    }
}

if (!function_exists('sy_validate_academic_calendar_input')) {
    /**
     * Logical-order validation shared by sy_save_academic_calendar(): every
     * date parses, the school year itself starts before it ends, and both
     * semesters fall entirely inside the school year and don't overlap each
     * other. Returns an error message, or '' when everything checks out.
     */
    function sy_validate_academic_calendar_input(array $input): string
    {
        $fields = ['sy_start_date', 'sy_end_date', 'sem1_start_date', 'sem1_end_date', 'sem2_start_date', 'sem2_end_date'];
        $dates = [];
        foreach ($fields as $field) {
            $value = trim((string)($input[$field] ?? ''));
            $ts = $value !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($ts === false || $ts->format('Y-m-d') !== $value) {
                return 'Please provide a valid date for every field.';
            }
            $dates[$field] = $value;
        }

        if (!sy_is_valid_label(trim((string)($input['school_year'] ?? '')))) {
            return 'School year must look like 2027-2028.';
        }

        if ($dates['sy_start_date'] >= $dates['sy_end_date']) {
            return 'School Year Start must be before School Year End.';
        }
        if ($dates['sem1_start_date'] >= $dates['sem1_end_date']) {
            return '1st Semester Start must be before 1st Semester End.';
        }
        if ($dates['sem2_start_date'] >= $dates['sem2_end_date']) {
            return '2nd Semester Start must be before 2nd Semester End.';
        }
        if ($dates['sem1_start_date'] < $dates['sy_start_date'] || $dates['sem1_end_date'] > $dates['sy_end_date']) {
            return '1st Semester must fall entirely within the School Year dates.';
        }
        if ($dates['sem2_start_date'] < $dates['sy_start_date'] || $dates['sem2_end_date'] > $dates['sy_end_date']) {
            return '2nd Semester must fall entirely within the School Year dates.';
        }
        if ($dates['sem1_start_date'] > $dates['sem2_start_date']) {
            return '1st Semester must start before 2nd Semester.';
        }
        if ($dates['sem1_end_date'] >= $dates['sem2_start_date']) {
            return '1st Semester and 2nd Semester dates must not overlap.';
        }

        return '';
    }
}

if (!function_exists('sy_save_academic_calendar')) {
    /**
     * Validates and saves one academic calendar row - inserts a new school
     * year or updates the existing row for that same school year (never a
     * second row for the same label, per the table's unique key on
     * school_year - "avoid duplicate academic calendar records").
     *
     * @return array{ok: bool, error: string}
     */
    function sy_save_academic_calendar(PDO $pdo, array $input): array
    {
        sy_ensure_academic_calendar_table($pdo);

        $schoolYear = trim((string)($input['school_year'] ?? ''));
        $error = sy_validate_academic_calendar_input($input);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO academic_calendars
                    (school_year, sy_start_date, sy_end_date, sem1_start_date, sem1_end_date, sem2_start_date, sem2_end_date)
                 VALUES (:sy, :sy_start, :sy_end, :s1_start, :s1_end, :s2_start, :s2_end)
                 ON DUPLICATE KEY UPDATE
                    sy_start_date = VALUES(sy_start_date),
                    sy_end_date = VALUES(sy_end_date),
                    sem1_start_date = VALUES(sem1_start_date),
                    sem1_end_date = VALUES(sem1_end_date),
                    sem2_start_date = VALUES(sem2_start_date),
                    sem2_end_date = VALUES(sem2_end_date)'
            );
            $stmt->execute([
                ':sy' => $schoolYear,
                ':sy_start' => (string)$input['sy_start_date'],
                ':sy_end' => (string)$input['sy_end_date'],
                ':s1_start' => (string)$input['sem1_start_date'],
                ':s1_end' => (string)$input['sem1_end_date'],
                ':s2_start' => (string)$input['sem2_start_date'],
                ':s2_end' => (string)$input['sem2_end_date'],
            ]);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Unable to save the academic calendar right now.'];
        }
    }
}

if (!function_exists('sy_delete_academic_calendar')) {
    function sy_delete_academic_calendar(PDO $pdo, int $id): void
    {
        sy_ensure_academic_calendar_table($pdo);
        try {
            $pdo->prepare('DELETE FROM academic_calendars WHERE id = :id')->execute([':id' => $id]);
        } catch (PDOException $e) {
        }
    }
}

if (!function_exists('sy_current')) {
    /**
     * The "current" school year: an admin-set override when one is on file
     * (only checked when $pdo is passed - every real call site has a $pdo
     * available), otherwise the Aug 1 - Jul 31 date calculation.
     */
    function sy_current(?PDO $pdo = null): string
    {
        if ($pdo !== null) {
            $override = sy_get_override($pdo);
            if ($override !== '') {
                return $override;
            }
        }

        return sy_label_for_date(date('Y-m-d'), $pdo);
    }
}

if (!function_exists('sy_is_valid_label')) {
    function sy_is_valid_label(string $schoolYear): bool
    {
        return (bool)preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m) && ((int)$m[2] === (int)$m[1] + 1);
    }
}

if (!function_exists('sy_bounds')) {
    /**
     * The [start, end) date bounds of a school year label, as 'Y-m-d' strings
     * (end is exclusive: the first day after the school year's configured
     * end date, or after Jul 31 for the legacy Aug1-Jul31 fallback).
     *
     * @return array{0: string, 1: string}
     */
    function sy_bounds(string $schoolYear, ?PDO $pdo = null): array
    {
        if (!sy_is_valid_label($schoolYear)) {
            $schoolYear = sy_current($pdo);
        }

        if ($pdo !== null) {
            $calendar = sy_get_academic_calendar_by_year($pdo, $schoolYear);
            if ($calendar !== null) {
                $endExclusive = (new DateTimeImmutable((string)$calendar['sy_end_date']))->modify('+1 day')->format('Y-m-d');
                return [(string)$calendar['sy_start_date'], $endExclusive];
            }
        }

        $startYear = (int)substr($schoolYear, 0, 4);

        return [sprintf('%04d-08-01', $startYear), sprintf('%04d-08-01', $startYear + 1)];
    }
}

if (!function_exists('sy_list_for_student')) {
    /**
     * Distinct school years the student has complaint/suggestion records in,
     * plus the current school year, newest first.
     *
     * @return string[]
     */
    function sy_list_for_student(PDO $pdo, int $studentProfileId): array
    {
        $current = sy_current($pdo);
        $years = [$current];

        if ($studentProfileId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT school_year FROM complaints WHERE student_id = :sid1 AND school_year IS NOT NULL
                     UNION
                     SELECT school_year FROM suggestions WHERE student_id = :sid2 AND school_year IS NOT NULL'
                );
                $stmt->execute([':sid1' => $studentProfileId, ':sid2' => $studentProfileId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $sy) {
                    $sy = (string)$sy;
                    if ($sy !== '' && sy_is_valid_label($sy) && !in_array($sy, $years, true)) {
                        $years[] = $sy;
                    }
                }
            } catch (PDOException $e) {
                // Fall back to just the current school year.
            }
        }

        usort($years, static function (string $a, string $b): int {
            return (int)substr($b, 0, 4) <=> (int)substr($a, 0, 4);
        });

        return $years;
    }
}

if (!function_exists('sy_list_for_college')) {
    /**
     * Distinct school years with complaint/suggestion records for a college,
     * plus the current school year, newest first. Used by dean-facing report
     * filters — scoped by college, not by an individual student.
     *
     * @return string[]
     */
    function sy_list_for_college(PDO $pdo, int $collegeId): array
    {
        $current = sy_current($pdo);
        $years = [$current];

        if ($collegeId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT school_year FROM complaints WHERE college_id = :cid1 AND school_year IS NOT NULL
                     UNION
                     SELECT school_year FROM suggestions WHERE college_id = :cid2 AND school_year IS NOT NULL'
                );
                $stmt->execute([':cid1' => $collegeId, ':cid2' => $collegeId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $sy) {
                    $sy = (string)$sy;
                    if ($sy !== '' && sy_is_valid_label($sy) && !in_array($sy, $years, true)) {
                        $years[] = $sy;
                    }
                }
            } catch (PDOException $e) {
                // Fall back to just the current school year.
            }
        }

        usort($years, static function (string $a, string $b): int {
            return (int)substr($b, 0, 4) <=> (int)substr($a, 0, 4);
        });

        return $years;
    }
}

if (!function_exists('sy_get_selected')) {
    /**
     * Resolves (and, if needed, resets) the student's selected school year
     * from the session. Any syntactically valid label is allowed — the
     * student can freely browse a school year they have no records in;
     * the page they're on is responsible for showing an empty state rather
     * than silently bouncing them back to the current year.
     *
     * $pdo/$studentProfileId are accepted (unused) to avoid a signature
     * change across every call site.
     */
    function sy_get_selected(PDO $pdo, int $studentProfileId): string
    {
        $current = sy_current($pdo);
        $selected = (string)($_SESSION['selected_school_year'] ?? '');

        if ($selected === '' || !sy_is_valid_label($selected)) {
            $_SESSION['selected_school_year'] = $current;
            return $current;
        }

        return $selected;
    }
}

if (!function_exists('sy_set_selected')) {
    function sy_set_selected(string $schoolYear): void
    {
        $_SESSION['selected_school_year'] = $schoolYear;
    }
}

if (!function_exists('semester_label_for_date')) {
    /**
     * Looks up which configured academic_calendars row covers this date and
     * reads its 1st/2nd semester date ranges to answer. Falls back to the
     * legacy August-December = 1st / January-July = 2nd rule when no
     * calendar row covers the date (nothing configured yet, or the date
     * falls in a gap between two configured years' semester ranges) - same
     * "unchanged until an admin configures the new calendar" guarantee as
     * sy_label_for_date() above.
     */
    function semester_label_for_date(string $date, ?PDO $pdo = null): string
    {
        if ($pdo !== null) {
            $calendar = sy_find_academic_calendar_for_date($pdo, $date);
            if ($calendar !== null) {
                $ts = strtotime($date) ?: time();
                $d = date('Y-m-d', $ts);
                if ($d >= (string)$calendar['sem1_start_date'] && $d <= (string)$calendar['sem1_end_date']) {
                    return '1';
                }
                if ($d >= (string)$calendar['sem2_start_date'] && $d <= (string)$calendar['sem2_end_date']) {
                    return '2';
                }
                // Covered by the school year but not by either configured
                // semester range (e.g. an inter-semester break) - fall
                // through to the legacy month-based guess below rather than
                // returning something arbitrary.
            }
        }

        return semester_legacy_label_for_date($date);
    }
}

if (!function_exists('semester_legacy_label_for_date')) {
    /**
     * The original Aug-Dec / Jan-Jul month-based guess, kept as the fallback
     * for any date no configured calendar's semester ranges account for.
     */
    function semester_legacy_label_for_date(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            $ts = time();
        }

        $month = (int)date('n', $ts);

        return $month >= 8 ? '1' : '2';
    }
}

if (!function_exists('semester_current')) {
    function semester_current(?PDO $pdo = null): string
    {
        return semester_label_for_date(date('Y-m-d'), $pdo);
    }
}

if (!function_exists('semester_is_valid_label')) {
    function semester_is_valid_label(string $semester): bool
    {
        return in_array($semester, ['1', '2'], true);
    }
}

if (!function_exists('semester_display_label')) {
    function semester_display_label(string $semester): string
    {
        return $semester === '2' ? '2nd Semester' : '1st Semester';
    }
}

if (!function_exists('semester_get_selected')) {
    /**
     * Resolves (and, if needed, resets) the student's selected semester from
     * the session. Unlike the school year, there's a fixed set of two valid
     * values, so no per-student "do they have records in it" check is needed.
     */
    function semester_get_selected(?PDO $pdo = null): string
    {
        $current = semester_current($pdo);
        $selected = (string)($_SESSION['selected_semester'] ?? '');

        if ($selected === '' || !semester_is_valid_label($selected)) {
            $_SESSION['selected_semester'] = $current;
            return $current;
        }

        return $selected;
    }
}

if (!function_exists('semester_set_selected')) {
    function semester_set_selected(string $semester): void
    {
        if (semester_is_valid_label($semester)) {
            $_SESSION['selected_semester'] = $semester;
        }
    }
}

if (!function_exists('sy_smart_input_script')) {
    /**
     * Shared JS behind every typable school-year input in the app (student
     * topbar switcher, admin/dean report and complaint-list filters): typing
     * a 4-digit start year (e.g. "2024") auto-completes it to the full
     * "2024-2025" label and submits the field's form, so nobody has to
     * scroll a <select> that grows by one entry every year.
     */
    function sy_smart_input_script(): string
    {
        return <<<'HTML'
<script>
function formatSchoolYearInput(input, autoSubmit, event) {
    // autoSubmit defaults to true (matches every existing filter that
    // submits as soon as the year is complete); pass false when the field
    // shares a form with something else the user still needs to set (e.g.
    // the student school-year/semester modal), so it only auto-formats and
    // waits for an explicit submit instead.
    //
    // Skip while the user is deleting (backspace/delete) rather than typing
    // forward - otherwise backspacing an already-completed "2026-2027" down
    // to "2026" instantly snaps back to "2026-2027", making it impossible to
    // delete past the 4-digit start year to type a different one.
    if (event && event.inputType && event.inputType.indexOf('delete') === 0) {
        return;
    }
    if (/^\d{4}$/.test(input.value)) {
        var startYear = parseInt(input.value, 10);
        input.value = startYear + '-' + (startYear + 1);
        if (autoSubmit !== false && input.form) {
            input.form.submit();
        }
    }
}
function schoolYearInputKeydown(event, input) {
    if (event.key === 'Enter') {
        event.preventDefault();
        formatSchoolYearInput(input, false);
        if (input.form) {
            input.form.submit();
        }
    }
}
</script>
HTML;
    }
}

if (!function_exists('sy_resolve_student_profile_id')) {
    function sy_resolve_student_profile_id(PDO $pdo): int
    {
        if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
            return 0;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
            $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }
}
