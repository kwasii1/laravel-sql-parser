<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('LaravelSqlParser\LaravelSqlParser')
    ->toUseStrictTypes();

arch('the parser core does not depend on Laravel')
    ->expect([
        'LaravelSqlParser\LaravelSqlParser\Dialects',
        'LaravelSqlParser\LaravelSqlParser\Errors',
        'LaravelSqlParser\LaravelSqlParser\Expressions',
        'LaravelSqlParser\LaravelSqlParser\Generator',
        'LaravelSqlParser\LaravelSqlParser\Optimizer',
        'LaravelSqlParser\LaravelSqlParser\Parser',
        'LaravelSqlParser\LaravelSqlParser\Support',
        'LaravelSqlParser\LaravelSqlParser\Tokenizer',
    ])
    ->not->toUse('Illuminate');

arch('expression nodes extend Expression')
    ->expect('LaravelSqlParser\LaravelSqlParser\Expressions')
    ->classes()
    ->toExtend('LaravelSqlParser\LaravelSqlParser\Expressions\Expression')
    ->ignoring([
        'LaravelSqlParser\LaravelSqlParser\Expressions\Expression',
        'LaravelSqlParser\LaravelSqlParser\Expressions\Builder',
        'LaravelSqlParser\LaravelSqlParser\Expressions\DType',
        'LaravelSqlParser\LaravelSqlParser\Expressions\ExpressionRegistry',
    ]);
