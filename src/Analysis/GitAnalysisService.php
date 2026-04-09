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

        $report = $this->buildChurnHotspotsReport($period, $limit, $path);
        if ($report['error'] !== null) {
            return ToolResult::error($report['error']);
        }

        return $this->successPayload($report['payload']);
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

        $report = $this->buildContributorRankingReport($period, $limit, $ignoreMerges, $path);
        if ($report['error'] !== null) {
            return ToolResult::error($report['error']);
        }

        return $this->successPayload($report['payload']);
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

        $report = $this->buildBugHotspotsReport($period, $limit, $keywords, $path);
        if ($report['error'] !== null) {
            return ToolResult::error($report['error']);
        }

        return $this->successPayload($report['payload']);
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

        $report = $this->buildVelocityTrendReport($period, $granularity, $path);
        if ($report['error'] !== null) {
            return ToolResult::error($report['error']);
        }

        return $this->successPayload($report['payload']);
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

        $report = $this->buildCrisisDetectionReport($period, $keywords, $path);
        if ($report['error'] !== null) {
            return ToolResult::error($report['error']);
        }

        return $this->successPayload($report['payload']);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function repoTriage(array $args): ToolResult
    {
        $hotspotPeriod = $this->readPeriod($args, 'hotspot_period', self::HOTSPOT_PERIODS, '1-year');
        $contributorPeriod = $this->readPeriod($args, 'contributor_period', self::CONTRIBUTOR_PERIODS, 'all-time');
        $velocityPeriod = $this->readPeriod($args, 'velocity_period', self::VELOCITY_PERIODS, 'all-time');
        $velocityGranularity = trim((string) ($args['velocity_granularity'] ?? 'month'));
        $crisisPeriod = $this->readPeriod($args, 'crisis_period', self::HOTSPOT_PERIODS, '1-year');
        $limit = $this->readLimit($args, 'limit', 20, 1, 50);
        $bugKeywords = trim((string) ($args['bug_keywords'] ?? 'fix|bug|broken'));
        $crisisKeywords = trim((string) ($args['crisis_keywords'] ?? 'revert|hotfix|emergency|rollback'));
        $path = $this->readPath($args);

        if (!in_array($velocityGranularity, ['month', 'quarter', 'year'], true)) {
            return ToolResult::error('The "velocity_granularity" parameter must be one of: month, quarter, year.');
        }

        if (!isset(self::CRISIS_PERIOD_MONTHS[$crisisPeriod])) {
            return ToolResult::error('The "crisis_period" parameter must be one of: 1-month, 3-months, 6-months, 1-year.');
        }

        if ($bugKeywords === '' || $crisisKeywords === '') {
            return ToolResult::error('The "bug_keywords" and "crisis_keywords" parameters cannot be empty.');
        }

        $churn = $this->buildChurnHotspotsReport($hotspotPeriod, $limit, $path);
        if ($churn['error'] !== null) {
            return ToolResult::error($churn['error']);
        }

        $contributors = $this->buildContributorRankingReport($contributorPeriod, $limit, true, $path);
        if ($contributors['error'] !== null) {
            return ToolResult::error($contributors['error']);
        }

        $bug = $this->buildBugHotspotsReport($hotspotPeriod, $limit, $bugKeywords, $path);
        if ($bug['error'] !== null) {
            return ToolResult::error($bug['error']);
        }

        $velocity = $this->buildVelocityTrendReport($velocityPeriod, $velocityGranularity, $path);
        if ($velocity['error'] !== null) {
            return ToolResult::error($velocity['error']);
        }

        $crisis = $this->buildCrisisDetectionReport($crisisPeriod, $crisisKeywords, $path);
        if ($crisis['error'] !== null) {
            return ToolResult::error($crisis['error']);
        }

        $payload = [
            'analysis' => 'repo_triage',
            'status' => 'ok',
            'repository_path' => $path,
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'inputs' => [
                'hotspot_period' => $hotspotPeriod,
                'contributor_period' => $contributorPeriod,
                'velocity_period' => $velocityPeriod,
                'velocity_granularity' => $velocityGranularity,
                'crisis_period' => $crisisPeriod,
                'limit' => $limit,
                'bug_keywords' => $bugKeywords,
                'crisis_keywords' => $crisisKeywords,
            ],
            'churn_hotspots' => $churn['payload'],
            'contributor_ranking' => $contributors['payload'],
            'bug_hotspots' => $bug['payload'],
            'velocity_trend' => $velocity['payload'],
            'crisis_detection' => $crisis['payload'],
            'priority_signals' => [
                'priority_files' => $this->derivePriorityFiles($churn['payload'], $bug['payload']),
                'bus_factor_warning' => (bool) ($contributors['payload']['bus_factor']['warning'] ?? false),
                'maintainer_drift' => (bool) ($contributors['payload']['maintainer_drift']['missing_from_recent_window'] ?? false),
                'delivery_risk' => $this->deriveDeliveryRisk($velocity['payload'], $crisis['payload']),
            ],
        ];

        return $this->successPayload($payload);
    }

    /**
     * @return array{payload: array<string, mixed>, error: ?string}
     */
    private function buildChurnHotspotsReport(string $period, int $limit, string $path): array
    {
        $counts = $this->collectFileCounts($period, $path);
        if ($counts['error'] !== null) {
            return ['payload' => [], 'error' => $counts['error']];
        }

        $ranked = $this->rankCounts($counts['counts'], $limit);

        return [
            'payload' => [
                'analysis' => 'churn_hotspots',
                'status' => $ranked === [] ? 'empty' : 'ok',
                'repository_path' => $path,
                'period' => $period,
                'limit' => $limit,
                'top_file' => $ranked[0]['label'] ?? null,
                'items' => array_map(
                    static fn(array $item): array => [
                        'file' => $item['label'],
                        'change_count' => $item['count'],
                    ],
                    $ranked,
                ),
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, error: ?string}
     */
    private function buildContributorRankingReport(string $period, int $limit, bool $ignoreMerges, string $path): array
    {
        $selected = $this->collectContributors($period, $ignoreMerges, $path);
        if ($selected['error'] !== null) {
            return ['payload' => [], 'error' => $selected['error']];
        }

        $overall = $this->collectContributors('all-time', $ignoreMerges, $path);
        if ($overall['error'] !== null) {
            return ['payload' => [], 'error' => $overall['error']];
        }

        $recent = $this->collectContributors('6-months', $ignoreMerges, $path);
        if ($recent['error'] !== null) {
            return ['payload' => [], 'error' => $recent['error']];
        }

        $display = array_slice($selected['contributors'], 0, $limit);
        $totalCommits = array_sum(array_column($selected['contributors'], 'commits'));
        $topContributor = $selected['contributors'][0] ?? null;
        $topPercent = $topContributor !== null && $totalCommits > 0
            ? round(($topContributor['commits'] / $totalCommits) * 100, 2)
            : 0.0;

        $allTimeTop = $overall['contributors'][0] ?? null;
        $recentNames = array_map(
            static fn(array $contributor): string => strtolower($contributor['name']),
            $recent['contributors'],
        );

        $missingFromRecentWindow = $allTimeTop !== null
            && !in_array(strtolower($allTimeTop['name']), $recentNames, true);

        return [
            'payload' => [
                'analysis' => 'contributor_ranking',
                'status' => $display === [] ? 'empty' : 'ok',
                'repository_path' => $path,
                'period' => $period,
                'limit' => $limit,
                'merge_strategy' => $ignoreMerges ? 'ignore-merges' : 'count-merges',
                'contributors' => array_map(
                    static fn(array $contributor): array => [
                        'name' => $contributor['name'],
                        'email' => $contributor['email'],
                        'commit_count' => $contributor['commits'],
                    ],
                    $display,
                ),
                'bus_factor' => [
                    'top_contributor' => $topContributor['name'] ?? null,
                    'top_percent' => $topPercent,
                    'warning' => $topPercent >= 60.0,
                ],
                'maintainer_drift' => [
                    'recent_window' => '6-months',
                    'top_all_time_contributor' => $allTimeTop['name'] ?? null,
                    'missing_from_recent_window' => $missingFromRecentWindow,
                ],
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, error: ?string}
     */
    private function buildBugHotspotsReport(string $period, int $limit, string $keywords, string $path): array
    {
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
            return ['payload' => [], 'error' => $bugCounts['error']];
        }

        $churnCounts = $this->collectFileCounts($period, $path);
        if ($churnCounts['error'] !== null) {
            return ['payload' => [], 'error' => $churnCounts['error']];
        }

        $ranked = $this->rankCounts($bugCounts['counts'], $limit);
        $churnRanked = $this->rankCounts($churnCounts['counts'], $limit);
        $churnMap = [];
        foreach ($churnRanked as $item) {
            $churnMap[$item['label']] = $item['count'];
        }

        $overlap = [];
        foreach ($ranked as $item) {
            if (!isset($churnMap[$item['label']])) {
                continue;
            }

            $overlap[] = [
                'file' => $item['label'],
                'bug_fix_count' => $item['count'],
                'change_count' => $churnMap[$item['label']],
            ];
        }

        return [
            'payload' => [
                'analysis' => 'bug_hotspots',
                'status' => $ranked === [] ? 'empty' : 'ok',
                'repository_path' => $path,
                'period' => $period,
                'limit' => $limit,
                'keywords' => $keywords,
                'top_file' => $ranked[0]['label'] ?? null,
                'items' => array_map(
                    static fn(array $item): array => [
                        'file' => $item['label'],
                        'bug_fix_count' => $item['count'],
                    ],
                    $ranked,
                ),
                'overlap_with_churn' => $overlap,
                'risk_level' => $overlap !== [] ? 'high' : ($ranked !== [] ? 'medium' : 'low'),
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, error: ?string}
     */
    private function buildVelocityTrendReport(string $period, string $granularity, string $path): array
    {
        $dates = $this->collectCommitDates($period, $path);
        if ($dates['error'] !== null) {
            return ['payload' => [], 'error' => $dates['error']];
        }

        if ($dates['dates'] === []) {
            return [
                'payload' => [
                    'analysis' => 'velocity_trend',
                    'status' => 'empty',
                    'repository_path' => $path,
                    'period' => $period,
                    'granularity' => $granularity,
                    'trend' => 'insufficient_data',
                    'series' => [],
                    'anomalies' => [],
                ],
                'error' => null,
            ];
        }

        $monthly = $this->buildMonthlySeries($dates['dates']);
        $series = $this->aggregateSeries($monthly, $granularity);

        return [
            'payload' => [
                'analysis' => 'velocity_trend',
                'status' => 'ok',
                'repository_path' => $path,
                'period' => $period,
                'granularity' => $granularity,
                'trend' => $this->detectTrend(array_values($series)),
                'series' => array_map(
                    static fn(string $label, int $count): array => [
                        'period' => $label,
                        'commit_count' => $count,
                    ],
                    array_keys($series),
                    array_values($series),
                ),
                'anomalies' => $this->detectAnomalies($series),
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, error: ?string}
     */
    private function buildCrisisDetectionReport(string $period, string $keywords, string $path): array
    {
        $events = $this->collectCrisisEvents($period, $keywords, $path);
        if ($events['error'] !== null) {
            return ['payload' => [], 'error' => $events['error']];
        }

        $months = self::CRISIS_PERIOD_MONTHS[$period];
        $count = count($events['events']);
        $eventsPerMonth = round($count / $months, 2);
        $assessment = $count === 0
            ? 'STABLE'
            : ($eventsPerMonth <= 0.75 ? 'CAUTIOUS' : 'HIGH_ALERT');

        return [
            'payload' => [
                'analysis' => 'crisis_detection',
                'status' => $count === 0 ? 'empty' : 'ok',
                'repository_path' => $path,
                'period' => $period,
                'keywords' => $keywords,
                'assessment' => $assessment,
                'event_count' => $count,
                'events_per_month' => $eventsPerMonth,
                'events' => $events['events'],
            ],
            'error' => null,
        ];
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
            return 'insufficient_data';
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

        return 'matched_pattern';
    }

    /**
     * @param array<string, mixed> $churn
     * @param array<string, mixed> $bug
     * @return list<string>
     */
    private function derivePriorityFiles(array $churn, array $bug): array
    {
        $priority = [];

        foreach (($bug['overlap_with_churn'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['file'])) {
                continue;
            }

            $priority[] = (string) $item['file'];
        }

        if ($priority !== []) {
            return array_values(array_unique($priority));
        }

        foreach (array_slice($churn['items'] ?? [], 0, 3) as $item) {
            if (!is_array($item) || !isset($item['file'])) {
                continue;
            }

            $priority[] = (string) $item['file'];
        }

        return array_values(array_unique($priority));
    }

    /**
     * @param array<string, mixed> $velocity
     * @param array<string, mixed> $crisis
     */
    private function deriveDeliveryRisk(array $velocity, array $crisis): string
    {
        $trend = (string) ($velocity['trend'] ?? 'insufficient_data');
        $assessment = (string) ($crisis['assessment'] ?? 'STABLE');

        if ($assessment === 'HIGH_ALERT' || ($trend === 'declining' && $assessment !== 'STABLE')) {
            return 'high';
        }

        if ($assessment === 'CAUTIOUS' || $trend === 'declining' || $trend === 'volatile') {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function successPayload(array $payload): ToolResult
    {
        return ToolResult::success($this->encode($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
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