<?php

declare(strict_types=1);

it('preloads the parser core without warnings', function (): void {
    $command = implode(' ', [
        escapeshellarg(PHP_BINARY),
        '-d opcache.enable_cli=1',
        '-d '.escapeshellarg('opcache.preload='.dirname(__DIR__, 2).'/preload.php'),
        '-d display_errors=stderr',
        '-r '.escapeshellarg('echo count(opcache_get_status(false)["preload_statistics"]["classes"] ?? []);'),
        '2>&1',
    ]);

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and($output)->toHaveCount(1)
        ->and((int) $output[0])->toBeGreaterThan(1000);
})->skip(PHP_OS_FAMILY === 'Windows', 'PHP does not support preloading on Windows')
    ->skip(! extension_loaded('Zend OPcache'), 'OPcache is not loaded');
