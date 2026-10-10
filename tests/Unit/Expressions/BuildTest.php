<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CollateProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\EngineProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\FileFormatProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\GT;
use LaravelSqlParser\LaravelSqlParser\Expressions\GTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntDiv;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mod;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionedByProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pow;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\When;

/*
 * Port of sqlglot's tests/test_build.py. Python operators map to named methods (x + 1 is
 * $x->add(1)); reflected operators (1 + x) build the node directly with Builder::binop().
 */

function parseOne(string $sql, string|array|null $into = null): Expression
{
    return Builder::maybeParse($sql, $into);
}

it('does not mutate operands', function (): void {
    $x = Builder::condition('x');
    $xPlusOne = $x->add(1);

    expect($x->parent)->toBeNull()
        ->and($xPlusOne->args['this'])->not->toBe($x);
});

/**
 * @return array<string, array{0: Closure(): Expression, 1: string, 2?: string}>
 */
function buildCases(): array
{
    $x = Builder::condition('x');
    $b = static fn (string $class, mixed $left, mixed $right): Expression => Builder::binop($class, $left, $right);

    $cases = [
        [fn () => $x->add(1), 'x + 1'],
        [fn () => $b(Add::class, 1, $x), '1 + x'],
        [fn () => $x->sub(1), 'x - 1'],
        [fn () => $b(Sub::class, 1, $x), '1 - x'],
        [fn () => $x->mul(1), 'x * 1'],
        [fn () => $b(Mul::class, 1, $x), '1 * x'],
        [fn () => $x->div(1), 'x / 1'],
        [fn () => $b(Div::class, 1, $x), '1 / x'],
        [fn () => $x->intDiv(1), 'CAST(x / 1 AS INT)'],
        [fn () => $b(IntDiv::class, 1, $x), 'CAST(1 / x AS INT)'],
        [fn () => $x->mod(1), 'x % 1'],
        [fn () => $b(Mod::class, 1, $x), '1 % x'],
        [fn () => $x->pow(1), 'POWER(x, 1)'],
        [fn () => $b(Pow::class, 1, $x), 'POWER(1, x)'],
        [fn () => $b(AndExpr::class, $x, 1), 'x AND 1'],
        [fn () => $b(AndExpr::class, 1, $x), '1 AND x'],
        [fn () => $b(OrExpr::class, $x, 1), 'x OR 1'],
        [fn () => $b(OrExpr::class, 1, $x), '1 OR x'],
        [fn () => $x->lt(1), 'x < 1'],
        [fn () => $x->gt(1), 'x > 1'],
        [fn () => $x->lte(1), 'x <= 1'],
        [fn () => $x->gte(1), 'x >= 1'],
        [fn () => $b(LT::class, $x, 1), 'x < 1'],
        [fn () => $b(LTE::class, $x, 1), 'x <= 1'],
        [fn () => $b(GT::class, $x, 1), 'x > 1'],
        [fn () => $b(GTE::class, $x, 1), 'x >= 1'],
        [fn () => $x->eq(1), 'x = 1'],
        [fn () => $x->neq(1), 'x <> 1'],
        [fn () => $x->is(new NullExpr), 'x IS NULL'],
        [fn () => $x->as('y'), 'x AS y'],
        [fn () => $x->isin(1, '2'), "x IN (1, '2')"],
        [fn () => $x->isin(query: 'select 1'), 'x IN (SELECT 1)'],
        [fn () => $x->isin(unnest: 'x'), 'x IN (SELECT UNNEST(x))'],
        [fn () => $x->isin(unnest: 'x'), 'x IN UNNEST(x)', 'bigquery'],
        [fn () => $x->isin(unnest: ['x', 'y']), 'x IN (SELECT UNNEST(x, y))'],
        [fn () => $x->between(1, 2), 'x BETWEEN 1 AND 2'],
        [fn () => $b(Add::class, 1, $x)->add(2)->add(3), '1 + x + 2 + 3'],
        [fn () => $b(Add::class, 1, $x->mul(2))->add(3), '1 + (x * 2) + 3'],
        [fn () => $x->mul(1)->mul(2)->add(3), '(x * 1 * 2) + 3'],
        [fn () => $b(Add::class, 1, $x->mul(2)->div(3)), '1 + ((x * 2) / 3)'],
        [fn () => $b(AndExpr::class, $x, 'y'), "x AND 'y'"],
        [fn () => $b(OrExpr::class, $x, 'y'), "x OR 'y'"],
        [fn () => $x->neg(), '-x'],
        [fn () => $x->not(), 'NOT x'],
        [fn () => $x->bracket(1), 'x[1]'],
        [fn () => $x->bracket(1, 2), 'x[1, 2]'],
        [fn () => $x->bracket('y')->add(1), "x['y'] + 1"],
        [fn () => $x->like('y'), "x LIKE 'y'"],
        [fn () => $x->ilike('y'), "x ILIKE 'y'"],
        [fn () => $x->rlike('y'), "REGEXP_LIKE(x, 'y')"],
        [fn () => Builder::case()->when('x = 1', 'x')->else('bar'), 'CASE WHEN x = 1 THEN x ELSE bar END'],
        [fn () => Builder::case('x')->when('1', 'x')->else('bar'), 'CASE x WHEN 1 THEN x ELSE bar END'],
        [fn () => Builder::func('COALESCE', ['x', '1']), 'COALESCE(x, 1)'],
        [fn () => Builder::column('x')->desc(), 'x DESC'],
        [fn () => Builder::column('x')->desc(nullsFirst: true), 'x DESC NULLS FIRST'],
        [fn () => Builder::select('x'), 'SELECT x'],
        [fn () => Builder::select('x', 'y'), 'SELECT x, y'],
        [fn () => Builder::select('x')->from('tbl'), 'SELECT x FROM tbl'],
        [fn () => Builder::select('x', 'y')->from('tbl'), 'SELECT x, y FROM tbl'],
        [fn () => Builder::select('x')->select('y')->from('tbl'), 'SELECT x, y FROM tbl'],
        [fn () => Builder::select('comment', 'begin'), 'SELECT comment, begin'],
        [fn () => Builder::select('x')->select('y', append: false)->from('tbl'), 'SELECT y FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->from('tbl2'), 'SELECT x FROM tbl2'],
        [fn () => Builder::select('SUM(x) AS y'), 'SELECT SUM(x) AS y'],
        [fn () => Builder::select('x')->from('tbl')->where('x > 0'), 'SELECT x FROM tbl WHERE x > 0'],
        [fn () => Builder::select('x')->from('tbl')->where('x < 4 OR x > 5'), 'SELECT x FROM tbl WHERE x < 4 OR x > 5'],
        [fn () => Builder::select('x')->from('tbl')->where('x > 0')->where('x < 9'), 'SELECT x FROM tbl WHERE x > 0 AND x < 9'],
        [fn () => Builder::select('x')->from('tbl')->where('x > 0', 'x < 9'), 'SELECT x FROM tbl WHERE x > 0 AND x < 9'],
        [fn () => Builder::select('x')->from('tbl')->where(null)->where(false, ''), 'SELECT x FROM tbl WHERE FALSE'],
        [fn () => Builder::select('x')->from('tbl')->where('x > 0')->where('x < 9', append: false), 'SELECT x FROM tbl WHERE x < 9'],
        [fn () => Builder::select('x')->from('tbl')->where('x > 0')->lock(), 'SELECT x FROM tbl WHERE x > 0 FOR UPDATE', 'mysql'],
        [fn () => Builder::select('x')->from('tbl')->where('x > 0')->lock(update: false), 'SELECT x FROM tbl WHERE x > 0 FOR SHARE', 'postgres'],
        [fn () => Builder::select('x')->from('tbl')->hint('repartition(100)'), 'SELECT /*+ REPARTITION(100) */ x FROM tbl', 'spark'],
        [fn () => Builder::select('x')->from('tbl')->hint('coalesce(3)', 'broadcast(x)'), 'SELECT /*+ COALESCE(3), BROADCAST(x) */ x FROM tbl', 'spark'],
        [fn () => Builder::select('x', 'y')->from('tbl')->groupBy('x'), 'SELECT x, y FROM tbl GROUP BY x'],
        [fn () => Builder::select('x', 'y')->from('tbl')->groupBy('x, y'), 'SELECT x, y FROM tbl GROUP BY x, y'],
        [fn () => Builder::select('x', 'y', 'z', 'a')->from('tbl')->groupBy('x, y', 'z')->groupBy('a'), 'SELECT x, y, z, a FROM tbl GROUP BY x, y, z, a'],
        [fn () => Builder::select(1)->from('tbl')->groupBy('x with cube'), 'SELECT 1 FROM tbl GROUP BY x WITH CUBE'],
        [fn () => Builder::select('x')->distinct('a', 'b')->from('tbl'), 'SELECT DISTINCT ON (a, b) x FROM tbl'],
        [fn () => Builder::select('x')->distinct(distinct: true)->from('tbl'), 'SELECT DISTINCT x FROM tbl'],
        [fn () => Builder::select('x')->distinct(distinct: false)->from('tbl'), 'SELECT x FROM tbl'],
        [fn () => Builder::select('x')->lateral('OUTER explode(y) tbl2 AS z')->from('tbl'), 'SELECT x FROM tbl LATERAL VIEW OUTER EXPLODE(y) tbl2 AS z'],
        [fn () => Builder::select('x')->from('tbl')->join('tbl2 ON tbl.y = tbl2.y'), 'SELECT x FROM tbl JOIN tbl2 ON tbl.y = tbl2.y'],
        [fn () => Builder::select('x')->from('tbl')->join('tbl2', on: 'tbl.y = tbl2.y'), 'SELECT x FROM tbl JOIN tbl2 ON tbl.y = tbl2.y'],
        [fn () => Builder::select('x')->from('tbl')->join('tbl2', on: ['tbl.y = tbl2.y', 'a = b']), 'SELECT x FROM tbl JOIN tbl2 ON tbl.y = tbl2.y AND a = b'],
        [fn () => Builder::select('x')->from('tbl')->join('tbl2', joinType: 'left outer'), 'SELECT x FROM tbl LEFT OUTER JOIN tbl2'],
        [fn () => Builder::select('x')->from('tbl')->join(new Table(['this' => 'tbl2']), joinType: 'left outer'), 'SELECT x FROM tbl LEFT OUTER JOIN tbl2'],
        [fn () => Builder::select('x')->from('tbl')->join(new Table(['this' => 'tbl2']), joinType: 'left outer', joinAlias: 'foo'), 'SELECT x FROM tbl LEFT OUTER JOIN tbl2 AS foo'],
        [fn () => Builder::select('x')->from('tbl')->join(Builder::select('y')->from('tbl2'), joinType: 'left outer'), 'SELECT x FROM tbl LEFT OUTER JOIN (SELECT y FROM tbl2)'],
        [fn () => Builder::select('x')->from('tbl')->join(Builder::select('y')->from('tbl2')->subquery('aliased'), joinType: 'left outer'), 'SELECT x FROM tbl LEFT OUTER JOIN (SELECT y FROM tbl2) AS aliased'],
        [fn () => Builder::select('x')->from('tbl')->join(Builder::select('y')->from('tbl2'), joinType: 'left outer', joinAlias: 'aliased'), 'SELECT x FROM tbl LEFT OUTER JOIN (SELECT y FROM tbl2) AS aliased'],
        [fn () => Builder::select('x')->from('tbl')->join(parseOne('left join x', Join::class), on: 'a=b'), 'SELECT x FROM tbl LEFT JOIN x ON a = b'],
        [fn () => Builder::select('x')->from('tbl')->join('left join x', on: 'a=b'), 'SELECT x FROM tbl LEFT JOIN x ON a = b'],
        [fn () => Builder::select('x')->from('tbl')->join('select b from tbl2', on: 'a=b', joinType: 'left'), 'SELECT x FROM tbl LEFT JOIN (SELECT b FROM tbl2) ON a = b'],
        [fn () => Builder::select('x')->from('tbl')->join('select b from tbl2', on: 'a=b', joinType: 'left', joinAlias: 'aliased'), 'SELECT x FROM tbl LEFT JOIN (SELECT b FROM tbl2) AS aliased ON a = b'],
        [fn () => Builder::select('x', 'y', 'z')->from('merged_df')->join('vte_diagnosis_df', using: ['patient_id', 'encounter_id']), 'SELECT x, y, z FROM merged_df JOIN vte_diagnosis_df USING (patient_id, encounter_id)'],
        [fn () => Builder::select('x', 'y', 'z')->from('merged_df')->join('vte_diagnosis_df', using: [Builder::toIdentifier('patient_id'), Builder::toIdentifier('encounter_id')]), 'SELECT x, y, z FROM merged_df JOIN vte_diagnosis_df USING (patient_id, encounter_id)'],
        [fn () => parseOne('JOIN x', Join::class)->on('y = 1', 'z = 1'), 'JOIN x ON y = 1 AND z = 1'],
        [fn () => parseOne('JOIN x', Join::class)->on('y = 1'), 'JOIN x ON y = 1'],
        [fn () => parseOne('JOIN x', Join::class)->using('bar', 'bob'), 'JOIN x USING (bar, bob)'],
        [fn () => parseOne('JOIN x', Join::class)->using('bar'), 'JOIN x USING (bar)'],
        [fn () => Builder::select('x')->from('foo')->join('bla', using: 'bob'), 'SELECT x FROM foo JOIN bla USING (bob)'],
        [fn () => Builder::select('x', 'COUNT(y)')->from('tbl')->groupBy('x')->having('COUNT(y) > 0'), 'SELECT x, COUNT(y) FROM tbl GROUP BY x HAVING COUNT(y) > 0'],
        [fn () => Builder::select('x')->from('tbl')->orderBy('y'), 'SELECT x FROM tbl ORDER BY y'],
        [fn () => parseOne('select * from x union select * from y')->orderBy('y'), 'SELECT * FROM x UNION SELECT * FROM y ORDER BY y'],
        [fn () => Builder::select('x')->from('tbl')->clusterBy('y'), 'SELECT x FROM tbl CLUSTER BY y', 'hive'],
        [fn () => Builder::select('x')->from('tbl')->sortBy('y'), 'SELECT x FROM tbl SORT BY y', 'hive'],
        [fn () => Builder::select('x')->from('tbl')->orderBy('x, y DESC'), 'SELECT x FROM tbl ORDER BY x, y DESC'],
        [fn () => Builder::select('x')->from('tbl')->clusterBy('x, y'), 'SELECT x FROM tbl CLUSTER BY x, y', 'hive'],
        [fn () => Builder::select('x')->from('tbl')->sortBy('x, y DESC'), 'SELECT x FROM tbl SORT BY x, y DESC', 'hive'],
        [fn () => Builder::select('x', 'y', 'z', 'a')->from('tbl')->orderBy('x, y', 'z')->orderBy('a'), 'SELECT x, y, z, a FROM tbl ORDER BY x, y, z, a'],
        [fn () => Builder::select('x', 'y', 'z', 'a')->from('tbl')->clusterBy('x, y', 'z')->clusterBy('a'), 'SELECT x, y, z, a FROM tbl CLUSTER BY x, y, z, a', 'hive'],
        [fn () => Builder::select('x', 'y', 'z', 'a')->from('tbl')->sortBy('x, y', 'z')->sortBy('a'), 'SELECT x, y, z, a FROM tbl SORT BY x, y, z, a', 'hive'],
        [fn () => Builder::select('x')->from('tbl')->limit(10), 'SELECT x FROM tbl LIMIT 10'],
        [fn () => Builder::select('x')->from('tbl')->offset(10), 'SELECT x FROM tbl OFFSET 10'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: 'SELECT x FROM tbl2'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: 'SELECT x FROM tbl2', materialized: true), 'WITH tbl AS MATERIALIZED (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: 'SELECT x FROM tbl2', materialized: false), 'WITH tbl AS NOT MATERIALIZED (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: 'SELECT x FROM tbl2', recursive: true), 'WITH RECURSIVE tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: Builder::select('x')->from('tbl2'), recursive: true, materialized: true), 'WITH RECURSIVE tbl AS MATERIALIZED (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: Builder::select('x')->from('tbl2'), recursive: true, materialized: false), 'WITH RECURSIVE tbl AS NOT MATERIALIZED (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: Builder::select('x')->from('tbl2')), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl (x, y)', as: Builder::select('x', 'y')->from('tbl2')), 'WITH tbl(x, y) AS (SELECT x, y FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: Builder::select('x')->from('tbl2'))->with('tbl2', as: Builder::select('x')->from('tbl3')), 'WITH tbl AS (SELECT x FROM tbl2), tbl2 AS (SELECT x FROM tbl3) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->from('tbl')->with('tbl', as: Builder::select('x', 'y')->from('tbl2'))->select('y'), 'WITH tbl AS (SELECT x, y FROM tbl2) SELECT x, y FROM tbl'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->groupBy('x'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl GROUP BY x'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->orderBy('x'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl ORDER BY x'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->limit(10), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl LIMIT 10'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->offset(10), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl OFFSET 10'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->join('tbl3'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl, tbl3'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->distinct(), 'WITH tbl AS (SELECT x FROM tbl2) SELECT DISTINCT x FROM tbl'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->where('x > 10'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl WHERE x > 10'],
        [fn () => Builder::select('x')->with('tbl', as: Builder::select('x')->from('tbl2'))->from('tbl')->having('x > 20'), 'WITH tbl AS (SELECT x FROM tbl2) SELECT x FROM tbl HAVING x > 20'],
        [fn () => Builder::select('x')->from('tbl')->subquery(), '(SELECT x FROM tbl)'],
        [fn () => Builder::select('x')->from('tbl')->subquery('y'), '(SELECT x FROM tbl) AS y'],
        [fn () => Builder::select('x')->from(Builder::select('x')->from('tbl')->subquery()), 'SELECT x FROM (SELECT x FROM tbl)'],
        [fn () => Builder::from('tbl')->select('x'), 'SELECT x FROM tbl'],
        [fn () => parseOne('SELECT a FROM tbl')->assertIs(Select::class)->select('b'), 'SELECT a, b FROM tbl'],
        [fn () => parseOne('SELECT * FROM y')->assertIs(Select::class)->ctas('x'), 'CREATE TABLE x AS SELECT * FROM y'],
        [fn () => parseOne('SELECT * FROM y')->assertIs(Select::class)->ctas('foo.x', properties: ['format' => 'parquet', 'y' => '2']), "CREATE TABLE foo.x STORED AS PARQUET TBLPROPERTIES ('y'='2') AS SELECT * FROM y", 'hive'],
        [fn () => Builder::and(['x=1', 'y=1']), 'x = 1 AND y = 1'],
        [fn () => Builder::condition('x')->and("y['a']")->and('1'), "(x AND y['a']) AND 1"],
        [fn () => Builder::condition('x=1')->and('y=1'), 'x = 1 AND y = 1'],
        [fn () => Builder::and(['x=1', 'y=1', 'z=1']), 'x = 1 AND y = 1 AND z = 1'],
        [fn () => Builder::condition('x=1')->and('y=1', 'z=1'), 'x = 1 AND y = 1 AND z = 1'],
        [fn () => Builder::and(['x=1', Builder::and(['y=1', 'z=1'])]), 'x = 1 AND (y = 1 AND z = 1)'],
        [fn () => Builder::condition('x=1')->and('y=1')->and('z=1'), '(x = 1 AND y = 1) AND z = 1'],
        [fn () => Builder::or([Builder::and(['x=1', 'y=1']), 'z=1']), '(x = 1 AND y = 1) OR z = 1'],
        [fn () => Builder::condition('x=1')->and('y=1')->or('z=1'), '(x = 1 AND y = 1) OR z = 1'],
        [fn () => Builder::or(['z=1', Builder::and(['x=1', 'y=1'])]), 'z = 1 OR (x = 1 AND y = 1)'],
        [fn () => Builder::or(['z=1 OR a=1', Builder::and(['x=1', 'y=1'])]), '(z = 1 OR a = 1) OR (x = 1 AND y = 1)'],
        [fn () => Builder::not('x=1'), 'NOT x = 1'],
        [fn () => Builder::condition('x=1')->not(), 'NOT x = 1'],
        [fn () => Builder::condition('x=1')->and('y=1')->not(), 'NOT (x = 1 AND y = 1)'],
        [fn () => Builder::select('*')->from('x')->where(Builder::condition('y=1')->and('z=1')), 'SELECT * FROM x WHERE y = 1 AND z = 1'],
        [fn () => Builder::subquery('select x from tbl', 'foo')->select('x')->where('x > 0'), 'SELECT x FROM (SELECT x FROM tbl) AS foo WHERE x > 0'],
        [fn () => Builder::subquery('select x from tbl UNION select x from bar', 'unioned')->select('x'), 'SELECT x FROM (SELECT x FROM tbl UNION SELECT x FROM bar) AS unioned'],
        [fn () => parseOne('(SELECT 1)')->select('2'), '(SELECT 1, 2)'],
        [fn () => parseOne('(SELECT 1)')->limit(1), '(SELECT 1) LIMIT 1'],
        [fn () => parseOne('WITH t AS (SELECT 1) (SELECT 1)')->limit(1), 'WITH t AS (SELECT 1) SELECT 1 LIMIT 1'],
        [fn () => parseOne('(SELECT 1 LIMIT 2)')->limit(1), '(SELECT 1 LIMIT 2) LIMIT 1'],
        [fn () => parseOne('SELECT 1 UNION SELECT 2')->limit(5)->offset(2), 'SELECT 1 UNION SELECT 2 LIMIT 5 OFFSET 2'],
        [fn () => parseOne('(SELECT 1)')->subquery(), '((SELECT 1))'],
        [fn () => parseOne('(SELECT 1)')->subquery('alias'), '((SELECT 1)) AS alias'],
        [fn () => parseOne('(select * from foo)')->with('foo', 'select 1 as c'), 'WITH foo AS (SELECT 1 AS c) (SELECT * FROM foo)'],
        [fn () => Builder::update('tbl', ['x' => null, 'y' => ['x' => 1]]), "UPDATE tbl SET x = NULL, y = MAP(ARRAY('x'), ARRAY(1))"],
        [fn () => Builder::update('tbl', ['x' => 1], where: 'y > 0'), 'UPDATE tbl SET x = 1 WHERE y > 0'],
        [fn () => Builder::update('tbl', ['x' => 1], where: Builder::condition('y > 0')), 'UPDATE tbl SET x = 1 WHERE y > 0'],
        [fn () => Builder::update('tbl', ['x' => 1], from: 'tbl2'), 'UPDATE tbl SET x = 1 FROM tbl2'],
        [fn () => Builder::update('tbl', ['x' => 1], from: 'tbl2 cross join tbl3'), 'UPDATE tbl SET x = 1 FROM tbl2 CROSS JOIN tbl3'],
        [fn () => Builder::update('my_table', ['x' => 1], from: 'baz', where: 'my_table.id = baz.id', with: ['baz' => 'SELECT id FROM foo UNION SELECT id FROM bar']), 'WITH baz AS (SELECT id FROM foo UNION SELECT id FROM bar) UPDATE my_table SET x = 1 FROM baz WHERE my_table.id = baz.id'],
        [fn () => Builder::update('my_table')->assign('x = 1'), 'UPDATE my_table SET x = 1'],
        [fn () => Builder::update('my_table')->assign('x = 1')->where('y = 2'), 'UPDATE my_table SET x = 1 WHERE y = 2'],
        [fn () => Builder::update('my_table')->assign('a = 1')->assign('b = 2'), 'UPDATE my_table SET a = 1, b = 2'],
        [fn () => Builder::update('my_table')->assign('x = 1')->where('my_table.id = baz.id')->from('baz')->with('baz', 'SELECT id FROM foo'), 'WITH baz AS (SELECT id FROM foo) UPDATE my_table SET x = 1 FROM baz WHERE my_table.id = baz.id'],
        [fn () => Builder::union('SELECT * FROM foo', 'SELECT * FROM bla'), 'SELECT * FROM foo UNION SELECT * FROM bla'],
        [fn () => parseOne('SELECT * FROM foo')->union('SELECT * FROM bla'), 'SELECT * FROM foo UNION SELECT * FROM bla'],
        [fn () => Builder::intersect('SELECT * FROM foo', 'SELECT * FROM bla'), 'SELECT * FROM foo INTERSECT SELECT * FROM bla'],
        [fn () => parseOne('SELECT * FROM foo')->intersect('SELECT * FROM bla'), 'SELECT * FROM foo INTERSECT SELECT * FROM bla'],
        [fn () => Builder::except('SELECT * FROM foo', 'SELECT * FROM bla'), 'SELECT * FROM foo EXCEPT SELECT * FROM bla'],
        [fn () => parseOne('SELECT * FROM foo')->except('SELECT * FROM bla'), 'SELECT * FROM foo EXCEPT SELECT * FROM bla'],
        [fn () => parseOne('(SELECT * FROM foo)')->union('SELECT * FROM bla'), '(SELECT * FROM foo) UNION SELECT * FROM bla'],
        [fn () => parseOne('(SELECT * FROM foo)')->union('SELECT * FROM bla', distinct: false), '(SELECT * FROM foo) UNION ALL SELECT * FROM bla'],
        [fn () => Builder::alias(parseOne('LAG(x) OVER (PARTITION BY y)'), 'a'), 'LAG(x) OVER (PARTITION BY y) AS a'],
        [fn () => Builder::alias(parseOne('LAG(x) OVER (ORDER BY z)'), 'a'), 'LAG(x) OVER (ORDER BY z) AS a'],
        [fn () => Builder::alias(parseOne('LAG(x) OVER (PARTITION BY y ORDER BY z)'), 'a'), 'LAG(x) OVER (PARTITION BY y ORDER BY z) AS a'],
        [fn () => Builder::alias(parseOne('LAG(x) OVER ()'), 'a'), 'LAG(x) OVER () AS a'],
        [fn () => Builder::values([['1', 2]]), "VALUES ('1', 2)"],
        [fn () => Builder::values([['1', 2]], 'alias'), "(VALUES ('1', 2)) AS alias"],
        [fn () => Builder::values([['1', 2], ['2', 3]]), "VALUES ('1', 2), ('2', 3)"],
        [fn () => Builder::values([['1', 2, null], ['2', 3, null]], 'alias', ['col1', 'col2', 'col3']), "(VALUES ('1', 2, NULL), ('2', 3, NULL)) AS alias(col1, col2, col3)"],
        [fn () => Builder::delete('y', where: 'x > 1'), 'DELETE FROM y WHERE x > 1'],
        [fn () => Builder::delete('y', where: Builder::and(['x > 1'])), 'DELETE FROM y WHERE x > 1'],
        [fn () => Builder::select('AVG(a) OVER b')->from('table')->window('b AS (PARTITION BY c ORDER BY d)'), 'SELECT AVG(a) OVER b FROM table WINDOW b AS (PARTITION BY c ORDER BY d)'],
        [fn () => Builder::select('AVG(a) OVER b', 'MIN(c) OVER d')->from('table')->window('b AS (PARTITION BY e ORDER BY f)')->window('d AS (PARTITION BY g ORDER BY h)'), 'SELECT AVG(a) OVER b, MIN(c) OVER d FROM table WINDOW b AS (PARTITION BY e ORDER BY f), d AS (PARTITION BY g ORDER BY h)'],
        [fn () => Builder::select('*')->from('table')->qualify('row_number() OVER (PARTITION BY a ORDER BY b) = 1'), 'SELECT * FROM table QUALIFY ROW_NUMBER() OVER (PARTITION BY a ORDER BY b) = 1'],
        [fn () => Builder::delete('tbl1', 'x = 1')->delete('tbl2'), 'DELETE FROM tbl2 WHERE x = 1'],
        [fn () => Builder::delete('tbl')->where('x = 1'), 'DELETE FROM tbl WHERE x = 1'],
        [fn () => Builder::delete(Builder::table('tbl')), 'DELETE FROM tbl'],
        [fn () => Builder::delete('tbl', 'x = 1')->where('y = 2'), 'DELETE FROM tbl WHERE x = 1 AND y = 2'],
        [fn () => Builder::delete('tbl', 'x = 1')->where(Builder::condition('y = 2')->or('z = 3')), 'DELETE FROM tbl WHERE x = 1 AND (y = 2 OR z = 3)'],
        [fn () => Builder::delete('tbl')->where('x = 1')->returning('*', dialect: 'postgres'), 'DELETE FROM tbl WHERE x = 1 RETURNING *', 'postgres'],
        [fn () => Builder::delete('tbl', where: 'x = 1', returning: '*', dialect: 'postgres'), 'DELETE FROM tbl WHERE x = 1 RETURNING *', 'postgres'],
        [fn () => Builder::insert('SELECT * FROM tbl2', 'tbl'), 'INSERT INTO tbl SELECT * FROM tbl2'],
        [fn () => Builder::insert('SELECT * FROM tbl2', 'tbl', returning: '*'), 'INSERT INTO tbl SELECT * FROM tbl2 RETURNING *'],
        [fn () => Builder::insert('SELECT * FROM tbl2', 'tbl', overwrite: true), 'INSERT OVERWRITE TABLE tbl SELECT * FROM tbl2'],
        [fn () => Builder::insert('VALUES (1, 2), (3, 4)', 'tbl', columns: ['cola', 'colb']), 'INSERT INTO tbl (cola, colb) VALUES (1, 2), (3, 4)'],
        [fn () => Builder::insert('VALUES (1), (2)', 'tbl', columns: ['col a']), 'INSERT INTO tbl ("col a") VALUES (1), (2)'],
        [fn () => Builder::insert('SELECT * FROM cte', 't')->with('cte', as: 'SELECT x FROM tbl'), 'WITH cte AS (SELECT x FROM tbl) INSERT INTO t SELECT * FROM cte'],
        [fn () => Builder::insert('SELECT * FROM cte', 't')->with('cte', as: 'SELECT x FROM tbl', materialized: true), 'WITH cte AS MATERIALIZED (SELECT x FROM tbl) INSERT INTO t SELECT * FROM cte'],
        [fn () => Builder::insert('SELECT * FROM cte', 't')->with('cte', as: 'SELECT x FROM tbl', materialized: false), 'WITH cte AS NOT MATERIALIZED (SELECT x FROM tbl) INSERT INTO t SELECT * FROM cte'],
        [fn () => (new Tuple(['expressions' => [Builder::column('x'), Builder::column('y')]]))->isin(Builder::tuple(1, 2), Builder::tuple(3, 4)), '(x, y) IN ((1, 2), (3, 4))', 'postgres'],
        [fn () => Builder::cast('CAST(x AS INT)', 'int'), 'CAST(x AS INT)'],
        [fn () => Builder::cast('CAST(x AS TEXT)', 'int'), 'CAST(CAST(x AS TEXT) AS INT)'],
        [fn () => Builder::renameColumn('table1', 'c1', 'c2', true), 'ALTER TABLE table1 RENAME COLUMN IF EXISTS c1 TO c2'],
        [fn () => Builder::renameColumn('table1', 'c1', 'c2', false), 'ALTER TABLE table1 RENAME COLUMN c1 TO c2'],
        [fn () => Builder::renameColumn('table1', 'c1', 'c2'), 'ALTER TABLE table1 RENAME COLUMN c1 TO c2'],
        [
            fn () => Builder::merge(
                'WHEN MATCHED THEN UPDATE SET col1 = source.col1',
                'WHEN NOT MATCHED THEN INSERT (col1) VALUES (source.col1)',
                into: 'target_table',
                using: 'source_table',
                on: 'target_table.id = source_table.id',
            ),
            'MERGE INTO target_table USING source_table ON target_table.id = source_table.id WHEN MATCHED THEN UPDATE SET col1 = source.col1 WHEN NOT MATCHED THEN INSERT (col1) VALUES (source.col1)',
        ],
        [
            fn () => Builder::merge(
                'WHEN MATCHED AND source.is_deleted = 1 THEN DELETE',
                'WHEN MATCHED THEN UPDATE SET val = source.val',
                'WHEN NOT MATCHED THEN INSERT (id, val) VALUES (source.id, source.val)',
                into: 'target_table',
                using: 'source_table',
                on: 'target_table.id = source_table.id',
            ),
            'MERGE INTO target_table USING source_table ON target_table.id = source_table.id WHEN MATCHED AND source.is_deleted = 1 THEN DELETE WHEN MATCHED THEN UPDATE SET val = source.val WHEN NOT MATCHED THEN INSERT (id, val) VALUES (source.id, source.val)',
        ],
        [
            fn () => Builder::merge(
                'WHEN MATCHED THEN UPDATE SET target.name = source.name',
                into: Builder::table('target_table')->as('target'),
                using: Builder::table('source_table')->as('source'),
                on: 'target.id = source.id',
            ),
            'MERGE INTO target_table AS target USING source_table AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET target.name = source.name',
        ],
        [
            fn () => Builder::merge(
                'WHEN MATCHED THEN UPDATE SET target.name = source.name',
                into: Builder::table('target_table')->as('target'),
                using: Builder::table('source_table')->as('source'),
                on: 'target.id = source.id',
                returning: 'target.*',
            ),
            'MERGE INTO target_table AS target USING source_table AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET target.name = source.name RETURNING target.*',
        ],
        [
            fn () => Builder::merge(
                new When([
                    'matched' => true,
                    'then' => new Update(['expressions' => [Builder::column('name', 'target')->eq(Builder::column('name', 'source'))]]),
                ]),
                into: Builder::table('target_table')->as('target'),
                using: Builder::table('source_table')->as('source'),
                on: 'target.id = source.id',
                returning: 'target.*',
            ),
            'MERGE INTO target_table AS target USING source_table AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET target.name = source.name RETURNING target.*',
        ],
        [fn () => Builder::union('SELECT 1', 'SELECT 2', 'SELECT 3', 'SELECT 4'), 'SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4'],
        [fn () => Builder::select('x')->with('var1', as: Builder::select('x')->from('tbl2')->subquery(), scalar: true)->from('tbl')->where('x > var1'), 'WITH (SELECT x FROM tbl2) AS var1 SELECT x FROM tbl WHERE x > var1', 'clickhouse'],
        [fn () => Builder::select('x')->with('var1', as: Builder::select('x')->from('tbl2'), scalar: true)->from('tbl')->where('x > var1'), 'WITH (SELECT x FROM tbl2) AS var1 SELECT x FROM tbl WHERE x > var1', 'clickhouse'],
    ];

    $named = [];
    foreach ($cases as $i => $case) {
        $named[sprintf('#%03d %s', $i, $case[1])] = $case;
    }

    return $named;
}

it('builds', function (string $name): void {
    [$build, $sql] = buildCases()[$name];
    $dialect = buildCases()[$name][2] ?? null;

    expect($build()->sql($dialect))->toBe($sql);
})->with(array_keys(buildCases()));

// Builder helpers covered by sqlglot's tests/test_expressions.py

it('builds properties from a dict', function (): void {
    $properties = Properties::fromDict([
        'FORMAT' => 'parquet',
        'PARTITIONED_BY' => new Tuple(['expressions' => [Builder::toIdentifier('a'), Builder::toIdentifier('b')]]),
        'custom' => 1,
        'ENGINE' => null,
        'COLLATE' => true,
    ]);

    $expected = new Properties(['expressions' => [
        new FileFormatProperty(['this' => Literal::string('parquet')]),
        new PartitionedByProperty(['this' => new Tuple(['expressions' => [Builder::toIdentifier('a'), Builder::toIdentifier('b')]])]),
        new Property(['this' => Literal::string('custom'), 'value' => Literal::number(1)]),
        new EngineProperty(['this' => Builder::null()]),
        new CollateProperty(['this' => Builder::true()]),
    ]]);

    expect($properties->equals($expected))->toBeTrue()
        ->and(fn () => Properties::fromDict(['FORMAT' => new stdClass]))->toThrow(InvalidArgumentException::class);
});

it('builds tables from names', function (): void {
    $table = Builder::toTable('table_name');
    expect($table->name())->toBe('table_name')
        ->and($table->args['db'] ?? null)->toBeNull()
        ->and($table->args['catalog'] ?? null)->toBeNull();

    $table = Builder::toTable('db.table_name');
    expect($table->name())->toBe('table_name')
        ->and($table->args['db']->equals(Builder::toIdentifier('db')))->toBeTrue()
        ->and($table->args['catalog'] ?? null)->toBeNull();

    $table = Builder::toTable('catalog.db.table_name');
    expect($table->name())->toBe('table_name')
        ->and($table->args['db']->equals(Builder::toIdentifier('db')))->toBeTrue()
        ->and($table->args['catalog']->equals(Builder::toIdentifier('catalog')))->toBeTrue()
        ->and(Builder::toTable('3e')->sql())->toBe('"3e"');
});

it('builds columns from names', function (): void {
    $column = Builder::toColumn('column_name');
    expect($column->name())->toBe('column_name')
        ->and($column->args['table'] ?? null)->toBeNull();

    $column = Builder::toColumn('table_name.column_name');
    expect($column->name())->toBe('column_name')
        ->and($column->args['table']->equals(Builder::toIdentifier('table_name')))->toBeTrue()
        ->and(Builder::toColumn('foo bar')->sql())->toBe('"foo bar"')
        ->and(Builder::toColumn('`column_name`', dialect: 'spark')->sql())->toBe('"column_name"')
        ->and(Builder::toColumn('column_name', quoted: true)->sql())->toBe('"column_name"')
        ->and(Builder::toColumn('column_name', args: ['table' => Builder::toIdentifier('table_name')])->sql())->toBe('table_name.column_name');
});

it('builds values with column names', function (): void {
    expect(Builder::values([[1, 2], [3, 4]], 't', ['a', 'b'])->sql())->toBe('(VALUES (1, 2), (3, 4)) AS t(a, b)')
        ->and(Builder::values([[1, 2], [3, 4]], 't', ['a' => DataType::build('TEXT'), 'b' => DataType::build('TEXT')])->sql())->toBe('(VALUES (1, 2), (3, 4)) AS t(a, b)')
        ->and(fn () => Builder::values([[1, 2], [3, 4]], columns: ['a']))->toThrow(InvalidArgumentException::class);
});

it('builds table renames and identifiers', function (): void {
    expect(Builder::renameTable('t1', 't2')->sql())->toBe('ALTER TABLE t1 RENAME TO t2')
        ->and(Builder::parseIdentifier("a ' b")->equals(Builder::toIdentifier("a ' b")))->toBeTrue();
});

it('asserts expression types', function (): void {
    expect(parseOne('x')->assertIs(Column::class))->toBeInstanceOf(Column::class)
        ->and(fn () => parseOne('x')->assertIs(Identifier::class))->toThrow(InvalidArgumentException::class);
});

it('rejects unknown builder options', function (): void {
    expect(fn () => Builder::select('x', dialet: 'mysql'))->toThrow(InvalidArgumentException::class, "Unknown builder option 'dialet'.")
        ->and(fn () => Builder::union('SELECT 1'))->toThrow(InvalidArgumentException::class);
});

it('copies by default and mutates in place with copy: false', function (): void {
    $select = Builder::select('x')->from('tbl');
    $select->where('x > 1');
    expect($select->sql())->toBe('SELECT x FROM tbl');

    $select->where('x > 1', copy: false);
    expect($select->sql())->toBe('SELECT x FROM tbl WHERE x > 1');
});
