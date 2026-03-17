<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Git\Runtime\GitRunner;

test('resolveBinary finds git', function () {
    $runner = new GitRunner();

    expect($runner->resolveBinary())->not->toBeEmpty();
});

test('isAvailable returns true when git is installed', function () {
    $runner = new GitRunner();

    expect($runner->isAvailable())->toBeTrue();
});

test('buildEnvironment returns null when no bot vars set', function () {
    // Ensure bot vars are not set
    putenv('GIT_BOT_NAME');
    putenv('GIT_BOT_EMAIL');

    $runner = new GitRunner();
    $reflection = new ReflectionMethod($runner, 'buildEnvironment');

    expect($reflection->invoke($runner))->toBeNull();
});

test('buildEnvironment maps bot name to author and committer', function () {
    putenv('GIT_BOT_NAME=CoquiBot');
    putenv('GIT_BOT_EMAIL');

    $runner = new GitRunner();
    $reflection = new ReflectionMethod($runner, 'buildEnvironment');

    $env = $reflection->invoke($runner);

    expect($env)->toBeArray()
        ->and($env['GIT_AUTHOR_NAME'])->toBe('CoquiBot')
        ->and($env['GIT_COMMITTER_NAME'])->toBe('CoquiBot')
        ->and($env)->not->toHaveKey('GIT_AUTHOR_EMAIL')
        ->and($env)->not->toHaveKey('GIT_COMMITTER_EMAIL');

    putenv('GIT_BOT_NAME');
});

test('buildEnvironment maps bot email to author and committer', function () {
    putenv('GIT_BOT_NAME');
    putenv('GIT_BOT_EMAIL=bot@example.com');

    $runner = new GitRunner();
    $reflection = new ReflectionMethod($runner, 'buildEnvironment');

    $env = $reflection->invoke($runner);

    expect($env)->toBeArray()
        ->and($env['GIT_AUTHOR_EMAIL'])->toBe('bot@example.com')
        ->and($env['GIT_COMMITTER_EMAIL'])->toBe('bot@example.com')
        ->and($env)->not->toHaveKey('GIT_AUTHOR_NAME')
        ->and($env)->not->toHaveKey('GIT_COMMITTER_NAME');

    putenv('GIT_BOT_EMAIL');
});

test('buildEnvironment maps both name and email', function () {
    putenv('GIT_BOT_NAME=CoquiBot');
    putenv('GIT_BOT_EMAIL=bot@example.com');

    $runner = new GitRunner();
    $reflection = new ReflectionMethod($runner, 'buildEnvironment');

    $env = $reflection->invoke($runner);

    expect($env)->toBeArray()
        ->and($env['GIT_AUTHOR_NAME'])->toBe('CoquiBot')
        ->and($env['GIT_COMMITTER_NAME'])->toBe('CoquiBot')
        ->and($env['GIT_AUTHOR_EMAIL'])->toBe('bot@example.com')
        ->and($env['GIT_COMMITTER_EMAIL'])->toBe('bot@example.com');

    putenv('GIT_BOT_NAME');
    putenv('GIT_BOT_EMAIL');
});

test('resolveEnv returns empty string for unset vars', function () {
    putenv('GIT_BOT_NAME');

    $runner = new GitRunner();
    $reflection = new ReflectionMethod($runner, 'resolveEnv');

    expect($reflection->invoke($runner, 'GIT_BOT_NAME'))->toBe('');
});

test('resolveEnv returns value for set vars', function () {
    putenv('GIT_BOT_NAME=TestBot');

    $runner = new GitRunner();
    $reflection = new ReflectionMethod($runner, 'resolveEnv');

    expect($reflection->invoke($runner, 'GIT_BOT_NAME'))->toBe('TestBot');

    putenv('GIT_BOT_NAME');
});
