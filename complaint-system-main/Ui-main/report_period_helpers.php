<?php

/**
 * Builds period buckets (week/month/year) and counts how many complaints
 * ("reports") were submitted in each one, for the Reports Management
 * period-comparison table. Shared by admin_students.php and dean_reports.php.
 *
 * @param PDO      $pdo
 * @param string   $rangeType  'week' | 'month' | 'year'
 * @param int|null $collegeId  Restrict to one college (dean scope); null = all colleges (admin scope)
 * @return array{
 *     rangeType: string,
 *     buckets: list<array{key:string,label:string,count:int}>,
 *     total: int,
 *     latest: int,
 *     previous: int,
 *     delta: int
 * }
 */
function report_period_buckets(PDO $pdo, string $rangeType, ?int $collegeId = null): array
{
    $rangeType = in_array($rangeType, ['week', 'month', 'year'], true) ? $rangeType : 'month';
    $bucketCount = ['week' => 12, 'month' => 12, 'year' => 5][$rangeType];

    $now = new DateTimeImmutable('now');
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

    $sql = 'SELECT created_at FROM complaints WHERE created_at >= :range_start AND created_at < :range_end';
    $params = [
        ':range_start' => $rangeStart->format('Y-m-d H:i:s'),
        ':range_end' => $rangeEnd->format('Y-m-d H:i:s'),
    ];
    if ($collegeId !== null) {
        $sql .= ' AND college_id = :college_id';
        $params[':college_id'] = $collegeId;
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
 * @param PDO      $pdo
 * @param int|null $collegeId       Restrict counts to one college (dean scope); null = all colleges (admin scope)
 * @param string   $currentRange    'week' | 'month' | 'year'
 * @param string   $pageUrl         The current page's filename, e.g. 'dean_reports.php'
 * @param array    $preserveParams  Other GET params (e.g. ['q' => ..., 'sort' => ...]) to keep when switching range
 */
function render_report_period_widget(PDO $pdo, ?int $collegeId, string $currentRange, string $pageUrl, array $preserveParams = []): void
{
    static $stylePrinted = false;

    $currentRange = in_array($currentRange, ['week', 'month', 'year'], true) ? $currentRange : 'month';
    $data = report_period_buckets($pdo, $currentRange, $collegeId);
    $buckets = $data['buckets'];

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
            <div class="rpt-range-switch">
                <?php foreach ($rangeLabels as $key => $label): ?>
                    <a href="<?php echo $esc($buildUrl($key)); ?>" class="<?php echo $currentRange === $key ? 'active' : ''; ?>"><?php echo $esc($label); ?>ly</a>
                <?php endforeach; ?>
            </div>
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
                        <tr><td><?php echo $esc($b['label']); ?></td><td><?php echo number_format($b['count']); ?></td></tr>
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
    </style>
    <?php
}
