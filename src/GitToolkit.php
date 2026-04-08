<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\Git\Analysis\GitAnalysisService;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;
use CoquiBot\Toolkits\Git\Tool\GitBranchTool;
use CoquiBot\Toolkits\Git\Tool\GitCheckoutTool;
use CoquiBot\Toolkits\Git\Tool\GitCommitTool;
use CoquiBot\Toolkits\Git\Tool\GitContributorRankingTool;
use CoquiBot\Toolkits\Git\Tool\GitCrisisDetectionTool;
use CoquiBot\Toolkits\Git\Tool\GitChurnHotspotsTool;
use CoquiBot\Toolkits\Git\Tool\GitDiffTool;
use CoquiBot\Toolkits\Git\Tool\GitBugHotspotsTool;
use CoquiBot\Toolkits\Git\Tool\GitInitTool;
use CoquiBot\Toolkits\Git\Tool\GitLogTool;
use CoquiBot\Toolkits\Git\Tool\GitMergeTool;
use CoquiBot\Toolkits\Git\Tool\GitPullTool;
use CoquiBot\Toolkits\Git\Tool\GitPushTool;
use CoquiBot\Toolkits\Git\Tool\GitRemoteTool;
use CoquiBot\Toolkits\Git\Tool\GitStageTool;
use CoquiBot\Toolkits\Git\Tool\GitStatusTool;
use CoquiBot\Toolkits\Git\Tool\GitTagTool;
use CoquiBot\Toolkits\Git\Tool\GitVelocityTrendTool;

/**
 * Git repository management toolkit for Coqui.
 *
 * Provides full Git CLI access — status, staging, commits, branches,
 * tags, remotes, push/pull, merge, diff, and log. All operations
 * accept an optional repo path parameter, defaulting to the current
 * working directory.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 */
final class GitToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly string $workspacePath = '',
    ) {}

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');

        return new self(
            workspacePath: is_string($workspacePath) && $workspacePath !== '' ? $workspacePath : '',
        );
    }

    public function tools(): array
    {
        $runner = new GitRunner(defaultRepoPath: $this->workspacePath);
        $analysis = new GitAnalysisService($runner);

        return [
            (new GitInitTool($runner))->build(),
            (new GitStatusTool($runner))->build(),
            (new GitStageTool($runner))->build(),
            (new GitCommitTool($runner))->build(),
            (new GitDiffTool($runner))->build(),
            (new GitLogTool($runner))->build(),
            (new GitBranchTool($runner))->build(),
            (new GitCheckoutTool($runner))->build(),
            (new GitTagTool($runner))->build(),
            (new GitRemoteTool($runner))->build(),
            (new GitPushTool($runner))->build(),
            (new GitPullTool($runner))->build(),
            (new GitMergeTool($runner))->build(),
            (new GitChurnHotspotsTool($analysis))->build(),
            (new GitContributorRankingTool($analysis))->build(),
            (new GitBugHotspotsTool($analysis))->build(),
            (new GitVelocityTrendTool($analysis))->build(),
            (new GitCrisisDetectionTool($analysis))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <GIT-TOOLKIT-GUIDELINES>
            ## Tool Selection

            | Task | Tool | Notes |
            |------|------|-------|
            | Check repo state | `git_status` | Always check status before committing |
            | Stage files | `git_stage` | action: add, add_all, reset |
            | Create commit | `git_commit` | Requires staged changes (or use all=true) |
            | View changes | `git_diff` | scope: working, staged, commits |
            | View history | `git_log` | Filter by author, date, file, message |
            | Manage branches | `git_branch` | action: list, create, delete, rename, current |
            | Switch branch | `git_checkout` | Also restores files from commits |
            | Manage tags | `git_tag` | action: list, create, delete |
            | Manage remotes | `git_remote` | action: list, add, remove, show |
            | Push to remote | `git_push` | Sends local commits upstream |
            | Pull from remote | `git_pull` | Fetches and integrates remote changes |
            | Merge branches | `git_merge` | Merge or abort in-progress merge |
            | Find churn hotspots | `git_churn_hotspots` | Rank files by change frequency |
            | Rank contributors | `git_contributor_ranking` | Detect bus-factor and maintainer drift |
            | Find bug hotspots | `git_bug_hotspots` | Rank files that appear in fix-heavy commits |
            | Track repo velocity | `git_velocity_trend` | Show whether activity is steady or declining |
            | Detect firefighting | `git_crisis_detection` | Find revert, hotfix, rollback, and emergency patterns |

            ## Destructive Operation Confirmation

            The following operations require user confirmation before executing
            (unless `--auto-approve` is enabled):

            | Operation | Trigger | Risk |
            |-----------|---------|------|
            | `git_push` | All invocations | Publishes commits to remote; force push rewrites history |
            | `git_pull` | All invocations | Modifies local branch with remote changes |
            | `git_merge` | All invocations | Modifies branch history; may cause conflicts |
            | `git_branch` | `action: "delete"` | Deletes a branch (force delete loses unmerged work) |
            | `git_tag` | `action: "delete"` | Removes a tag reference |
            | `git_remote` | `action: "remove"` | Removes remote configuration |
            | `git_commit` | `amend: true` | Rewrites the last commit (changes history) |
            | `git_checkout` | `files` parameter set | Overwrites working tree files (uncommitted changes lost) |

            When confirmation is triggered, the user sees the tool name and arguments
            and must approve. If denied, the tool returns an error — inform the user
            and ask if they'd like to proceed differently.

            ## Bot Identity

            When `GIT_BOT_NAME` and/or `GIT_BOT_EMAIL` credentials are configured,
            all git commands automatically use them as the author/committer identity.
            This enables a separate bot account for commits without modifying the
            repo's git config.

            To configure: `credentials(action: "set", key: "GIT_BOT_NAME", value: "Coqui Bot")`

            ## Workflow Patterns

            ### Triage A New Repository Before Reading Code
            1. `git_churn_hotspots(period: "1-year")` — see what changes the most
            2. `git_bug_hotspots(period: "1-year")` — find files that keep getting patched
            3. `git_contributor_ranking(period: "all-time")` — identify bus-factor and maintainer drift
            4. `git_velocity_trend(period: "all-time", granularity: "month")` — check whether the project is accelerating or stalling
            5. `git_crisis_detection(period: "1-year")` — look for revert and hotfix pressure

            ### Feature Branch Flow
            1. `git_branch(action: "create", name: "feature/my-change")`
            2. `git_checkout(target: "feature/my-change")`
            3. Make changes...
            4. `git_stage(action: "add_all")`
            5. `git_commit(message: "feat: implement my change")`
            6. `git_push(branch: "feature/my-change", set_upstream: true)`

            ### Quick Commit Flow
            1. `git_status()` — check what changed
            2. `git_diff(scope: "working")` — review changes
            3. `git_stage(action: "add", files: "src/Foo.php src/Bar.php")`
            4. `git_commit(message: "fix: resolve null handling in Foo")`

            ### Review Changes
            1. `git_log(count: 5, oneline: true)` — recent history
            2. `git_diff(scope: "commits", ref1: "HEAD~3")` — last 3 commits' changes
            3. `git_diff(scope: "commits", ref1: "main", ref2: "feature/x")` — branch comparison

            ## Best Practices
            - Before reading unfamiliar code, use the analysis tools to identify churn, defects, ownership concentration, and release instability
            - Always check `git_status` before staging or committing
            - Use `git_diff(scope: "staged")` to review exactly what will be committed
            - Write clear, conventional commit messages (feat:, fix:, docs:, refactor:, test:, chore:)
            - Every tool accepts an optional `path` parameter to operate on any repo
            - Use `git_branch(action: "current")` to verify the active branch
            - Use `git_push(set_upstream: true)` on first push of a new branch
            - Prefer `git_push(force: false)` unless the user explicitly asks for force push
            </GIT-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
