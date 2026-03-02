<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * Manage Git branches — list, create, delete, rename.
 */
final readonly class GitBranchTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_branch',
            description: 'Manage Git branches — list, create, delete, or rename branches.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Branch operation to perform.',
                    values: ['list', 'create', 'delete', 'rename', 'current'],
                    required: true,
                ),
                new StringParameter(
                    'name',
                    'Branch name (required for create, delete, rename).',
                    required: false,
                ),
                new StringParameter(
                    'new_name',
                    'New name when renaming a branch.',
                    required: false,
                ),
                new StringParameter(
                    'from',
                    'Start point for new branch (commit hash, tag, or branch name). Defaults to HEAD.',
                    required: false,
                ),
                new BoolParameter(
                    'force',
                    'Force delete (-D) even if the branch is not fully merged.',
                    required: false,
                ),
                new BoolParameter(
                    'all',
                    'List both local and remote-tracking branches.',
                    required: false,
                ),
                new StringParameter(
                    'path',
                    'Repository path. Defaults to the current working directory.',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->execute($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(array $args): ToolResult
    {
        $action = trim((string) ($args['action'] ?? ''));
        $name = trim((string) ($args['name'] ?? ''));
        $newName = trim((string) ($args['new_name'] ?? ''));
        $from = trim((string) ($args['from'] ?? ''));
        $force = (bool) ($args['force'] ?? false);
        $all = (bool) ($args['all'] ?? false);
        $path = trim((string) ($args['path'] ?? ''));

        return match ($action) {
            'list' => $this->listBranches($all, $path),
            'create' => $this->createBranch($name, $from, $path),
            'delete' => $this->deleteBranch($name, $force, $path),
            'rename' => $this->renameBranch($name, $newName, $path),
            'current' => $this->currentBranch($path),
            default => ToolResult::error("Unknown action: {$action}."),
        };
    }

    private function listBranches(bool $all, string $repoPath): ToolResult
    {
        $gitArgs = [];
        if ($all) {
            $gitArgs[] = '--all';
        }
        $gitArgs[] = '--verbose';

        return $this->runner->run('branch', $gitArgs, $repoPath)->toToolResult();
    }

    private function createBranch(string $name, string $from, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to create a branch.');
        }

        $gitArgs = [$name];
        if ($from !== '') {
            $gitArgs[] = $from;
        }

        return $this->runner->run('branch', $gitArgs, $repoPath)->toToolResult();
    }

    private function deleteBranch(string $name, bool $force, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to delete a branch.');
        }

        $flag = $force ? '-D' : '-d';

        return $this->runner->run('branch', [$flag, $name], $repoPath)->toToolResult();
    }

    private function renameBranch(string $name, string $newName, string $repoPath): ToolResult
    {
        if ($name === '' || $newName === '') {
            return ToolResult::error('Both "name" and "new_name" parameters are required to rename a branch.');
        }

        return $this->runner->run('branch', ['-m', $name, $newName], $repoPath)->toToolResult();
    }

    private function currentBranch(string $repoPath): ToolResult
    {
        return $this->runner->run('branch', ['--show-current'], $repoPath)->toToolResult();
    }
}
