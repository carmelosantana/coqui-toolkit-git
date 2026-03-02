<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * Fetch and integrate changes from a remote repository.
 */
final readonly class GitPullTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_pull',
            description: 'Fetch and integrate changes from a remote repository.',
            parameters: [
                new StringParameter(
                    'remote',
                    'Remote name (default: "origin").',
                    required: false,
                ),
                new StringParameter(
                    'branch',
                    'Remote branch to pull. Uses the tracking branch if omitted.',
                    required: false,
                ),
                new BoolParameter(
                    'rebase',
                    'Rebase local commits on top of fetched changes instead of merging.',
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
        $remote = trim((string) ($args['remote'] ?? 'origin'));
        $branch = trim((string) ($args['branch'] ?? ''));
        $rebase = (bool) ($args['rebase'] ?? false);
        $path = trim((string) ($args['path'] ?? ''));

        $gitArgs = [];

        if ($rebase) {
            $gitArgs[] = '--rebase';
        }

        $gitArgs[] = $remote;

        if ($branch !== '') {
            $gitArgs[] = $branch;
        }

        return $this->runner->run('pull', $gitArgs, $path, timeout: 60)->toToolResult();
    }
}
