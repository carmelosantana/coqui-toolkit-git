<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * Stage, unstage, or reset files in the Git index.
 */
final readonly class GitStageTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_stage',
            description: 'Stage, unstage, or reset files in the Git index.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Operation to perform.',
                    values: ['add', 'add_all', 'reset'],
                    required: true,
                ),
                new StringParameter(
                    'files',
                    'Space-separated file paths or glob patterns. Required for "add" and "reset", ignored for "add_all".',
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
        $files = trim((string) ($args['files'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));

        return match ($action) {
            'add' => $this->add($files, $path),
            'add_all' => $this->addAll($path),
            'reset' => $this->reset($files, $path),
            default => ToolResult::error("Unknown action: {$action}. Use add, add_all, or reset."),
        };
    }

    private function add(string $files, string $repoPath): ToolResult
    {
        if ($files === '') {
            return ToolResult::error('The "files" parameter is required for "add" action.');
        }

        /** @var list<string> $fileList */
        $fileList = preg_split('/\s+/', $files, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($fileList === []) {
            return ToolResult::error('No valid file paths provided.');
        }

        return $this->runner->run('add', $fileList, $repoPath)->toToolResult();
    }

    private function addAll(string $repoPath): ToolResult
    {
        return $this->runner->run('add', ['--all'], $repoPath)->toToolResult();
    }

    private function reset(string $files, string $repoPath): ToolResult
    {
        if ($files === '') {
            return ToolResult::error('The "files" parameter is required for "reset" action.');
        }

        /** @var list<string> $fileList */
        $fileList = preg_split('/\s+/', $files, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($fileList === []) {
            return ToolResult::error('No valid file paths provided.');
        }

        $gitArgs = array_merge(['HEAD', '--'], $fileList);

        return $this->runner->run('reset', $gitArgs, $repoPath)->toToolResult();
    }
}
