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

if (!function_exists('sy_current')) {
    function sy_current(): string
    {
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
        $current = sy_current();
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
        $current = sy_current();
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
     * from the session. Always returns a valid label the student is allowed
     * to view: either the current school year, or one they have records in.
     */
    function sy_get_selected(PDO $pdo, int $studentProfileId): string
    {
        $current = sy_current();
        $selected = (string)($_SESSION['selected_school_year'] ?? '');

        if ($selected === $current) {
            return $current;
        }

        if ($selected === '' || !sy_is_valid_label($selected)) {
            $_SESSION['selected_school_year'] = $current;
            return $current;
        }

        $available = sy_list_for_student($pdo, $studentProfileId);
        if (!in_array($selected, $available, true)) {
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
