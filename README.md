# Coqui Git Toolkit

Git repository management toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Provides full Git CLI access — status, staging, commits, branches, tags, remotes, push/pull, merge, diff, and log — all sandboxed through typed tools with proper argument escaping.

## Requirements

- PHP 8.4+
- Git CLI installed and on PATH
- [Coqui](https://github.com/AgentCoqui/coqui) (auto-discovered via Composer)

## Installation

```bash
composer require coquibot/coqui-toolkit-git
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

## Tools Provided

### `git_init`

Initialize a new Git repository.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | No | Directory to initialize (defaults to cwd) |
| `branch` | string | No | Name for the initial branch |

### `git_status`

Show the working tree status — modified, staged, and untracked files.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | No | Repository path |
| `short` | bool | No | Use short format output |

### `git_stage`

Stage, unstage, or reset files in the Git index.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `add`, `add_all`, or `reset` |
| `files` | string | No | Space-separated file paths (required for `add` and `reset`) |
| `path` | string | No | Repository path |

### `git_commit`

Create a new commit with a message.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `message` | string | Yes | Commit message |
| `all` | bool | No | Auto-stage all modified tracked files (-a) |
| `amend` | bool | No | Amend the previous commit |
| `path` | string | No | Repository path |

### `git_diff`

Show differences between working tree, staged changes, or between commits.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scope` | enum | Yes | `working`, `staged`, or `commits` |
| `ref1` | string | No | First commit/branch ref (required for `commits`) |
| `ref2` | string | No | Second ref (defaults to HEAD) |
| `file` | string | No | Limit diff to a specific file |
| `stat_only` | bool | No | Show only diffstat summary |
| `path` | string | No | Repository path |

### `git_log`

View commit history with optional filters.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `count` | int | No | Max commits to show (default: 10, max: 100) |
| `oneline` | bool | No | Compact one-line format |
| `author` | string | No | Filter by author |
| `since` | string | No | Show commits after this date |
| `until` | string | No | Show commits before this date |
| `grep` | string | No | Filter by commit message |
| `file` | string | No | Show only commits touching this file |
| `ref` | string | No | Branch or commit to start from |
| `path` | string | No | Repository path |

### `git_branch`

Manage Git branches — list, create, delete, rename.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `list`, `create`, `delete`, `rename`, or `current` |
| `name` | string | No | Branch name (required for create/delete/rename) |
| `new_name` | string | No | New name when renaming |
| `from` | string | No | Start point for new branch |
| `force` | bool | No | Force delete (-D) |
| `all` | bool | No | List remote-tracking branches too |
| `path` | string | No | Repository path |

### `git_checkout`

Switch branches, create+switch, or restore files.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `target` | string | Yes | Branch, tag, or commit hash |
| `create` | bool | No | Create and switch (-b) |
| `files` | string | No | Space-separated files to restore |
| `path` | string | No | Repository path |

### `git_tag`

Manage Git tags — list, create, delete.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `list`, `create`, or `delete` |
| `name` | string | No | Tag name (required for create/delete) |
| `message` | string | No | Annotation message (creates annotated tag) |
| `ref` | string | No | Commit to tag (defaults to HEAD) |
| `pattern` | string | No | Glob filter for listing (e.g. "v1.*") |
| `path` | string | No | Repository path |

### `git_remote`

Manage Git remotes — list, add, remove, show.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `list`, `add`, `remove`, or `show` |
| `name` | string | No | Remote name (required for add/remove/show) |
| `url` | string | No | Remote URL (required for add) |
| `path` | string | No | Repository path |

### `git_push`

Push local commits to a remote repository.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `remote` | string | No | Remote name (default: "origin") |
| `branch` | string | No | Branch to push (defaults to current) |
| `force` | bool | No | Force push |
| `tags` | bool | No | Push all tags |
| `set_upstream` | bool | No | Set upstream tracking (-u) |
| `path` | string | No | Repository path |

### `git_pull`

Fetch and integrate changes from a remote repository.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `remote` | string | No | Remote name (default: "origin") |
| `branch` | string | No | Remote branch to pull |
| `rebase` | bool | No | Rebase instead of merge |
| `path` | string | No | Repository path |

### `git_merge`

Merge a branch into the current branch.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `branch` | string | No | Branch to merge (required unless aborting) |
| `no_ff` | bool | No | Create merge commit even if fast-forward possible |
| `message` | string | No | Custom merge commit message |
| `abort` | bool | No | Abort an in-progress merge |
| `path` | string | No | Repository path |

## Usage Examples

### Feature Branch Workflow

```
git_branch(action: "create", name: "feature/new-api")
git_checkout(target: "feature/new-api")
# ... make changes ...
git_stage(action: "add_all")
git_commit(message: "feat: implement new API endpoint")
git_push(branch: "feature/new-api", set_upstream: true)
```

### Quick Commit

```
git_status(short: true)
git_diff(scope: "working")
git_stage(action: "add", files: "src/Handler.php src/Router.php")
git_commit(message: "fix: handle null response in router")
```

### Reviewing History

```
git_log(count: 10, oneline: true)
git_diff(scope: "commits", ref1: "HEAD~5")
git_diff(scope: "commits", ref1: "main", ref2: "feature/x", stat_only: true)
```

### Tagging a Release

```
git_tag(action: "create", name: "v1.2.0", message: "Release 1.2.0")
git_push(tags: true)
```

## Architecture

```
src/
├── GitToolkit.php              # ToolkitInterface — registers all tools + guidelines
├── Runtime/
│   ├── GitRunner.php           # proc_open wrapper with timeout + output truncation
│   └── GitResult.php           # Typed result VO (exitCode, stdout, stderr)
└── Tool/
    ├── GitInitTool.php
    ├── GitStatusTool.php
    ├── GitStageTool.php
    ├── GitCommitTool.php
    ├── GitDiffTool.php
    ├── GitLogTool.php
    ├── GitBranchTool.php
    ├── GitCheckoutTool.php
    ├── GitTagTool.php
    ├── GitRemoteTool.php
    ├── GitPushTool.php
    ├── GitPullTool.php
    └── GitMergeTool.php
```

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Static analysis
composer analyse
```

## License

MIT
