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
 * Show diffs between working tree, index, and commits.
 */
final readonly class GitDiffTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_diff',
            description: 'Show differences between working tree, staged changes, or between commits.',
            parameters: [
                new EnumParameter(
                    'scope',
                    'What to diff.',
                    values: ['working', 'staged', 'commits'],
                    required: true,
                ),
                new StringParameter(
                    'ref1',
                    'First commit/branch reference (required for "commits" scope).',
                    required: false,
                ),
                new StringParameter(
                    'ref2',
                    'Second commit/branch reference. Defaults to HEAD when ref1 is given.',
                    required: false,
                ),
                new StringParameter(
                    'file',
                    'Limit diff to a specific file path.',
                    required: false,
                ),
                new BoolParameter(
                    'stat_only',
                    'Show only a diffstat summary (files changed, insertions, deletions).',
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
        $scope = trim((string) ($args['scope'] ?? 'working'));
        $ref1 = trim((string) ($args['ref1'] ?? ''));
        $ref2 = trim((string) ($args['ref2'] ?? ''));
        $file = trim((string) ($args['file'] ?? ''));
        $statOnly = (bool) ($args['stat_only'] ?? false);
        $path = trim((string) ($args['path'] ?? ''));

        $gitArgs = [];

        if ($statOnly) {
            $gitArgs[] = '--stat';
        }

        match ($scope) {
            'staged' => $gitArgs[] = '--cached',
            'commits' => $this->appendCommitRefs($gitArgs, $ref1, $ref2),
            default => null, // 'working' — no extra args
        };

        if ($scope === 'commits' && $ref1 === '') {
            return ToolResult::error('The "ref1" parameter is required for "commits" scope.');
        }

        if ($file !== '') {
            $gitArgs[] = '--';
            $gitArgs[] = $file;
        }

        $result = $this->runner->run('diff', $gitArgs, $path);
        $output = $result->output();

        if ($result->succeeded() && $output === '') {
            return ToolResult::success('No differences found.');
        }

        return $result->toToolResult();
    }

    /**
     * @param list<string> $gitArgs
     */
    private function appendCommitRefs(array &$gitArgs, string $ref1, string $ref2): void
    {
        if ($ref1 !== '') {
            if ($ref2 !== '') {
                $gitArgs[] = $ref1 . '..' . $ref2;
            } else {
                $gitArgs[] = $ref1;
            }
        }
    }
}
