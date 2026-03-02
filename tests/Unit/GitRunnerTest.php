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
