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
 * Merge branches together.
 */
final readonly class GitMergeTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_merge',
            description: 'Merge a branch into the current branch, or abort an in-progress merge.',
            parameters: [
                new StringParameter(
                    'branch',
                    'Branch to merge into the current branch. Not required when aborting.',
                    required: false,
                ),
                new BoolParameter(
                    'no_ff',
                    'Create a merge commit even if fast-forward is possible (--no-ff).',
                    required: false,
                ),
                new StringParameter(
                    'message',
                    'Custom merge commit message.',
                    required: false,
                ),
                new BoolParameter(
                    'abort',
                    'Abort the current merge in progress.',
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
        $branch = trim((string) ($args['branch'] ?? ''));
        $noFf = (bool) ($args['no_ff'] ?? false);
        $message = trim((string) ($args['message'] ?? ''));
        $abort = (bool) ($args['abort'] ?? false);
        $path = trim((string) ($args['path'] ?? ''));

        if ($abort) {
            return $this->runner->run('merge', ['--abort'], $path)->toToolResult();
        }

        if ($branch === '') {
            return ToolResult::error('The "branch" parameter is required (or use "abort" to cancel an in-progress merge).');
        }

        $gitArgs = [];

        if ($noFf) {
            $gitArgs[] = '--no-ff';
        }

        if ($message !== '') {
            $gitArgs[] = '-m';
            $gitArgs[] = $message;
        }

        $gitArgs[] = $branch;

        return $this->runner->run('merge', $gitArgs, $path)->toToolResult();
    }
}
