<?php

declare(strict_types=1);

/**
 * School Year helpers for the student portal.
 *
 * Convention: the academic year runs August 1 - July 31 (typical Philippine
 * SUC semester calendar), so any date from Aug 1 of year N through Jul 31 of
 * year N+1 belongs to school year "N-(N+1)" (e.g. 2026-07-13 -> "2025-2026",
 * 2026-08-23 -> "2026-2027"). See Ui-main/school_year_migration.sql for the
 * matching SQL used to backfill existing rows.
 *
 * complaints.school_year / suggestions.school_year are set once at
 * submission time (see process_complaint.php / process_suggestion.php) and
 * never change afterward, so a record's school year always reflects when it
 * was actually filed.
 */

if (!function_exists('sy_label_for_date')) {
    function sy_label_for_date(string $date): string
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

        return sy_label_for_date(date('Y-m-d'));
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
     * (end is exclusive: the first day of the following school year).
     *
     * @return array{0: string, 1: string}
     */
    function sy_bounds(string $schoolYear): array
    {
        if (!sy_is_valid_label($schoolYear)) {
            $schoolYear = sy_current();
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
     * Within the Aug 1 - Jul 31 school year (see sy_label_for_date above),
     * August-December is the 1st semester and January-July is the 2nd.
     */
    function semester_label_for_date(string $date): string
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
    function semester_current(): string
    {
        return semester_label_for_date(date('Y-m-d'));
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
    function semester_get_selected(): string
    {
        $current = semester_current();
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
