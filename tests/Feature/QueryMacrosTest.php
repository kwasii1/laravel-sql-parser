<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

it('reorders bindings to match the transpiled query', function (): void {
    $query = DB::table('users')
        ->selectRaw('IF(active = ?, ?, ?) AS state', [1, 'on', 'off'])
        ->where('name', 'like', 'T%');

    expect($query->toSqlFor('postgres'))
        ->toBe('SELECT CASE WHEN active = ? THEN ? ELSE ? END AS state FROM "users" WHERE "name" LIKE ?')
        ->and($query->bindingsFor('postgres'))->toBe([1, 'on', 'off', 'T%']);
});

it('reads the query in its connection dialect', function (): void {
    expect(DB::table('users')->select('id')->toSqlFor('mysql'))->toBe('SELECT `id` FROM `users`');
});

it('formats the transpiled query when asked', function (): void {
    expect(DB::table('users')->select('id', 'name')->toSqlFor('mysql', pretty: true))
        ->toBe("SELECT\n  `id`,\n  `name`\nFROM `users`");
});

it('substitutes bindings into raw SQL for another dialect', function (): void {
    expect(DB::table('users')->where('id', 7)->toRawSqlFor('mysql'))
        ->toBe('SELECT * FROM `users` WHERE `id` = 7');
});

it('works on Eloquent builders', function (): void {
    $query = User::query()->where('email', 'a@example.com');

    expect($query->toSqlFor('tsql'))->toBe('SELECT * FROM [users] WHERE [email] = ?')
        ->and($query->bindingsFor('tsql'))->toBe(['a@example.com'])
        ->and($query->toRawSqlFor('mysql'))->toBe("SELECT * FROM `users` WHERE `email` = 'a@example.com'");
});

it('registers the macros on both builders', function (): void {
    expect(Builder::hasMacro('toSqlFor'))->toBeTrue()
        ->and(Illuminate\Database\Eloquent\Builder::hasGlobalMacro('toSqlFor'))->toBeTrue();
});

it('matches the README example', function (): void {
    $query = User::where('email', 'like', '%@example.com')->orderBy('id');

    expect($query->toSqlFor('sqlsrv'))->toBe('SELECT * FROM [users] WHERE [email] LIKE ? ORDER BY [id] ASC')
        ->and($query->bindingsFor('sqlsrv'))->toBe(['%@example.com'])
        ->and($query->toRawSqlFor('mysql'))->toBe("SELECT * FROM `users` WHERE `email` LIKE '%@example.com' ORDER BY `id` ASC");
});
