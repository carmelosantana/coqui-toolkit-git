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
 * Push commits to a remote repository.
 */
final readonly class GitPushTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_push',
            description: 'Push local commits to a remote repository.',
            parameters: [
                new StringParameter(
                    'remote',
                    'Remote name (default: "origin").',
                    required: false,
                ),
                new StringParameter(
                    'branch',
                    'Branch to push. Pushes the current branch if omitted.',
                    required: false,
                ),
                new BoolParameter(
                    'force',
                    'Force push (overwrites remote history). Use with caution.',
                    required: false,
                ),
                new BoolParameter(
                    'tags',
                    'Push all tags along with commits.',
                    required: false,
                ),
                new BoolParameter(
                    'set_upstream',
                    'Set the remote branch as upstream tracking reference (-u flag).',
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
        $force = (bool) ($args['force'] ?? false);
        $tags = (bool) ($args['tags'] ?? false);
        $setUpstream = (bool) ($args['set_upstream'] ?? false);
        $path = trim((string) ($args['path'] ?? ''));

        $gitArgs = [];

        if ($force) {
            $gitArgs[] = '--force';
        }

        if ($tags) {
            $gitArgs[] = '--tags';
        }

        if ($setUpstream) {
            $gitArgs[] = '--set-upstream';
        }

        $gitArgs[] = $remote;

        if ($branch !== '') {
            $gitArgs[] = $branch;
        }

        return $this->runner->run('push', $gitArgs, $path, timeout: 60)->toToolResult();
    }
}
