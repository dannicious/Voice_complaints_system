<?php

require_once __DIR__ . '/school_year_helpers.php';

/**
 * Builds period buckets (week/month/year) and counts how many complaints
 * ("reports") were submitted in each one, for the Reports Management
 * period-comparison table. Shared by admin_students.php and dean_reports.php.
 *
 * @param PDO         $pdo
 * @param string      $rangeType   'week' | 'month' | 'year'
 * @param int|null    $collegeId   Restrict to one college (dean scope); null = all colleges (admin scope)
 * @param int|null    $programId   Restrict to one department/program within that college (dean scope only)
 * @param string|null $schoolYear  Restrict to one school year label (e.g. "2025-2026"); null = no school-year restriction.
 *                                 When set, the bucket window is anchored to that school year (instead of "today") so a
 *                                 past school year still shows its own data rather than an empty window.
 * @return array{
 *     rangeType: string,
 *     buckets: list<array{key:string,label:string,count:int}>,
 *     total: int,
 *     latest: int,
 *     previous: int,
 *     delta: int
 * }
 */
function report_period_buckets(PDO $pdo, string $rangeType, ?int $collegeId = null, ?int $programId = null, ?string $schoolYear = null, ?string $semester = null): array
{
    $rangeType = in_array($rangeType, ['week', 'month', 'year'], true) ? $rangeType : 'month';
    $bucketCount = ['week' => 12, 'month' => 12, 'year' => 5][$rangeType];

    $now = new DateTimeImmutable('now');
    if ($schoolYear !== null && sy_is_valid_label($schoolYear)) {
        // Anchor the bucket window to the selected school year so a past
        // school year shows its own trend instead of an empty "last 12
        // weeks from today" window that doesn't overlap it at all.
        [, $syEndExclusive] = sy_bounds($schoolYear, $pdo);
        $syLastDay = (new DateTimeImmutable($syEndExclusive))->modify('-1 day');
        if ($syLastDay < $now) {
            $now = $syLastDay;
        }
    }
    $buckets = [];

    for ($i = $bucketCount - 1; $i >= 0; $i--) {
        if ($rangeType === 'week') {
            $start = $now->modify('monday this week')->setTime(0, 0, 0)->modify("-{$i} week");
            $end = $start->modify('+7 day');
            $lastDay = $end->modify('-1 day');
            $label = $start->format('M') === $lastDay->format('M')
                ? $start->format('M j') . '-' . $lastDay->format('j')
                : $start->format('M j') . ' - ' . $lastDay->format('M j');
        } elseif ($rangeType === 'year') {
            $year = (int)$now->format('Y') - $i;
            $start = new DateTimeImmutable("{$year}-01-01 00:00:00");
            $end = $start->modify('+1 year');
            $label = (string)$year;
        } else {
            $start = $now->modify('first day of this month')->setTime(0, 0, 0)->modify("-{$i} month");
            $end = $start->modify('+1 month');
            $label = $start->format('M Y');
        }
        $buckets[] = [
            'key' => $start->format('Y-m-d'),
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'count' => 0,
        ];
    }

    $rangeStart = $buckets[0]['start'];
    $rangeEnd = $buckets[count($buckets) - 1]['end'];

    $sql = 'SELECT c.created_at FROM complaints c';
    if ($programId !== null) {
        $sql .= ' INNER JOIN student_profiles sp ON sp.id = c.student_id';
    }
    $sql .= ' WHERE c.created_at >= :range_start AND c.created_at < :range_end';
    $params = [
        ':range_start' => $rangeStart->format('Y-m-d H:i:s'),
        ':range_end' => $rangeEnd->format('Y-m-d H:i:s'),
    ];
    if ($collegeId !== null) {
        $sql .= ' AND c.college_id = :college_id';
        $params[':college_id'] = $collegeId;
    }
    if ($programId !== null) {
        $sql .= ' AND sp.program_id = :program_id';
        $params[':program_id'] = $programId;
    }
    if ($schoolYear !== null && sy_is_valid_label($schoolYear)) {
        $sql .= ' AND c.school_year = :school_year';
        $params[':school_year'] = $schoolYear;
    }
    if ($semester === '1' || $semester === '2') {
        // The stored column, set once at submission time from whichever
        // academic calendar was in effect then - not a hardcoded Aug1/Jan1
        // date guess, so this stays correct even if the calendar changes.
        $sql .= ' AND c.semester = :semester';
        $params[':semester'] = $semester;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $bucketStarts = array_map(static fn(array $b) => $b['start']->getTimestamp(), $buckets);
    foreach ($rows as $createdAt) {
        $ts = strtotime((string)$createdAt);
        if ($ts === false) {
            continue;
        }
        // Buckets are contiguous and sorted, so find the last bucket whose start <= ts.
        $index = count($bucketStarts) - 1;
        while ($index >= 0 && $bucketStarts[$index] > $ts) {
            $index--;
        }
        if ($index >= 0) {
            $buckets[$index]['count']++;
        }
    }

    $total = array_sum(array_column($buckets, 'count'));
    $latest = $buckets[count($buckets) - 1]['count'];
    $previous = count($buckets) >= 2 ? $buckets[count($buckets) - 2]['count'] : 0;

    return [
        'rangeType' => $rangeType,
        'buckets' => array_map(static fn(array $b) => [
            'key' => $b['key'],
            'label' => $b['label'],
            'count' => $b['count'],
        ], $buckets),
        'total' => $total,
        'latest' => $latest,
        'previous' => $previous,
        'delta' => $latest - $previous,
    ];
}

/**
 * Renders the "Report Submissions Over Time" card: a Week/Month/Year range
 * switch, a headline stat with change vs the previous period, and a backing
 * data table. Self-contained (own <style>, emitted once per page even if
 * called multiple times).
 *
 * @param PDO         $pdo
 * @param int|null    $collegeId       Restrict counts to one college (dean scope); null = all colleges (admin scope)
 * @param string      $currentRange    'week' | 'month' | 'year'
 * @param string      $pageUrl         The current page's filename, e.g. 'dean_reports.php'
 * @param array       $preserveParams  Other GET params (e.g. ['q' => ..., 'sort' => ...]) to keep when switching range
 * @param int|null    $programId       Restrict counts to one department/program (dean scope only)
 * @param string|null $schoolYear      Restrict counts to one school year label; null = no restriction
 * @param string|null $semester        Restrict counts to semester 1 (Aug-Dec) or 2 (Jan-Jul)
 * @param bool        $showRangeSwitch Whether to render the built-in Week/Month/Year switch in the card header
 *                                     (set false when the caller renders its own range control elsewhere, using
 *                                     the same 'range' query param and preserveParams)
 */
function render_report_period_widget(PDO $pdo, ?int $collegeId, string $currentRange, string $pageUrl, array $preserveParams = [], ?int $programId = null, ?string $schoolYear = null, bool $showRangeSwitch = true, ?string $semester = null): void
{
    static $stylePrinted = false;

    $currentRange = in_array($currentRange, ['week', 'month', 'year'], true) ? $currentRange : 'month';
    $data = report_period_buckets($pdo, $currentRange, $collegeId, $programId, $schoolYear, $semester);
    $buckets = $data['buckets'];
    $maxBucketCount = max(1, max(array_column($buckets, 'count')));

    $rangeLabels = ['week' => 'Week', 'month' => 'Month', 'year' => 'Year'];
    $latestBucket = $buckets[count($buckets) - 1];
    $delta = $data['delta'];
    $deltaClass = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
    $deltaText = $delta > 0 ? ('+' . $delta) : ($delta < 0 ? (string)$delta : 'No change');
    $periodNoun = ['week' => 'week', 'month' => 'month', 'year' => 'year'][$currentRange];

    $buildUrl = static function (string $range) use ($pageUrl, $preserveParams): string {
        $params = $preserveParams;
        $params['range'] = $range;
        return $pageUrl . '?' . http_build_query($params);
    };

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="data-card rpt-card">
        <div class="rpt-header">
            <div class="rpt-title">
                <h3>Report Submissions Over Time</h3>
                <p>Compare how many reports were submitted per <?php echo $esc($periodNoun); ?>.</p>
            </div>
            <?php if ($showRangeSwitch): ?>
                <div class="rpt-range-switch">
                    <?php foreach ($rangeLabels as $key => $label): ?>
                        <a href="<?php echo $esc($buildUrl($key)); ?>" class="<?php echo $currentRange === $key ? 'active' : ''; ?>"><?php echo $esc($label); ?>ly</a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="rpt-stats">
            <div>
                <div class="rpt-stat-label">This <?php echo $esc($periodNoun); ?> (<?php echo $esc($latestBucket['label']); ?>)</div>
                <div><span class="rpt-stat-value"><?php echo number_format($latestBucket['count']); ?></span><span class="rpt-stat-delta <?php echo $deltaClass; ?>"><?php echo $esc($deltaText); ?> vs previous <?php echo $esc($periodNoun); ?></span></div>
            </div>
            <div>
                <div class="rpt-stat-label">Total shown (<?php echo count($buckets); ?> <?php echo $esc($periodNoun); ?>s)</div>
                <div><span class="rpt-stat-value"><?php echo number_format($data['total']); ?></span></div>
            </div>
        </div>

        <div class="rpt-table-wrap">
            <table class="rpt-table">
                <thead><tr><th><?php echo $esc(ucfirst($periodNoun)); ?></th><th>Reports</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($buckets) as $b): ?>
                        <?php $barPct = $maxBucketCount > 0 ? round(($b['count'] / $maxBucketCount) * 100) : 0; ?>
                        <tr>
                            <td><?php echo $esc($b['label']); ?></td>
                            <td>
                                <div class="rpt-bar-cell">
                                    <span class="rpt-bar-track"><span class="rpt-bar-fill" style="width:<?php echo (int)$barPct; ?>%"></span></span>
                                    <span class="rpt-bar-value"><?php echo number_format($b['count']); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    if ($stylePrinted) {
        return;
    }
    $stylePrinted = true;
    ?>
    <style>
    .rpt-card { margin-top: 24px; }
    .rpt-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:18px; }
    .rpt-title h3 { font-size:16px; font-weight:600; color:#111827; margin-bottom:4px; }
    .rpt-title p { font-size:13px; color:#6b7280; }
    .rpt-range-switch { display:inline-flex; background:#f3f4f6; border-radius:8px; padding:3px; gap:2px; flex-shrink:0; }
    .rpt-range-switch a { padding:6px 14px; font-size:13px; font-weight:500; color:#6b7280; text-decoration:none; border-radius:6px; }
    .rpt-range-switch a.active { background:#fff; color:#111827; box-shadow:0 1px 2px rgba(0,0,0,0.08); }
    .rpt-range-switch a:hover:not(.active) { color:#374151; }
    .rpt-stats { display:flex; gap:32px; margin-bottom:20px; flex-wrap:wrap; }
    .rpt-stat-label { font-size:12px; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; margin-bottom:6px; }
    .rpt-stat-value { font-size:26px; font-weight:600; color:#111827; }
    .rpt-stat-delta { font-size:13px; font-weight:500; margin-left:10px; color:#6b7280; white-space:nowrap; }
    .rpt-stat-delta.up { color:#3b82f6; }
    .rpt-stat-delta.down { color:#6b7280; }
    .rpt-stat-delta.up::before { content:'\25B2\0020'; font-size:10px; }
    .rpt-stat-delta.down::before { content:'\25BC\0020'; font-size:10px; }
    .rpt-table-wrap { max-height:320px; overflow-y:auto; border:1px solid #f0f1f3; border-radius:8px; }
    .rpt-table { width:100%; border-collapse:collapse; }
    .rpt-table th { position:sticky; top:0; background:#fafafa; text-align:left; font-size:11px; color:#9ca3af; text-transform:uppercase; padding:8px 12px; border-bottom:1px solid #f0f1f3; }
    .rpt-table td { padding:8px 12px; font-size:13px; color:#374151; border-bottom:1px solid #f9f9f9; font-variant-numeric:tabular-nums; }
    .rpt-table td:last-child, .rpt-table th:last-child { text-align:right; }
    .rpt-bar-cell { display:flex; align-items:center; justify-content:flex-end; gap:10px; }
    .rpt-bar-track { flex:1; max-width:140px; height:6px; background:#f0f1f3; border-radius:999px; overflow:hidden; }
    .rpt-bar-fill { display:block; height:100%; background:#6d28d9; border-radius:999px; }
    .rpt-bar-value { min-width:28px; text-align:right; font-weight:600; color:#111827; }
    </style>
    <?php
}
