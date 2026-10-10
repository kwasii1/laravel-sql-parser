<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Kwasii\LaravelSqlParser')
    ->toUseStrictTypes();

arch('the parser core does not depend on Laravel')
    ->expect([
        'Kwasii\LaravelSqlParser\Dialects',
        'Kwasii\LaravelSqlParser\Errors',
        'Kwasii\LaravelSqlParser\Expressions',
        'Kwasii\LaravelSqlParser\Generator',
        'Kwasii\LaravelSqlParser\Optimizer',
        'Kwasii\LaravelSqlParser\Parser',
        'Kwasii\LaravelSqlParser\Support',
        'Kwasii\LaravelSqlParser\Tokenizer',
    ])
    ->not->toUse('Illuminate');

arch('expression nodes extend Expression')
    ->expect('Kwasii\LaravelSqlParser\Expressions')
    ->classes()
    ->toExtend('Kwasii\LaravelSqlParser\Expressions\Expression')
    ->ignoring([
        'Kwasii\LaravelSqlParser\Expressions\Expression',
        'Kwasii\LaravelSqlParser\Expressions\Builder',
        'Kwasii\LaravelSqlParser\Expressions\DType',
        'Kwasii\LaravelSqlParser\Expressions\ExpressionRegistry',
    ]);
