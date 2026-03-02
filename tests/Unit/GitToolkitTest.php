<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\Git\GitToolkit;

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new GitToolkit();

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('tools returns all 13 tools', function () {
    $toolkit = new GitToolkit();

    expect($toolkit->tools())->toHaveCount(13);
});

test('each tool implements ToolInterface', function () {
    $toolkit = new GitToolkit();

    foreach ($toolkit->tools() as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});

test('tool names are unique', function () {
    $toolkit = new GitToolkit();
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toHaveCount(count(array_unique($names)));
});

test('each tool produces a valid function schema', function () {
    $toolkit = new GitToolkit();

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)
            ->toBeArray()
            ->toHaveKeys(['type', 'function']);

        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toBeArray()->toHaveKeys(['name', 'description', 'parameters']);
        expect($schema['function']['name'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['description'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['parameters'])->toBeArray();
    }
});

test('all tool names start with git_', function () {
    $toolkit = new GitToolkit();

    foreach ($toolkit->tools() as $tool) {
        expect($tool->name())->toStartWith('git_');
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $toolkit = new GitToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)
        ->toBeString()
        ->not->toBeEmpty()
        ->toContain('<GIT-TOOLKIT-GUIDELINES>')
        ->toContain('</GIT-TOOLKIT-GUIDELINES>');
});

test('fromEnv creates instance', function () {
    $toolkit = GitToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(GitToolkit::class);
    expect($toolkit->tools())->toHaveCount(13);
});

test('expected tool names are present', function () {
    $toolkit = new GitToolkit();
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    $expected = [
        'git_init',
        'git_status',
        'git_stage',
        'git_commit',
        'git_diff',
        'git_log',
        'git_branch',
        'git_checkout',
        'git_tag',
        'git_remote',
        'git_push',
        'git_pull',
        'git_merge',
    ];

    foreach ($expected as $name) {
        expect($names)->toContain($name);
    }
});
