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
 * Manage Git tags — list, create, delete.
 */
final readonly class GitTagTool
{
    public function __construct(
        private GitRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'git_tag',
            description: 'Manage Git tags — list existing tags, create annotated or lightweight tags, or delete tags.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Tag operation to perform.',
                    values: ['list', 'create', 'delete'],
                    required: true,
                ),
                new StringParameter(
                    'name',
                    'Tag name (required for create and delete).',
                    required: false,
                ),
                new StringParameter(
                    'message',
                    'Annotation message. Creates an annotated tag when provided, lightweight otherwise.',
                    required: false,
                ),
                new StringParameter(
                    'ref',
                    'Commit hash or branch to tag. Defaults to HEAD.',
                    required: false,
                ),
                new StringParameter(
                    'pattern',
                    'Glob pattern to filter listed tags (e.g. "v1.*").',
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
        $message = trim((string) ($args['message'] ?? ''));
        $ref = trim((string) ($args['ref'] ?? ''));
        $pattern = trim((string) ($args['pattern'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));

        return match ($action) {
            'list' => $this->listTags($pattern, $path),
            'create' => $this->createTag($name, $message, $ref, $path),
            'delete' => $this->deleteTag($name, $path),
            default => ToolResult::error("Unknown action: {$action}."),
        };
    }

    private function listTags(string $pattern, string $repoPath): ToolResult
    {
        $gitArgs = ['--list', '--sort=-v:refname'];

        if ($pattern !== '') {
            $gitArgs[] = $pattern;
        }

        $result = $this->runner->run('tag', $gitArgs, $repoPath);
        $output = $result->output();

        if ($result->succeeded() && $output === '') {
            return ToolResult::success('No tags found.');
        }

        return $result->toToolResult();
    }

    private function createTag(string $name, string $message, string $ref, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to create a tag.');
        }

        $gitArgs = [];

        if ($message !== '') {
            $gitArgs[] = '-a';
            $gitArgs[] = $name;
            $gitArgs[] = '-m';
            $gitArgs[] = $message;
        } else {
            $gitArgs[] = $name;
        }

        if ($ref !== '') {
            $gitArgs[] = $ref;
        }

        return $this->runner->run('tag', $gitArgs, $repoPath)->toToolResult();
    }

    private function deleteTag(string $name, string $repoPath): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to delete a tag.');
        }

        return $this->runner->run('tag', ['-d', $name], $repoPath)->toToolResult();
    }
}
