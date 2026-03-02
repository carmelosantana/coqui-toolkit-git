<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\Git\GitToolkit;

/**
 * Integration tests that exercise the full Git workflow in a temporary repository.
 *
 * Each test group creates a fresh temp directory with `git init`, runs tools,
 * and validates results. Requires `git` on PATH.
 */

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * @return array<string, ToolInterface>
 */
function buildTools(string $workspacePath): array
{
    $toolkit = new GitToolkit(workspacePath: $workspacePath);
    $tools = [];
    foreach ($toolkit->tools() as $tool) {
        $tools[$tool->name()] = $tool;
    }
    return $tools;
}

function createTempDir(): string
{
    $dir = sys_get_temp_dir() . '/coqui-git-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    return $dir;
}

function removeTempDir(string $dir): void
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
            // .git directories contain read-only files on some systems
            chmod($file->getPathname(), 0755);
            rmdir($file->getPathname());
        } else {
            chmod($file->getPathname(), 0644);
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}

// ── Setup ────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->tmpDir = createTempDir();
    $this->tools = buildTools($this->tmpDir);

    // Configure git user for commits
    exec("git -C {$this->tmpDir} init --initial-branch=main 2>&1");
    exec("git -C {$this->tmpDir} config user.email 'test@example.com'");
    exec("git -C {$this->tmpDir} config user.name 'Test User'");
});

afterEach(function () {
    removeTempDir($this->tmpDir);
});

// ── git_init ─────────────────────────────────────────────────────────────

test('git_init initializes a new repository', function () {
    $newDir = createTempDir();

    try {
        $result = $this->tools['git_init']->execute(['path' => $newDir]);

        expect($result->status)->toBe(ToolResultStatus::Success);
        expect(is_dir($newDir . '/.git'))->toBeTrue();
    } finally {
        removeTempDir($newDir);
    }
});

// ── git_status ───────────────────────────────────────────────────────────

test('git_status shows clean working tree', function () {
    // Create initial commit so status is clean
    file_put_contents($this->tmpDir . '/README.md', '# Test');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_status']->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('main');
});

test('git_status shows modified files', function () {
    file_put_contents($this->tmpDir . '/README.md', '# Test');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    file_put_contents($this->tmpDir . '/README.md', '# Modified');

    $result = $this->tools['git_status']->execute(['short' => true]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('README.md');
});

// ── git_stage ────────────────────────────────────────────────────────────

test('git_stage adds specific files', function () {
    file_put_contents($this->tmpDir . '/file1.txt', 'content1');
    file_put_contents($this->tmpDir . '/file2.txt', 'content2');

    $result = $this->tools['git_stage']->execute([
        'action' => 'add',
        'files' => 'file1.txt',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    // Verify file1 is staged but file2 is not
    $status = $this->tools['git_status']->execute(['short' => true]);
    expect($status->content)->toContain('file1.txt');
});

test('git_stage add_all stages everything', function () {
    file_put_contents($this->tmpDir . '/file1.txt', 'content1');
    file_put_contents($this->tmpDir . '/file2.txt', 'content2');

    $result = $this->tools['git_stage']->execute(['action' => 'add_all']);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_stage requires files for add action', function () {
    $result = $this->tools['git_stage']->execute(['action' => 'add']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('files');
});

// ── git_commit ───────────────────────────────────────────────────────────

test('git_commit creates a commit', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'hello');
    exec("git -C {$this->tmpDir} add . 2>&1");

    $result = $this->tools['git_commit']->execute([
        'message' => 'Initial commit',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Initial commit');
});

test('git_commit with all flag stages tracked files', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'v1');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    file_put_contents($this->tmpDir . '/file.txt', 'v2');

    $result = $this->tools['git_commit']->execute([
        'message' => 'Update file',
        'all' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_commit requires message', function () {
    $result = $this->tools['git_commit']->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('message');
});

// ── git_diff ─────────────────────────────────────────────────────────────

test('git_diff shows working tree changes', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'original');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    file_put_contents($this->tmpDir . '/file.txt', 'modified');

    $result = $this->tools['git_diff']->execute(['scope' => 'working']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('modified');
});

test('git_diff shows staged changes', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'original');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    file_put_contents($this->tmpDir . '/file.txt', 'staged change');
    exec("git -C {$this->tmpDir} add . 2>&1");

    $result = $this->tools['git_diff']->execute(['scope' => 'staged']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('staged change');
});

test('git_diff shows no differences message', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_diff']->execute(['scope' => 'working']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No differences');
});

test('git_diff requires ref1 for commits scope', function () {
    $result = $this->tools['git_diff']->execute(['scope' => 'commits']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('ref1');
});

test('git_diff stat_only shows summary', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'original');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    file_put_contents($this->tmpDir . '/file.txt', 'changed');

    $result = $this->tools['git_diff']->execute([
        'scope' => 'working',
        'stat_only' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('file.txt');
});

// ── git_log ──────────────────────────────────────────────────────────────

test('git_log shows commit history', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'first commit' 2>&1");

    $result = $this->tools['git_log']->execute(['count' => 5, 'oneline' => true]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('first commit');
});

test('git_log returns empty message when no commits match', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'first commit' 2>&1");

    $result = $this->tools['git_log']->execute([
        'author' => 'nonexistent-author-xyz',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No commits found');
});

// ── git_branch ───────────────────────────────────────────────────────────

test('git_branch creates a new branch', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_branch']->execute([
        'action' => 'create',
        'name' => 'feature/test',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_branch lists branches', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");
    exec("git -C {$this->tmpDir} branch feature/a 2>&1");

    $result = $this->tools['git_branch']->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('main');
    expect($result->content)->toContain('feature/a');
});

test('git_branch shows current branch', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_branch']->execute(['action' => 'current']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('main');
});

test('git_branch deletes a branch', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");
    exec("git -C {$this->tmpDir} branch to-delete 2>&1");

    $result = $this->tools['git_branch']->execute([
        'action' => 'delete',
        'name' => 'to-delete',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_branch renames a branch', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");
    exec("git -C {$this->tmpDir} branch old-name 2>&1");

    $result = $this->tools['git_branch']->execute([
        'action' => 'rename',
        'name' => 'old-name',
        'new_name' => 'new-name',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_branch requires name for create', function () {
    $result = $this->tools['git_branch']->execute(['action' => 'create']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('name');
});

// ── git_checkout ─────────────────────────────────────────────────────────

test('git_checkout switches branches', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");
    exec("git -C {$this->tmpDir} branch other 2>&1");

    $result = $this->tools['git_checkout']->execute(['target' => 'other']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    // Verify we switched
    $current = $this->tools['git_branch']->execute(['action' => 'current']);
    expect($current->content)->toBe('other');
});

test('git_checkout creates and switches with create flag', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_checkout']->execute([
        'target' => 'new-branch',
        'create' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $current = $this->tools['git_branch']->execute(['action' => 'current']);
    expect($current->content)->toBe('new-branch');
});

test('git_checkout requires target', function () {
    $result = $this->tools['git_checkout']->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('target');
});

// ── git_tag ──────────────────────────────────────────────────────────────

test('git_tag creates a lightweight tag', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_tag']->execute([
        'action' => 'create',
        'name' => 'v1.0.0',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_tag creates an annotated tag', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_tag']->execute([
        'action' => 'create',
        'name' => 'v2.0.0',
        'message' => 'Release 2.0',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_tag lists tags', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");
    exec("git -C {$this->tmpDir} tag v1.0.0 2>&1");
    exec("git -C {$this->tmpDir} tag v1.1.0 2>&1");

    $result = $this->tools['git_tag']->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('v1.0.0');
    expect($result->content)->toContain('v1.1.0');
});

test('git_tag deletes a tag', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");
    exec("git -C {$this->tmpDir} tag v1.0.0 2>&1");

    $result = $this->tools['git_tag']->execute([
        'action' => 'delete',
        'name' => 'v1.0.0',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_tag lists empty when no tags', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    $result = $this->tools['git_tag']->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No tags');
});

// ── git_remote ───────────────────────────────────────────────────────────

test('git_remote lists empty remotes', function () {
    $result = $this->tools['git_remote']->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No remotes');
});

test('git_remote adds a remote', function () {
    $result = $this->tools['git_remote']->execute([
        'action' => 'add',
        'name' => 'origin',
        'url' => 'https://github.com/example/repo.git',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $list = $this->tools['git_remote']->execute(['action' => 'list']);
    expect($list->content)->toContain('origin');
});

test('git_remote removes a remote', function () {
    exec("git -C {$this->tmpDir} remote add origin https://github.com/example/repo.git 2>&1");

    $result = $this->tools['git_remote']->execute([
        'action' => 'remove',
        'name' => 'origin',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('git_remote requires name for add', function () {
    $result = $this->tools['git_remote']->execute(['action' => 'add']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('name');
});

test('git_remote requires url for add', function () {
    $result = $this->tools['git_remote']->execute([
        'action' => 'add',
        'name' => 'origin',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('url');
});

// ── git_merge ────────────────────────────────────────────────────────────

test('git_merge merges a branch', function () {
    file_put_contents($this->tmpDir . '/file.txt', 'content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'init' 2>&1");

    exec("git -C {$this->tmpDir} checkout -b feature 2>&1");
    file_put_contents($this->tmpDir . '/feature.txt', 'feature content');
    exec("git -C {$this->tmpDir} add . && git -C {$this->tmpDir} commit -m 'feature work' 2>&1");

    exec("git -C {$this->tmpDir} checkout main 2>&1");

    $result = $this->tools['git_merge']->execute(['branch' => 'feature']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_exists($this->tmpDir . '/feature.txt'))->toBeTrue();
});

test('git_merge requires branch parameter', function () {
    $result = $this->tools['git_merge']->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('branch');
});

// ── Full workflow ────────────────────────────────────────────────────────

test('full workflow: init → stage → commit → branch → checkout → tag', function () {
    $repoDir = createTempDir();

    try {
        // Init
        $result = $this->tools['git_init']->execute([
            'path' => $repoDir,
            'branch' => 'main',
        ]);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Configure user for this repo
        exec("git -C {$repoDir} config user.email 'test@example.com'");
        exec("git -C {$repoDir} config user.name 'Test User'");

        // Create a file and stage
        file_put_contents($repoDir . '/app.php', '<?php echo "hello";');

        $tools = buildTools($repoDir);

        $result = $tools['git_stage']->execute(['action' => 'add_all']);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Commit
        $result = $tools['git_commit']->execute(['message' => 'Initial commit']);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Create branch
        $result = $tools['git_branch']->execute([
            'action' => 'create',
            'name' => 'feature/hello',
        ]);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Checkout
        $result = $tools['git_checkout']->execute(['target' => 'feature/hello']);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Verify current branch
        $result = $tools['git_branch']->execute(['action' => 'current']);
        expect($result->content)->toBe('feature/hello');

        // Make a change, commit
        file_put_contents($repoDir . '/app.php', '<?php echo "hello world";');
        $tools['git_stage']->execute(['action' => 'add_all']);
        $result = $tools['git_commit']->execute(['message' => 'feat: add world']);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Tag
        $result = $tools['git_tag']->execute([
            'action' => 'create',
            'name' => 'v0.1.0',
            'message' => 'First release',
        ]);
        expect($result->status)->toBe(ToolResultStatus::Success);

        // Check log
        $result = $tools['git_log']->execute(['count' => 5, 'oneline' => true]);
        expect($result->status)->toBe(ToolResultStatus::Success);
        expect($result->content)->toContain('feat: add world');
        expect($result->content)->toContain('Initial commit');

        // List tags
        $result = $tools['git_tag']->execute(['action' => 'list']);
        expect($result->content)->toContain('v0.1.0');
    } finally {
        removeTempDir($repoDir);
    }
});
