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
 * Switch branches or restore working tree files.
 */
final readonly class GitCheckoutTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_checkout',
            description: 'Switch branches, create and switch to a new branch, or restore specific files from a commit.',
            parameters: [
                new StringParameter(
                    'target',
                    'Branch name, tag, or commit hash to switch to.',
                    required: true,
                ),
                new BoolParameter(
                    'create',
                    'Create a new branch and switch to it (-b flag).',
                    required: false,
                ),
                new StringParameter(
                    'files',
                    'Space-separated file paths to restore from the target (checkout specific files instead of switching branches).',
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
        $target = trim((string) ($args['target'] ?? ''));
        $create = (bool) ($args['create'] ?? false);
        $files = trim((string) ($args['files'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));

        if ($target === '') {
            return ToolResult::error('The "target" parameter is required (branch, tag, or commit hash).');
        }

        $gitArgs = [];

        if ($create) {
            $gitArgs[] = '-b';
        }

        $gitArgs[] = $target;

        if ($files !== '') {
            /** @var list<string> $fileList */
            $fileList = preg_split('/\s+/', $files, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($fileList !== []) {
                $gitArgs[] = '--';
                array_push($gitArgs, ...$fileList);
            }
        }

        return $this->runner->run('checkout', $gitArgs, $path)->toToolResult();
    }
}
