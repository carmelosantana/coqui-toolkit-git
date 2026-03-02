<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Git\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Git\Runtime\GitRunner;

/**
 * Initialize a new Git repository.
 */
final readonly class GitInitTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_init',
            description: 'Initialize a new Git repository in the specified directory.',
            parameters: [
                new StringParameter(
                    'path',
                    'Directory to initialize. Defaults to the current working directory.',
                    required: false,
                ),
                new StringParameter(
                    'branch',
                    'Name for the initial branch (e.g. "main"). Uses git default if omitted.',
                    required: false,
                ),
            ],
            callback: fn(array $args) => $this->execute($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(array $args): \CarmeloSantana\PHPAgents\Tool\ToolResult
    {
        $path = trim((string) ($args['path'] ?? ''));
        $branch = trim((string) ($args['branch'] ?? ''));

        $gitArgs = [];
        if ($branch !== '') {
            $gitArgs[] = '--initial-branch=' . $branch;
        }

        return $this->runner->run('init', $gitArgs, $path)->toToolResult();
    }
}
