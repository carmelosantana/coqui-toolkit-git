<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\Git\GitToolkit;

/**
 * @return array<string, mixed>
 */
function decodeAnalysisResult(string $content): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * Integration tests for the higher-level repository analysis tools.
 */

/**
 * @return array<string, ToolInterface>
 */
function buildAnalysisTools(string $workspacePath): array
{
    $toolkit = new GitToolkit(workspacePath: $workspacePath);
    $tools = [];
    foreach ($toolkit->tools() as $tool) {
        $tools[$tool->name()] = $tool;
    }

    return $tools;
}

function createAnalysisTempDir(): string
{
    $dir = sys_get_temp_dir() . '/coqui-git-analysis-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    return $dir;
}

function removeAnalysisTempDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            chmod($file->getPathname(), 0755);
            rmdir($file->getPathname());
        } else {
            chmod($file->getPathname(), 0644);
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}

function commitAnalysisFile(
    string $repoDir,
    string $file,
    string $content,
    string $message,
    string $date,
    string $authorName = 'Test User',
    string $authorEmail = 'test@example.com',
): void {
    $target = $repoDir . '/' . $file;
    $parent = dirname($target);
    if (!is_dir($parent)) {
        mkdir($parent, 0755, true);
    }

    file_put_contents($target, $content);
    exec(sprintf('git -C %s add %s 2>&1', escapeshellarg($repoDir), escapeshellarg($file)));
    exec(sprintf(
        'GIT_AUTHOR_NAME=%s GIT_AUTHOR_EMAIL=%s GIT_COMMITTER_NAME=%s GIT_COMMITTER_EMAIL=%s GIT_AUTHOR_DATE=%s GIT_COMMITTER_DATE=%s git -C %s commit -m %s 2>&1',
        escapeshellarg($authorName),
        escapeshellarg($authorEmail),
        escapeshellarg($authorName),
        escapeshellarg($authorEmail),
        escapeshellarg($date),
        escapeshellarg($date),
        escapeshellarg($repoDir),
        escapeshellarg($message),
    ));
}

beforeEach(function () {
    $this->tmpDir = createAnalysisTempDir();
    $this->tools = buildAnalysisTools($this->tmpDir);

    exec('git -C ' . escapeshellarg($this->tmpDir) . ' init --initial-branch=main 2>&1');
    exec('git -C ' . escapeshellarg($this->tmpDir) . ' config user.email test@example.com');
    exec('git -C ' . escapeshellarg($this->tmpDir) . ' config user.name "Test User"');
});

afterEach(function () {
    removeAnalysisTempDir($this->tmpDir);
});

test('git_churn_hotspots ranks the most changed files first', function () {
    commitAnalysisFile($this->tmpDir, 'src/Hotspot.php', '<?php echo 1;', 'feat: start hotspot', '2025-06-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Hotspot.php', '<?php echo 2;', 'refactor: update hotspot', '2025-08-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Hotspot.php', '<?php echo 3;', 'chore: update hotspot again', '2026-02-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Stable.php', '<?php echo 1;', 'feat: add stable file', '2026-03-01T12:00:00');

    $result = $this->tools['git_churn_hotspots']->execute(['period' => '1-year', 'limit' => 5]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $payload = decodeAnalysisResult($result->content);

    expect($payload['analysis'])->toBe('churn_hotspots');
    expect($payload['items'][0]['file'])->toBe('src/Hotspot.php');
    expect($payload['items'][0]['change_count'])->toBeGreaterThan($payload['items'][1]['change_count']);
});

test('git_contributor_ranking flags bus factor and maintainer drift', function () {
    commitAnalysisFile($this->tmpDir, 'src/Core.php', '<?php echo 1;', 'feat: initial core', '2025-01-10T12:00:00', 'Alice', 'alice@example.com');
    commitAnalysisFile($this->tmpDir, 'src/Core.php', '<?php echo 2;', 'fix: core issue', '2025-02-10T12:00:00', 'Alice', 'alice@example.com');
    commitAnalysisFile($this->tmpDir, 'src/Core.php', '<?php echo 3;', 'refactor: core cleanup', '2025-03-10T12:00:00', 'Alice', 'alice@example.com');
    commitAnalysisFile($this->tmpDir, 'src/Recent.php', '<?php echo 4;', 'feat: recent work', '2026-02-10T12:00:00', 'Bob', 'bob@example.com');

    $result = $this->tools['git_contributor_ranking']->execute(['period' => 'all-time']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $payload = decodeAnalysisResult($result->content);

    expect($payload['analysis'])->toBe('contributor_ranking');
    expect($payload['bus_factor']['warning'])->toBeTrue();
    expect($payload['maintainer_drift']['missing_from_recent_window'])->toBeTrue();
});

test('git_bug_hotspots reports overlap with churn hotspots', function () {
    commitAnalysisFile($this->tmpDir, 'src/Risky.php', '<?php echo 1;', 'feat: add risky area', '2025-06-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Risky.php', '<?php echo 2;', 'fix: risky regression', '2025-07-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Risky.php', '<?php echo 3;', 'bug: risky edge case', '2026-01-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Quiet.php', '<?php echo 1;', 'feat: quiet file', '2026-02-01T12:00:00');

    $result = $this->tools['git_bug_hotspots']->execute(['period' => '1-year']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $payload = decodeAnalysisResult($result->content);

    expect($payload['analysis'])->toBe('bug_hotspots');
    expect($payload['risk_level'])->toBe('high');
    expect($payload['overlap_with_churn'][0]['file'])->toBe('src/Risky.php');
});

test('git_velocity_trend detects declining activity', function () {
    commitAnalysisFile($this->tmpDir, 'src/Velocity.php', '<?php echo 1;', 'feat: jan momentum', '2025-06-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Velocity.php', '<?php echo 2;', 'feat: jul momentum', '2025-07-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Velocity.php', '<?php echo 3;', 'feat: aug momentum', '2025-08-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/Velocity.php', '<?php echo 4;', 'feat: slowdown', '2026-01-01T12:00:00');

    $result = $this->tools['git_velocity_trend']->execute([
        'period' => '2-years',
        'granularity' => 'month',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $payload = decodeAnalysisResult($result->content);

    expect($payload['analysis'])->toBe('velocity_trend');
    expect($payload['trend'])->toBe('declining');
});

test('git_crisis_detection finds revert and hotfix patterns', function () {
    commitAnalysisFile($this->tmpDir, 'src/App.php', '<?php echo 1;', 'feat: release work', '2025-06-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/App.php', '<?php echo 2;', 'revert: rollback broken release', '2025-10-01T12:00:00');
    commitAnalysisFile($this->tmpDir, 'src/App.php', '<?php echo 3;', 'hotfix: restore login', '2026-02-01T12:00:00');

    $result = $this->tools['git_crisis_detection']->execute(['period' => '1-year']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $payload = decodeAnalysisResult($result->content);

    expect($payload['analysis'])->toBe('crisis_detection');
    expect($payload['assessment'])->not->toBe('STABLE');
    expect($payload['events'][0]['type'])->toBe('hotfix');
    expect($payload['events'][1]['type'])->toBe('revert');
});

test('git_repo_triage returns one combined machine-readable report', function () {
    commitAnalysisFile($this->tmpDir, 'src/Risky.php', '<?php echo 1;', 'feat: add risky area', '2025-06-01T12:00:00', 'Alice', 'alice@example.com');
    commitAnalysisFile($this->tmpDir, 'src/Risky.php', '<?php echo 2;', 'fix: risky regression', '2025-07-01T12:00:00', 'Alice', 'alice@example.com');
    commitAnalysisFile($this->tmpDir, 'src/App.php', '<?php echo 3;', 'hotfix: restore login', '2026-02-01T12:00:00', 'Bob', 'bob@example.com');

    $result = $this->tools['git_repo_triage']->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $payload = decodeAnalysisResult($result->content);

    expect($payload['analysis'])->toBe('repo_triage');
    expect($payload)->toHaveKeys([
        'churn_hotspots',
        'contributor_ranking',
        'bug_hotspots',
        'velocity_trend',
        'crisis_detection',
        'priority_signals',
    ]);
    expect($payload['priority_signals']['priority_files'])->toContain('src/Risky.php');
});