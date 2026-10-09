<?php

declare(strict_types=1);

it('transpiles SQL given as an argument', function (): void {
    $this->artisan('sql:transpile', ['sql' => 'SELECT IFNULL(a, 1) FROM t LIMIT 5', '--from' => 'mysql', '--to' => 'tsql'])
        ->expectsOutput('SELECT TOP 5 COALESCE(a, 1) FROM t')
        ->assertSuccessful();
});

it('reads SQL from a file and separates statements', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'sql');
    file_put_contents($file, 'SELECT 1; SELECT IFNULL(a, 2)');

    try {
        $this->artisan('sql:transpile', ['--file' => $file, '--from' => 'mysql', '--to' => 'postgres'])
            ->expectsOutput("SELECT 1;\n\nSELECT COALESCE(a, 2);")
            ->assertSuccessful();
    } finally {
        unlink($file);
    }
});

it('pretty prints', function (): void {
    $this->artisan('sql:transpile', ['sql' => 'SELECT a, b FROM t', '--from' => 'mysql', '--pretty' => true])
        ->expectsOutput("SELECT\n  a,\n  b\nFROM t")
        ->assertSuccessful();
});

it('prints the expression tree', function (): void {
    $this->artisan('sql:transpile', ['sql' => 'SELECT 1', '--from' => 'mysql', '--ast' => true])
        ->expectsOutputToContain('"select"')
        ->assertSuccessful();
});

it('fails on invalid SQL', function (): void {
    $this->artisan('sql:transpile', ['sql' => 'SELECT (', '--from' => 'mysql'])
        ->assertFailed();
});

it('fails on an unknown dialect', function (): void {
    $this->artisan('sql:transpile', ['sql' => 'SELECT 1', '--from' => 'nope'])
        ->expectsOutputToContain("Unknown dialect 'nope'")
        ->assertFailed();
});

it('fails on an unreadable file', function (): void {
    $this->artisan('sql:transpile', ['--file' => '/does/not/exist.sql'])
        ->assertFailed();
});
