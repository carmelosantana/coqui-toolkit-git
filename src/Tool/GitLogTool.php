<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * View commit history.
 */
final readonly class GitLogTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_log',
            description: 'View commit history with optional filters for author, date range, and file.',
            parameters: [
                new NumberParameter(
                    'count',
                    'Maximum number of commits to show (default: 10).',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 100,
                ),
                new BoolParameter(
                    'oneline',
                    'Compact one-line-per-commit format.',
                    required: false,
                ),
                new StringParameter(
                    'author',
                    'Filter by author name or email.',
                    required: false,
                ),
                new StringParameter(
                    'since',
                    'Show commits after this date (e.g. "2025-01-01", "2 weeks ago").',
                    required: false,
                ),
                new StringParameter(
                    'until',
                    'Show commits before this date.',
                    required: false,
                ),
                new StringParameter(
                    'grep',
                    'Filter commits whose message contains this string.',
                    required: false,
                ),
                new StringParameter(
                    'file',
                    'Show only commits that touch this file path.',
                    required: false,
                ),
                new StringParameter(
                    'ref',
                    'Branch or commit reference to start from.',
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
        $count = (int) ($args['count'] ?? 10);
        $oneline = (bool) ($args['oneline'] ?? false);
        $author = trim((string) ($args['author'] ?? ''));
        $since = trim((string) ($args['since'] ?? ''));
        $until = trim((string) ($args['until'] ?? ''));
        $grep = trim((string) ($args['grep'] ?? ''));
        $file = trim((string) ($args['file'] ?? ''));
        $ref = trim((string) ($args['ref'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));

        $gitArgs = ['-' . max(1, min($count, 100))];

        if ($oneline) {
            $gitArgs[] = '--oneline';
        } else {
            $gitArgs[] = '--format=medium';
        }

        if ($author !== '') {
            $gitArgs[] = '--author=' . $author;
        }

        if ($since !== '') {
            $gitArgs[] = '--since=' . $since;
        }

        if ($until !== '') {
            $gitArgs[] = '--until=' . $until;
        }

        if ($grep !== '') {
            $gitArgs[] = '--grep=' . $grep;
        }

        if ($ref !== '') {
            $gitArgs[] = $ref;
        }

        if ($file !== '') {
            $gitArgs[] = '--';
            $gitArgs[] = $file;
        }

        $result = $this->runner->run('log', $gitArgs, $path);
        $output = $result->output();

        if ($result->succeeded() && $output === '') {
            return ToolResult::success('No commits found matching the criteria.');
        }

        return $result->toToolResult();
    }
}
