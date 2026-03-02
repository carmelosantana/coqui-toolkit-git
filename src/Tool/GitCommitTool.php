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
 * Create a new commit with staged changes.
 */
final readonly class GitCommitTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_commit',
            description: 'Create a new commit with a message. Stages all tracked files when "all" is true.',
            parameters: [
                new StringParameter(
                    'message',
                    'Commit message.',
                    required: true,
                ),
                new BoolParameter(
                    'all',
                    'Automatically stage all modified and deleted tracked files before committing (-a flag).',
                    required: false,
                ),
                new BoolParameter(
                    'amend',
                    'Amend the previous commit instead of creating a new one.',
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
        $message = trim((string) ($args['message'] ?? ''));
        $all = (bool) ($args['all'] ?? false);
        $amend = (bool) ($args['amend'] ?? false);
        $path = trim((string) ($args['path'] ?? ''));

        if ($message === '' && !$amend) {
            return ToolResult::error('A commit message is required (unless amending with --no-edit).');
        }

        $gitArgs = [];

        if ($all) {
            $gitArgs[] = '--all';
        }

        if ($amend) {
            $gitArgs[] = '--amend';
        }

        if ($message !== '') {
            $gitArgs[] = '--message';
            $gitArgs[] = $message;
        } else {
            // Amend without a new message
            $gitArgs[] = '--no-edit';
        }

        return $this->runner->run('commit', $gitArgs, $path)->toToolResult();
    }
}
