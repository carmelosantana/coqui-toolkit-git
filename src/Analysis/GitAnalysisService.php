<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Analysis;

use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Git\Runtime\GitResult;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Exception;

/**
 * Shared analysis layer for higher-level Git repository triage.
 */
final readonly class GitAnalysisService
{
    /** @var array<string, string> */
    private const array HOTSPOT_PERIODS = [
        '1-month' => '1 month ago',
        '3-months' => '3 months ago',
        '6-months' => '6 months ago',
        '1-year' => '1 year ago',
        'all-time' => '',
    ];

    /** @var array<string, string> */
    private const array CONTRIBUTOR_PERIODS = [
        'all-time' => '',
        '1-year' => '1 year ago',
        '6-months' => '6 months ago',
        '3-months' => '3 months ago',
    ];

    /** @var array<string, string> */
    private const array VELOCITY_PERIODS = [
        '1-year' => '1 year ago',
        '2-years' => '2 years ago',
        'all-time' => '',
    ];

    /** @var array<string, int> */
    private const array CRISIS_PERIOD_MONTHS = [
        '1-month' => 1,
        '3-months' => 3,
        '6-months' => 6,
        '1-year' => 12,
    ];

    /** @var array<string, string> */
    private const array CRISIS_TYPE_PATTERNS = [
        'revert' => '/\brevert(?:ed)?\b/i',
        'hotfix' => '/\bhotfix\b/i',
        'emergency' => '/\bemergency\b/i',
        'rollback' => '/\brollback\b/i',
    ];

    public function __construct(
        private GitRunner $runner,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function churnHotspots(array $args): ToolResult
    {
        $period = $this->readPeriod($args, 'period', self::HOTSPOT_PERIODS, '1-year');
        $limit = $this->readLimit($args, 'limit', 20, 1, 50);
        $path = $this->readPath($args);

        $counts = $this->collectFileCounts($period, $path);
        if ($counts['error'] !== null) {
            return ToolResult::error($counts['error']);
        }

        if ($counts['counts'] === []) {
            return ToolResult::success(
                sprintf('No file churn found for %s. The repository may have no commits in that period.', $this->describePeriod($period)),
            );
        }

        $ranked = $this->rankCounts($counts['counts'], $limit);
        $topFile = $ranked[0]['label'];

        $lines = [
            sprintf('Git churn hotspots for %s', $this->describePeriod($period)),
            '',
            $this->renderTable(['#', 'File', 'Changes'], $this->rankedRows($ranked, 'count')),
            '',
            sprintf('Interpretation: %s is the hottest file in this window. High churn often signals active work, but high churn paired with bug-fix traffic usually marks the riskiest code to touch first.', $topFile),
        ];

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function contributorRanking(array $args): ToolResult
    {
        $period = $this->readPeriod($args, 'period', self::CONTRIBUTOR_PERIODS, 'all-time');
        $limit = $this->readLimit($args, 'limit', 20, 1, 50);
        $mergeStrategy = trim((string) ($args['merge_strategy'] ?? 'ignore-merges'));
        $ignoreMerges = $mergeStrategy !== 'count-merges';
        $path = $this->readPath($args);

        $selected = $this->collectContributors($period, $ignoreMerges, $path);
        if ($selected['error'] !== null) {
            return ToolResult::error($selected['error']);
        }

        if ($selected['contributors'] === []) {
            return ToolResult::success(
                sprintf('No contributor activity found for %s.', $this->describePeriod($period)),
            );
        }

        $overall = $this->collectContributors('all-time', $ignoreMerges, $path);
        if ($overall['error'] !== null) {
            return ToolResult::error($overall['error']);
        }

        $recent = $this->collectContributors('6-months', $ignoreMerges, $path);
        if ($recent['error'] !== null) {
            return ToolResult::error($recent['error']);
        }

        $display = array_slice($selected['contributors'], 0, $limit);
        $totalCommits = array_sum(array_column($selected['contributors'], 'commits'));
        $topContributor = $selected['contributors'][0];
        $topPercent = $totalCommits > 0 ? ($topContributor['commits'] / $totalCommits) * 100 : 0.0;
        $allTimeTop = $overall['contributors'][0] ?? null;
        $recentNames = array_map(
            static fn(array $contributor): string => strtolower($contributor['name']),
            $recent['contributors'],
        );

        $inactivityWarning = null;
        if ($allTimeTop !== null && !in_array(strtolower($allTimeTop['name']), $recentNames, true)) {
            $inactivityWarning = sprintf(
                '%s is the top all-time contributor but does not appear in the last 6 months. That is a maintenance continuity risk if the code still depends on their context.',
                $allTimeTop['name'],
            );
        }

        $busFactor = $topPercent >= 60
            ? sprintf('Bus factor warning: %s accounts for %.1f%% of the commits in this window.', $topContributor['name'], $topPercent)
            : sprintf('Contributor distribution looks healthier: %s leads with %.1f%% of commits in this window.', $topContributor['name'], $topPercent);

        $lines = [
            sprintf('Contributor ranking for %s', $this->describePeriod($period)),
            '',
            $this->renderTable(
                ['#', 'Contributor', 'Email', 'Commits'],
                array_map(
                    static fn(int $index, array $contributor): array => [
                        (string) ($index + 1),
                        $contributor['name'],
                        $contributor['email'] !== '' ? $contributor['email'] : '—',
                        (string) $contributor['commits'],
                    ],
                    array_keys($display),
                    $display,
                ),
            ),
            '',
            $busFactor,
            $inactivityWarning ?? 'Recent contributor activity still includes the dominant historical maintainers.',
            'Caveat: squash-merge-heavy workflows can distort authorship toward whoever merged the pull requests.',
        ];

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function bugHotspots(array $args): ToolResult
    {
        $period = $this->readPeriod($args, 'period', self::HOTSPOT_PERIODS, '1-year');
        $limit = $this->readLimit($args, 'limit', 20, 1, 50);
        $keywords = trim((string) ($args['keywords'] ?? 'fix|bug|broken'));
        $path = $this->readPath($args);

        if ($keywords === '') {
            return ToolResult::error('The "keywords" parameter cannot be empty.');
        }

        $bugCounts = $this->collectFileCounts(
            $period,
            $path,
            [
                '--regexp-ignore-case',
                '--extended-regexp',
                '--grep=' . $keywords,
            ],
        );

        if ($bugCounts['error'] !== null) {
            return ToolResult::error($bugCounts['error']);
        }

        if ($bugCounts['counts'] === []) {
            return ToolResult::success(
                sprintf(
                    'No bug hotspot signals found for %s using keywords %s.',
                    $this->describePeriod($period),
                    $keywords,
                ),
            );
        }

        $churnCounts = $this->collectFileCounts($period, $path);
        if ($churnCounts['error'] !== null) {
            return ToolResult::error($churnCounts['error']);
        }

        $ranked = $this->rankCounts($bugCounts['counts'], $limit);
        $churnRanked = $this->rankCounts($churnCounts['counts'], $limit);
        $churnMap = [];
        foreach ($churnRanked as $item) {
            $churnMap[$item['label']] = $item['count'];
        }

        $overlapRows = [];
        foreach ($ranked as $item) {
            if (!isset($churnMap[$item['label']])) {
                continue;
            }

            $overlapRows[] = [
                $item['label'],
                (string) $item['count'],
                (string) $churnMap[$item['label']],
            ];
        }

        $riskMessage = match (count($overlapRows)) {
            0 => 'Risk assessment: bug-fix churn does not overlap with the highest-churn files in this window, so the repo may have clearer hotspots separation.',
            1 => sprintf('Risk assessment: %s is both a churn hotspot and a bug hotspot. Start your code reading there.', $overlapRows[0][0]),
            default => sprintf('Risk assessment: %s files overlap between churn and bug hotspots. Those are the most failure-prone areas to inspect first.', count($overlapRows)),
        };

        $lines = [
            sprintf('Bug hotspots for %s', $this->describePeriod($period)),
            '',
            $this->renderTable(['#', 'File', 'Bug-fix commits'], $this->rankedRows($ranked, 'count')),
            '',
        ];

        if ($overlapRows !== []) {
            $lines[] = 'Overlap with churn hotspots';
            $lines[] = '';
            $lines[] = $this->renderTable(['File', 'Bug-fix commits', 'Churn events'], $overlapRows);
            $lines[] = '';
        }

        $lines[] = $riskMessage;

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function velocityTrend(array $args): ToolResult
    {
        $period = $this->readPeriod($args, 'period', self::VELOCITY_PERIODS, 'all-time');
        $granularity = trim((string) ($args['granularity'] ?? 'month'));
        $path = $this->readPath($args);

        if (!in_array($granularity, ['month', 'quarter', 'year'], true)) {
            return ToolResult::error('The "granularity" parameter must be one of: month, quarter, year.');
        }

        $dates = $this->collectCommitDates($period, $path);
        if ($dates['error'] !== null) {
            return ToolResult::error($dates['error']);
        }

        if ($dates['dates'] === []) {
            return ToolResult::success(
                sprintf('No commit activity found for %s.', $this->describePeriod($period)),
            );
        }

        $monthly = $this->buildMonthlySeries($dates['dates']);
        $series = $this->aggregateSeries($monthly, $granularity);
        $trend = $this->detectTrend(array_values($series));
        $anomalies = $this->detectAnomalies($series);

        $rows = [];
        foreach ($series as $label => $count) {
            $rows[] = [$label, (string) $count];
        }

        $lines = [
            sprintf('Commit velocity trend for %s', $this->describePeriod($period)),
            '',
            $this->renderTable(['Period', 'Commits'], $rows),
            '',
            sprintf('Trend: %s', $trend),
        ];

        if ($anomalies !== []) {
            $lines[] = 'Anomalies:';
            foreach ($anomalies as $anomaly) {
                $lines[] = '- ' . $anomaly;
            }
        } else {
            $lines[] = 'Anomalies: none that exceed the reporting threshold.';
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function crisisDetection(array $args): ToolResult
    {
        $period = $this->readPeriod($args, 'period', self::HOTSPOT_PERIODS, '1-year');
        $keywords = trim((string) ($args['keywords'] ?? 'revert|hotfix|emergency|rollback'));
        $path = $this->readPath($args);

        if (!isset(self::CRISIS_PERIOD_MONTHS[$period])) {
            return ToolResult::error('The "period" parameter for git_crisis_detection must be one of: 1-month, 3-months, 6-months, 1-year.');
        }

        if ($keywords === '') {
            return ToolResult::error('The "keywords" parameter cannot be empty.');
        }

        $events = $this->collectCrisisEvents($period, $keywords, $path);
        if ($events['error'] !== null) {
            return ToolResult::error($events['error']);
        }

        $months = self::CRISIS_PERIOD_MONTHS[$period];
        $count = count($events['events']);
        $eventsPerMonth = $count / $months;
        $assessment = $count === 0
            ? 'STABLE'
            : ($eventsPerMonth <= 0.75 ? 'CAUTIOUS' : 'HIGH_ALERT');

        if ($count === 0) {
            return ToolResult::success(
                sprintf(
                    'Crisis detection for %s: STABLE. No revert, hotfix, emergency, or rollback commits matched the configured keywords.',
                    $this->describePeriod($period),
                ),
            );
        }

        $display = array_slice($events['events'], 0, 10);
        $rows = array_map(
            static fn(array $event): array => [$event['hash'], $event['date'], $event['type'], $event['message']],
            $display,
        );

        $lines = [
            sprintf('Crisis detection for %s', $this->describePeriod($period)),
            '',
            $this->renderTable(['Commit', 'Date', 'Type', 'Message'], $rows),
            '',
            sprintf('Assessment: %s (%.2f events/month across %d matched commit%s).', $assessment, $eventsPerMonth, $count, $count === 1 ? '' : 's'),
            'Interpretation: repeated revert or hotfix traffic often points to deploy fear, weak test coverage, or rollback-heavy release practices.',
        ];

        if ($count > count($display)) {
            $lines[] = sprintf('Showing the latest %d matched commits out of %d total.', count($display), $count);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, string> $validPeriods
     */
    private function readPeriod(array $args, string $key, array $validPeriods, string $default): string
    {
        $period = trim((string) ($args[$key] ?? $default));

        return array_key_exists($period, $validPeriods) ? $period : $default;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function readLimit(array $args, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int) ($args[$key] ?? $default);

        return max($minimum, min($maximum, $value));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function readPath(array $args): string
    {
        return trim((string) ($args['path'] ?? ''));
    }

    private function describePeriod(string $period): string
    {
        return $period === 'all-time' ? 'all time' : $period;
    }

    /**
     * @param list<string> $extraArgs
     * @return array{counts: array<string, int>, error: ?string}
     */
    private function collectFileCounts(string $period, string $path, array $extraArgs = []): array
    {
        $gitArgs = ['--format=format:', '--name-only'];
        $since = self::HOTSPOT_PERIODS[$period] ?? '';
        if ($since !== '') {
            $gitArgs[] = '--since=' . $since;
        }

        foreach ($extraArgs as $arg) {
            $gitArgs[] = $arg;
        }

        $result = $this->runner->run('log', $gitArgs, $path);
        if (!$result->succeeded()) {
            if ($this->isEmptyHistory($result)) {
                return ['counts' => [], 'error' => null];
            }

            return ['counts' => [], 'error' => $result->output()];
        }

        $counts = [];
        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            $file = trim($line);
            if ($file === '') {
                continue;
            }

            $counts[$file] = ($counts[$file] ?? 0) + 1;
        }

        return ['counts' => $counts, 'error' => null];
    }

    /**
     * @return array{contributors: list<array{name: string, email: string, commits: int}>, error: ?string}
     */
    private function collectContributors(string $period, bool $ignoreMerges, string $path): array
    {
        $gitArgs = ['-s', '-n', '-e', '--all'];
        if ($ignoreMerges) {
            $gitArgs[] = '--no-merges';
        }

        $since = self::CONTRIBUTOR_PERIODS[$period] ?? '';
        if ($since !== '') {
            $gitArgs[] = '--since=' . $since;
        }

        $result = $this->runner->run('shortlog', $gitArgs, $path);
        if (!$result->succeeded()) {
            if ($this->isEmptyHistory($result)) {
                return ['contributors' => [], 'error' => null];
            }

            return ['contributors' => [], 'error' => $result->output()];
        }

        $contributors = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (!preg_match('/^\s*(\d+)\s+(.+?)(?:\s+<([^>]+)>)?$/', $line, $matches)) {
                continue;
            }

            $contributors[] = [
                'name' => trim($matches[2]),
                'email' => isset($matches[3]) ? trim($matches[3]) : '',
                'commits' => (int) $matches[1],
            ];
        }

        return ['contributors' => $contributors, 'error' => null];
    }

    /**
     * @return array{dates: list<string>, error: ?string}
     */
    private function collectCommitDates(string $period, string $path): array
    {
        $gitArgs = ['--format=%ad', '--date=format:%Y-%m'];
        $since = self::VELOCITY_PERIODS[$period] ?? '';
        if ($since !== '') {
            $gitArgs[] = '--since=' . $since;
        }

        $result = $this->runner->run('log', $gitArgs, $path);
        if (!$result->succeeded()) {
            if ($this->isEmptyHistory($result)) {
                return ['dates' => [], 'error' => null];
            }

            return ['dates' => [], 'error' => $result->output()];
        }

        $dates = array_values(array_filter(
            array_map(static fn(string $line): string => trim($line), preg_split('/\R/', $result->stdout) ?: []),
            static fn(string $line): bool => $line !== '',
        ));

        return ['dates' => $dates, 'error' => null];
    }

    /**
     * @return array{events: list<array{hash: string, date: string, message: string, type: string}>, error: ?string}
     */
    private function collectCrisisEvents(string $period, string $keywords, string $path): array
    {
        $gitArgs = [
            '--format=%h%x09%ad%x09%s',
            '--date=short',
            '--regexp-ignore-case',
            '--extended-regexp',
            '--grep=' . $keywords,
            '--since=' . self::HOTSPOT_PERIODS[$period],
        ];

        $result = $this->runner->run('log', $gitArgs, $path);
        if (!$result->succeeded()) {
            if ($this->isEmptyHistory($result)) {
                return ['events' => [], 'error' => null];
            }

            return ['events' => [], 'error' => $result->output()];
        }

        $events = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);
            if (count($parts) !== 3) {
                continue;
            }

            $events[] = [
                'hash' => $parts[0],
                'date' => $parts[1],
                'message' => $parts[2],
                'type' => $this->classifyCrisisType($parts[2]),
            ];
        }

        return ['events' => $events, 'error' => null];
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{label: string, count: int}>
     */
    private function rankCounts(array $counts, int $limit): array
    {
        $items = [];
        foreach ($counts as $label => $count) {
            $items[] = ['label' => $label, 'count' => $count];
        }

        usort(
            $items,
            static function (array $left, array $right): int {
                if ($left['count'] === $right['count']) {
                    return strcmp($left['label'], $right['label']);
                }

                return $right['count'] <=> $left['count'];
            },
        );

        return array_slice($items, 0, $limit);
    }

    /**
     * @param list<array{label: string, count: int}> $items
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function rankedRows(array $items, string $countLabel): array
    {
        $rows = [];
        foreach ($items as $index => $item) {
            $rows[] = [
                (string) ($index + 1),
                $item['label'],
                (string) $item[$countLabel],
            ];
        }

        return $rows;
    }

    /**
    * @param list<string> $headers
    * @param list<array<int, string>> $rows
     */
    private function renderTable(array $headers, array $rows): string
    {
        $headerLine = '| ' . implode(' | ', $headers) . ' |';
        $separator = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
        $body = array_map(
            static fn(array $row): string => '| ' . implode(' | ', array_map(static fn(string $value): string => str_replace('|', '\\|', $value), $row)) . ' |',
            $rows,
        );

        return implode("\n", array_merge([$headerLine, $separator], $body));
    }

    /**
     * @param list<string> $dates
     * @return array<string, int>
     */
    private function buildMonthlySeries(array $dates): array
    {
        $counts = [];
        foreach ($dates as $date) {
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        ksort($counts);
        $labels = array_keys($counts);
        if ($labels === []) {
            return [];
        }

        try {
            $start = new DateTimeImmutable($labels[0] . '-01');
            $end = new DateTimeImmutable(end($labels) . '-01');
        } catch (Exception) {
            return $counts;
        }

        $series = [];
        $period = new DatePeriod($start, new DateInterval('P1M'), $end->modify('+1 month'));
        foreach ($period as $date) {
            $label = $date->format('Y-m');
            $series[$label] = $counts[$label] ?? 0;
        }

        return $series;
    }

    /**
     * @param array<string, int> $monthlySeries
     * @return array<string, int>
     */
    private function aggregateSeries(array $monthlySeries, string $granularity): array
    {
        if ($granularity === 'month') {
            return $monthlySeries;
        }

        $series = [];
        foreach ($monthlySeries as $label => $count) {
            [$year, $month] = explode('-', $label, 2);

            $bucket = $granularity === 'year'
                ? $year
                : sprintf('%s-Q%d', $year, (int) ceil(((int) $month) / 3));

            $series[$bucket] = ($series[$bucket] ?? 0) + $count;
        }

        ksort($series);

        return $series;
    }

    /**
     * @param list<int> $values
     */
    private function detectTrend(array $values): string
    {
        if (count($values) < 2) {
            return 'insufficient data';
        }

        $mean = array_sum($values) / count($values);
        $firstHalf = array_slice($values, 0, (int) ceil(count($values) / 2));
        $secondHalf = array_slice($values, (int) floor(count($values) / 2));

        $firstAverage = array_sum($firstHalf) / max(1, count($firstHalf));
        $secondAverage = array_sum($secondHalf) / max(1, count($secondHalf));

        if ($firstAverage > 0 && $secondAverage >= $firstAverage * 1.25) {
            return 'accelerating';
        }

        if ($firstAverage > 0 && $secondAverage <= $firstAverage * 0.75) {
            return 'declining';
        }

        if ($mean > 0 && (max($values) - min($values)) / $mean >= 1.4 && count($values) >= 4) {
            return 'volatile';
        }

        return 'steady';
    }

    /**
     * @param array<string, int> $series
     * @return list<string>
     */
    private function detectAnomalies(array $series): array
    {
        $labels = array_keys($series);
        $values = array_values($series);
        $anomalies = [];

        for ($index = 1; $index < count($values); $index++) {
            $previous = $values[$index - 1];
            $current = $values[$index];
            $label = $labels[$index];

            if ($previous > 0 && $current <= (int) floor($previous * 0.5)) {
                $anomalies[] = sprintf('%s dropped from %d to %d commits.', $label, $previous, $current);
                continue;
            }

            if ($previous > 0 && $current >= (int) ceil($previous * 1.75)) {
                $anomalies[] = sprintf('%s jumped from %d to %d commits.', $label, $previous, $current);
            }
        }

        return $anomalies;
    }

    private function classifyCrisisType(string $message): string
    {
        foreach (self::CRISIS_TYPE_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return $label;
            }
        }

        return 'matched-pattern';
    }

    private function isEmptyHistory(GitResult $result): bool
    {
        $output = strtolower($result->output());

        return str_contains($output, 'does not have any commits yet')
            || str_contains($output, 'your current branch')
            || str_contains($output, 'unknown revision or path not in the working tree')
            || str_contains($output, 'ambiguous argument')
            || ($result->exitCode === 0 && trim($result->stdout) === '');
    }
}