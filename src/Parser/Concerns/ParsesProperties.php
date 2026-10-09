<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use Error;
use LaravelSqlParser\LaravelSqlParser\Expressions\BlockCompressionProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CalledOnNullInputProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\CharacterSetProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ChecksumProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cluster;
use LaravelSqlParser\LaravelSqlParser\Expressions\ClusteredByProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ClusterProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\CopyGrantsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataBlocksizeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataDeletionProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DefinerProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DistKeyProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DistributedByProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ExecuteAsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\FallbackProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\FileFormatProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\FreespaceProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\InputOutputFormat;
use LaravelSqlParser\LaravelSqlParser\Expressions\IsolatedLoadingProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\JournalProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\LikeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\LockingProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\LogProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\MergeBlockRatioProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\NoPrimaryIndexProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\OnCommitProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\OnProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ParameterStyleProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionBoundSpec;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionedByProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionedOfProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;
use LaravelSqlParser\LaravelSqlParser\Expressions\RemoteWithConnectionModelProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\SettingsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\SortKeyProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\SqlReadWriteProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\SqlSecurityProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\StabilityProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\StorageHandlerProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ViewAttributeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\VolatileProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithDataProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithJournalTableProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithProcedureOptions;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithSchemaBindingProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithSystemVersioningProperty;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * DDL property grammar (sqlglot parser.py, from _parse_property_before to _parse_returns).
 */
trait ParsesProperties
{
    /**
     * @return Expression|list<Expression>|null
     */
    protected function parsePropertyBefore(): Expression|array|null
    {
        // only used for teradata currently
        $this->match(TokenType::COMMA);

        $kwargs = [
            'no' => $this->matchTextSeq('NO'),
            'dual' => $this->matchTextSeq('DUAL'),
            'before' => $this->matchTextSeq('BEFORE'),
            'default' => $this->matchTextSeq('DEFAULT'),
            'local' => $this->matchTextSeq('LOCAL') ? 'LOCAL' : ($this->matchTextSeq('NOT', 'LOCAL') ? 'NOT LOCAL' : null),
            'after' => $this->matchTextSeq('AFTER'),
            'minimum' => $this->matchTexts(['MIN' => true, 'MINIMUM' => true]),
            'maximum' => $this->matchTexts(['MAX' => true, 'MAXIMUM' => true]),
        ];

        if ($this->matchTexts($this->t['PROPERTY_PARSERS'])) {
            $handler = $this->t['PROPERTY_PARSERS'][strtoupper($this->prevText())];

            try {
                return $this->callHandler($handler, ...array_filter($kwargs, static fn (mixed $v): bool => $v !== null && $v !== false));
            } catch (Error $e) {
                if (! str_starts_with($e->getMessage(), 'Unknown named parameter')) {
                    throw $e;
                }

                $this->raiseError("Cannot parse property '{$this->prevText()}'");
            }
        }

        if ($this->matchTextSeq('CHARACTER', 'SET')) {
            return $this->parseCharacterSet(default: $kwargs['default']);
        }

        return null;
    }

    /**
     * @return list<Expression|list<Expression>|null>
     */
    protected function parseWrappedProperties(): array
    {
        return $this->parseWrappedCsv(fn (): Expression|array|null => $this->parseProperty());
    }

    /**
     * @return Expression|list<Expression>|null
     */
    protected function parseProperty(): Expression|array|null
    {
        if ($this->matchTexts($this->t['PROPERTY_PARSERS'])) {
            return $this->callHandler($this->t['PROPERTY_PARSERS'][strtoupper($this->prevText())]);
        }

        if ($this->matchTextSeq('CHARACTER', 'SET')) {
            return $this->parseCharacterSet();
        }

        if ($this->match(TokenType::DEFAULT)) {
            if ($this->matchTexts($this->t['PROPERTY_PARSERS'])) {
                return $this->callHandler($this->t['PROPERTY_PARSERS'][strtoupper($this->prevText())], default: true);
            }

            if ($this->matchTextSeq('CHARACTER', 'SET')) {
                return $this->parseCharacterSet(default: true);
            }
        }

        if ($this->matchTextSeq('COMPOUND', 'SORTKEY')) {
            return $this->parseSortkey(compound: true);
        }

        if ($this->matchTextSeq('PARAMETER', 'STYLE', 'PANDAS')) {
            return $this->expression(new ParameterStyleProperty(['this' => 'PANDAS']));
        }

        if ($this->matchTextSeq('NOT', 'DETERMINISTIC')) {
            return $this->expression(new StabilityProperty(['this' => Literal::string('VOLATILE')]));
        }

        $index = $this->index;

        $seqProps = $this->parseSequenceProperties();

        if ($seqProps !== null) {
            return $seqProps;
        }

        $this->retreat($index);

        return $this->parseKeyValueProperty();
    }

    /**
     * @param  (callable(): ?Expression)|null  $parseValue
     */
    protected function parseKeyValueProperty(?callable $parseValue = null): ?Property
    {
        $index = $this->index;
        $key = $this->parseColumn();

        if (! $this->match(TokenType::EQ)) {
            $this->retreat($index);

            return null;
        }

        // Transform the key to exp.Dot if it's dotted identifiers wrapped in exp.Column or to exp.Var otherwise
        if ($key instanceof Column) {
            $key = count($key->parts()) > 1 ? $key->toDot() : Builder::var($key->name());
        }

        $value = $parseValue !== null ? $parseValue() : ($this->parseBitwise() ?? $this->parseVar(anyToken: true));

        // Transform the value to exp.Var if it was parsed as exp.Column(exp.Identifier())
        if ($value instanceof Column) {
            $value = Builder::var($value->name());
        }

        return $this->expression(new Property(['this' => $key, 'value' => $value]));
    }

    protected function parseStored(): Expression
    {
        if ($this->matchTextSeq('BY')) {
            return $this->expression(new StorageHandlerProperty(['this' => $this->parseVarOrString()]));
        }

        $this->match(TokenType::ALIAS);
        $inputFormat = $this->matchTextSeq('INPUTFORMAT') ? $this->parseString() : null;
        $outputFormat = $this->matchTextSeq('OUTPUTFORMAT') ? $this->parseString() : null;

        return $this->expression(new FileFormatProperty([
            'this' => $inputFormat !== null || $outputFormat !== null
                ? $this->expression(new InputOutputFormat(['input_format' => $inputFormat, 'output_format' => $outputFormat]))
                : ($this->parseVarOrString() ?? $this->parseNumber() ?? $this->parseIdVar()),
            'hive_format' => true,
        ]));
    }

    protected function parseUnquotedField(): ?Expression
    {
        $field = $this->parseField();

        if ($field instanceof Identifier && ! $field->flag('quoted')) {
            $field = Builder::var($field);
        }

        return $field;
    }

    /**
     * @template T of Expression
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $args
     * @return T
     */
    protected function parsePropertyAssignment(string $class, array $args = []): Expression
    {
        $this->match(TokenType::EQ);
        $this->match(TokenType::ALIAS);

        return $this->expression(new $class(['this' => $this->parseUnquotedField(), ...$args]));
    }

    protected function parseProperties(?bool $before = null): ?Properties
    {
        $properties = [];

        while (true) {
            $prop = $before === true ? $this->parsePropertyBefore() : $this->parseProperty();

            if ($prop === null || $prop === []) {
                break;
            }

            foreach (is_array($prop) ? $prop : [$prop] as $p) {
                $properties[] = $p;
            }
        }

        if ($properties !== []) {
            return $this->expression(new Properties(['expressions' => $properties]));
        }

        return null;
    }

    protected function parseFallback(bool $no = false): FallbackProperty
    {
        return $this->expression(new FallbackProperty(['no' => $no, 'protection' => $this->matchTextSeq('PROTECTION')]));
    }

    protected function parseSqlSecurity(): SqlSecurityProperty
    {
        return $this->expression(new SqlSecurityProperty([
            'this' => $this->matchTexts($this->t['SECURITY_PROPERTY_KEYWORDS']) ? strtoupper($this->prevText()) : false,
        ]));
    }

    protected function parseSettingsProperty(): SettingsProperty
    {
        return $this->expression(new SettingsProperty(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseAssignment())]));
    }

    protected function parseCalledOnNullInputProperty(): ?CalledOnNullInputProperty
    {
        if (! $this->matchTextSeq('ON', 'NULL', 'INPUT')) {
            $this->retreat($this->index - 1);

            return null;
        }

        return $this->expression(new CalledOnNullInputProperty);
    }

    protected function parseVolatileProperty(): Expression
    {
        $preVolatileToken = $this->index - 2 >= $this->chunkStart ? $this->types[$this->index - 2] : null;

        if ($preVolatileToken !== null && isset($this->t['PRE_VOLATILE_TOKENS'][$preVolatileToken])) {
            return new VolatileProperty;
        }

        return $this->expression(new StabilityProperty(['this' => Literal::string('VOLATILE')]));
    }

    protected function parseRetentionPeriod(): VarExpr
    {
        // Parse TSQL's HISTORY_RETENTION_PERIOD: {INFINITE | <number> DAY | DAYS | MONTH ...}
        $number = $this->parseNumber();
        $numberStr = $number !== null ? $number->sql().' ' : '';
        $unit = $this->parseVar(anyToken: true);

        return Builder::var($numberStr.($unit?->sql() ?? 'None'));
    }

    protected function parseSystemVersioningProperty(bool $with = false): WithSystemVersioningProperty
    {
        $this->match(TokenType::EQ);
        $prop = $this->expression(new WithSystemVersioningProperty(['on' => true, 'with_' => $with]));

        if ($this->matchTextSeq('OFF')) {
            $prop->set('on', false);

            return $prop;
        }

        $this->match(TokenType::ON);

        if ($this->match(TokenType::L_PAREN)) {
            while ($this->curr !== 0 && ! $this->match(TokenType::R_PAREN)) {
                if ($this->matchTextSeq('HISTORY_TABLE', '=')) {
                    $prop->set('this', $this->parseTableParts());
                } elseif ($this->matchTextSeq('DATA_CONSISTENCY_CHECK', '=')) {
                    $prop->set('data_consistency', $this->advanceAny() !== null ? strtoupper($this->prevText()) : null);
                } elseif ($this->matchTextSeq('HISTORY_RETENTION_PERIOD', '=')) {
                    $prop->set('retention_period', $this->parseRetentionPeriod());
                }

                $this->match(TokenType::COMMA);
            }
        }

        return $prop;
    }

    protected function parseDataDeletionProperty(): DataDeletionProperty
    {
        $this->match(TokenType::EQ);
        $on = $this->matchTextSeq('ON') || ! $this->matchTextSeq('OFF');
        $prop = $this->expression(new DataDeletionProperty(['on' => $on]));

        if ($this->match(TokenType::L_PAREN)) {
            while ($this->curr !== 0 && ! $this->match(TokenType::R_PAREN)) {
                if ($this->matchTextSeq('FILTER_COLUMN', '=')) {
                    $prop->set('filter_column', $this->parseColumn());
                } elseif ($this->matchTextSeq('RETENTION_PERIOD', '=')) {
                    $prop->set('retention_period', $this->parseRetentionPeriod());
                }

                $this->match(TokenType::COMMA);
            }
        }

        return $prop;
    }

    protected function parseDistributedProperty(): DistributedByProperty
    {
        $kind = 'HASH';
        $expressions = null;

        if ($this->matchTextSeq('BY', 'HASH')) {
            $expressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parseIdVar());
        } elseif ($this->matchTextSeq('BY', 'RANDOM')) {
            $kind = 'RANDOM';
        }

        // If the BUCKETS keyword is not present, the number of buckets is AUTO
        $buckets = null;

        if ($this->matchTextSeq('BUCKETS') && ! $this->matchTextSeq('AUTO')) {
            $buckets = $this->parseNumber();
        }

        return $this->expression(new DistributedByProperty([
            'expressions' => $expressions,
            'kind' => $kind,
            'buckets' => $buckets,
            'order' => $this->parseOrder(),
        ]));
    }

    /**
     * @template T of Expression
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function parseCompositeKeyProperty(string $class): Expression
    {
        $this->matchTextSeq('KEY');
        $expressions = $this->parseWrappedIdVars();

        return $this->expression(new $class(['expressions' => $expressions]));
    }

    /**
     * @return Expression|list<Expression>|null
     */
    protected function parseWithProperty(): Expression|array|null
    {
        if ($this->matchTextSeq('(', 'SYSTEM_VERSIONING')) {
            $prop = $this->parseSystemVersioningProperty(with: true);
            $this->matchRParen();

            return $prop;
        }

        if ($this->match(TokenType::L_PAREN, false)) {
            $result = [];
            foreach ($this->parseWrappedProperties() as $item) {
                if (is_array($item)) {
                    array_push($result, ...$item);
                } elseif ($item !== null) {
                    $result[] = $item;
                }
            }

            return $result;
        }

        if ($this->matchTextSeq('JOURNAL')) {
            return $this->parseWithjournaltable();
        }

        if ($this->matchTexts($this->t['VIEW_ATTRIBUTES'])) {
            return $this->expression(new ViewAttributeProperty(['this' => strtoupper($this->prevText())]));
        }

        if ($this->matchTextSeq('DATA')) {
            return $this->parseWithdata(no: false);
        }

        if ($this->matchTextSeq('NO', 'DATA')) {
            return $this->parseWithdata(no: true);
        }

        if ($this->match(TokenType::SERDE_PROPERTIES, false)) {
            return $this->parseSerdeProperties(with: true);
        }

        if ($this->match(TokenType::SCHEMA)) {
            return $this->expression(new WithSchemaBindingProperty([
                'this' => $this->parseVarFromOptions($this->t['SCHEMA_BINDING_OPTIONS']),
            ]));
        }

        if ($this->matchTexts($this->t['PROCEDURE_OPTIONS'], false)) {
            return $this->expression(new WithProcedureOptions([
                'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseProcedureOption()),
            ]));
        }

        if ($this->next === 0) {
            return null;
        }

        return $this->parseWithisolatedloading();
    }

    protected function parseProcedureOption(): ?Expression
    {
        if ($this->matchTextSeq('EXECUTE', 'AS')) {
            return $this->expression(new ExecuteAsProperty([
                'this' => $this->parseVarFromOptions($this->t['EXECUTE_AS_OPTIONS'], raiseUnmatched: false) ?? $this->parseString(),
            ]));
        }

        return $this->parseVarFromOptions($this->t['PROCEDURE_OPTIONS']);
    }

    // https://dev.mysql.com/doc/refman/8.0/en/create-view.html
    protected function parseDefiner(): ?DefinerProperty
    {
        $this->match(TokenType::EQ);

        $user = $this->parseIdVar();
        $this->match(TokenType::PARAMETER);
        $host = $this->parseIdVar();
        $hostText = $host?->sql() ?? ($this->match(TokenType::MOD) ? $this->prevText() : null);

        if ($user === null || $hostText === null || $hostText === '') {
            return null;
        }

        return new DefinerProperty(['this' => $user->sql().'@'.$hostText]);
    }

    protected function parseWithjournaltable(): WithJournalTableProperty
    {
        $this->match(TokenType::TABLE);
        $this->match(TokenType::EQ);

        return $this->expression(new WithJournalTableProperty(['this' => $this->parseTableParts()]));
    }

    protected function parseLog(bool $no = false): LogProperty
    {
        return $this->expression(new LogProperty(['no' => $no]));
    }

    protected function parseJournal(mixed ...$kwargs): JournalProperty
    {
        /** @var array<string, mixed> $kwargs */
        return $this->expression(new JournalProperty($kwargs));
    }

    protected function parseChecksum(): ChecksumProperty
    {
        $this->match(TokenType::EQ);

        $on = null;

        if ($this->match(TokenType::ON)) {
            $on = true;
        } elseif ($this->matchTextSeq('OFF')) {
            $on = false;
        }

        return $this->expression(new ChecksumProperty(['on' => $on, 'default' => $this->match(TokenType::DEFAULT)]));
    }

    protected function parseCluster(): Cluster
    {
        $this->match(TokenType::CLUSTER_BY);

        return $this->expression(new Cluster(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseColumn())]));
    }

    protected function parseClusterProperty(): ClusterProperty
    {
        return $this->expression(new ClusterProperty(['expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseColumn())]));
    }

    protected function parseClusteredBy(): ClusteredByProperty
    {
        $this->matchTextSeq('BY');

        $this->matchLParen();
        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseColumn());
        $this->matchRParen();

        if ($this->matchTextSeq('SORTED', 'BY')) {
            $this->matchLParen();
            $sortedBy = $this->parseCsv(fn (): ?Expression => $this->parseOrdered());
            $this->matchRParen();
        } else {
            $sortedBy = null;
        }

        $this->match(TokenType::INTO);
        $buckets = $this->parseNumber();
        $this->matchTextSeq('BUCKETS');

        return $this->expression(new ClusteredByProperty(['expressions' => $expressions, 'sorted_by' => $sortedBy, 'buckets' => $buckets]));
    }

    protected function parseCopyProperty(): ?CopyGrantsProperty
    {
        if (! $this->matchTextSeq('GRANTS')) {
            $this->retreat($this->index - 1);

            return null;
        }

        return $this->expression(new CopyGrantsProperty);
    }

    protected function parseFreespace(): FreespaceProperty
    {
        $this->match(TokenType::EQ);

        return $this->expression(new FreespaceProperty(['this' => $this->parseNumber(), 'percent' => $this->match(TokenType::PERCENT)]));
    }

    protected function parseMergeblockratio(bool $no = false, bool $default = false): MergeBlockRatioProperty
    {
        if ($this->match(TokenType::EQ)) {
            return $this->expression(new MergeBlockRatioProperty(['this' => $this->parseNumber(), 'percent' => $this->match(TokenType::PERCENT)]));
        }

        return $this->expression(new MergeBlockRatioProperty(['no' => $no, 'default' => $default]));
    }

    protected function parseDatablocksize(?bool $default = null, ?bool $minimum = null, ?bool $maximum = null): DataBlocksizeProperty
    {
        $this->match(TokenType::EQ);
        $size = $this->parseNumber();

        $units = null;

        if ($this->matchTexts(['BYTES' => true, 'KBYTES' => true, 'KILOBYTES' => true])) {
            $units = $this->prevText();
        }

        return $this->expression(new DataBlocksizeProperty([
            'size' => $size,
            'units' => $units,
            'default' => $default,
            'minimum' => $minimum,
            'maximum' => $maximum,
        ]));
    }

    protected function parseBlockcompression(): BlockCompressionProperty
    {
        $this->match(TokenType::EQ);
        $always = $this->matchTextSeq('ALWAYS');
        $manual = $this->matchTextSeq('MANUAL');
        $never = $this->matchTextSeq('NEVER');
        $default = $this->matchTextSeq('DEFAULT');

        $autotemp = null;

        if ($this->matchTextSeq('AUTOTEMP')) {
            $autotemp = $this->parseSchema();
        }

        return $this->expression(new BlockCompressionProperty([
            'always' => $always,
            'manual' => $manual,
            'never' => $never,
            'default' => $default,
            'autotemp' => $autotemp,
        ]));
    }

    protected function parseWithisolatedloading(): ?IsolatedLoadingProperty
    {
        $index = $this->index;
        $no = $this->matchTextSeq('NO');
        $concurrent = $this->matchTextSeq('CONCURRENT');

        if (! $this->matchTextSeq('ISOLATED', 'LOADING')) {
            $this->retreat($index);

            return null;
        }

        $target = $this->parseVarFromOptions($this->t['ISOLATED_LOADING_OPTIONS'], raiseUnmatched: false);

        return $this->expression(new IsolatedLoadingProperty(['no' => $no, 'concurrent' => $concurrent, 'target' => $target]));
    }

    protected function parseLocking(): LockingProperty
    {
        if ($this->match(TokenType::TABLE)) {
            $kind = 'TABLE';
        } elseif ($this->match(TokenType::VIEW)) {
            $kind = 'VIEW';
        } elseif ($this->match(TokenType::ROW)) {
            $kind = 'ROW';
        } elseif ($this->matchTextSeq('DATABASE')) {
            $kind = 'DATABASE';
        } else {
            $kind = null;
        }

        $this_ = in_array($kind, ['DATABASE', 'TABLE', 'VIEW'], true) ? $this->parseTableParts() : null;

        if ($this->match(TokenType::FOR)) {
            $forOrIn = 'FOR';
        } elseif ($this->match(TokenType::IN)) {
            $forOrIn = 'IN';
        } else {
            $forOrIn = null;
        }

        if ($this->matchTextSeq('ACCESS')) {
            $lockType = 'ACCESS';
        } elseif ($this->matchTexts(['EXCL' => true, 'EXCLUSIVE' => true])) {
            $lockType = 'EXCLUSIVE';
        } elseif ($this->matchTextSeq('SHARE')) {
            $lockType = 'SHARE';
        } elseif ($this->matchTextSeq('READ')) {
            $lockType = 'READ';
        } elseif ($this->matchTextSeq('WRITE')) {
            $lockType = 'WRITE';
        } elseif ($this->matchTextSeq('CHECKSUM')) {
            $lockType = 'CHECKSUM';
        } else {
            $lockType = null;
        }

        $override = $this->matchTextSeq('OVERRIDE');

        return $this->expression(new LockingProperty([
            'this' => $this_,
            'kind' => $kind,
            'for_or_in' => $forOrIn,
            'lock_type' => $lockType,
            'override' => $override,
        ]));
    }

    /**
     * @return list<Expression>
     */
    protected function parsePartitionBy(): array
    {
        if ($this->match(TokenType::PARTITION_BY)) {
            return $this->parseCsv(fn (): ?Expression => $this->parseDisjunction());
        }

        return [];
    }

    protected function parsePartitionBoundSpec(): PartitionBoundSpec
    {
        $parsePartitionBoundExpr = function (): ?Expression {
            if ($this->matchTextSeq('MINVALUE')) {
                return Builder::var('MINVALUE');
            }

            if ($this->matchTextSeq('MAXVALUE')) {
                return Builder::var('MAXVALUE');
            }

            return $this->parseBitwise();
        };

        $this_ = null;
        $expression = null;
        $fromExpressions = null;
        $toExpressions = null;

        if ($this->match(TokenType::IN)) {
            $this_ = $this->parseWrappedCsv(fn (): ?Expression => $this->parseBitwise());
        } elseif ($this->match(TokenType::FROM)) {
            $fromExpressions = $this->parseWrappedCsv($parsePartitionBoundExpr);
            $this->matchTextSeq('TO');
            $toExpressions = $this->parseWrappedCsv($parsePartitionBoundExpr);
        } elseif ($this->matchTextSeq('WITH', '(', 'MODULUS')) {
            $this_ = $this->parseNumber();
            $this->matchTextSeq(',', 'REMAINDER');
            $expression = $this->parseNumber();
            $this->matchRParen();
        } else {
            $this->raiseError('Failed to parse partition bound spec.');
        }

        return $this->expression(new PartitionBoundSpec([
            'this' => $this_,
            'expression' => $expression,
            'from_expressions' => $fromExpressions,
            'to_expressions' => $toExpressions,
        ]));
    }

    // https://www.postgresql.org/docs/current/sql-createtable.html
    protected function parsePartitionedOf(): ?PartitionedOfProperty
    {
        if (! $this->matchTextSeq('OF')) {
            $this->retreat($this->index - 1);

            return null;
        }

        $this_ = $this->parseTable(schema: true);
        $expression = null;

        if ($this->match(TokenType::DEFAULT)) {
            $expression = Builder::var('DEFAULT');
        } elseif ($this->matchTextSeq('FOR', 'VALUES')) {
            $expression = $this->parsePartitionBoundSpec();
        } else {
            $this->raiseError('Expecting either DEFAULT or FOR VALUES clause.');
        }

        return $this->expression(new PartitionedOfProperty(['this' => $this_, 'expression' => $expression]));
    }

    protected function parsePartitionedBy(): PartitionedByProperty
    {
        $this->match(TokenType::EQ);

        return $this->expression(new PartitionedByProperty([
            'this' => $this->parseSchema() ?? $this->parseBracket($this->parseField()),
        ]));
    }

    protected function parseWithdata(bool $no = false): WithDataProperty
    {
        if ($this->matchTextSeq('AND', 'STATISTICS')) {
            $statistics = true;
        } elseif ($this->matchTextSeq('AND', 'NO', 'STATISTICS')) {
            $statistics = false;
        } else {
            $statistics = null;
        }

        return $this->expression(new WithDataProperty(['no' => $no, 'statistics' => $statistics]));
    }

    protected function parseContainsProperty(): ?SqlReadWriteProperty
    {
        if ($this->matchTextSeq('SQL')) {
            return $this->expression(new SqlReadWriteProperty(['this' => 'CONTAINS SQL']));
        }

        return null;
    }

    protected function parseModifiesProperty(): ?SqlReadWriteProperty
    {
        if ($this->matchTextSeq('SQL', 'DATA')) {
            return $this->expression(new SqlReadWriteProperty(['this' => 'MODIFIES SQL DATA']));
        }

        return null;
    }

    protected function parseNoProperty(): ?Expression
    {
        if ($this->matchTextSeq('PRIMARY', 'INDEX')) {
            return new NoPrimaryIndexProperty;
        }

        if ($this->matchTextSeq('SQL')) {
            return $this->expression(new SqlReadWriteProperty(['this' => 'NO SQL']));
        }

        return null;
    }

    protected function parseOnProperty(): ?Expression
    {
        if ($this->matchTextSeq('COMMIT', 'PRESERVE', 'ROWS')) {
            return new OnCommitProperty;
        }

        if ($this->matchTextSeq('COMMIT', 'DELETE', 'ROWS')) {
            return new OnCommitProperty(['delete' => true]);
        }

        return $this->expression(new OnProperty(['this' => $this->parseSchema($this->parseIdVar())]));
    }

    protected function parseReadsProperty(): ?SqlReadWriteProperty
    {
        if ($this->matchTextSeq('SQL', 'DATA')) {
            return $this->expression(new SqlReadWriteProperty(['this' => 'READS SQL DATA']));
        }

        return null;
    }

    protected function parseDistkey(): DistKeyProperty
    {
        return $this->expression(new DistKeyProperty(['this' => $this->parseWrapped(fn (): ?Expression => $this->parseIdVar())]));
    }

    protected function parseCreateLike(): ?LikeProperty
    {
        $table = $this->parseTable(schema: true);

        $options = [];
        while ($this->matchTexts(['INCLUDING' => true, 'EXCLUDING' => true])) {
            $this_ = strtoupper($this->prevText());

            $idVar = $this->parseIdVar();

            if ($idVar === null) {
                return null;
            }

            $options[] = $this->expression(new Property(['this' => $this_, 'value' => Builder::var(strtoupper($idVar->name()))]));
        }

        return $this->expression(new LikeProperty(['this' => $table, 'expressions' => $options]));
    }

    protected function parseSortkey(bool $compound = false): SortKeyProperty
    {
        return $this->expression(new SortKeyProperty(['this' => $this->parseWrappedIdVars(), 'compound' => $compound]));
    }

    protected function parseCharacterSet(bool $default = false): CharacterSetProperty
    {
        $this->match(TokenType::EQ);

        return $this->expression(new CharacterSetProperty(['this' => $this->parseVarOrString(), 'default' => $default]));
    }

    protected function parseRemoteWithConnection(): RemoteWithConnectionModelProperty
    {
        $this->matchTextSeq('WITH', 'CONNECTION');

        return $this->expression(new RemoteWithConnectionModelProperty(['this' => $this->parseTableParts()]));
    }

    protected function parseReturns(): ReturnsProperty
    {
        $null = null;
        $isTable = $this->match(TokenType::TABLE);

        if ($isTable) {
            if ($this->match(TokenType::LT)) {
                $value = $this->expression(new Schema([
                    'this' => 'TABLE',
                    'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseStructTypes()),
                ]));

                if (! $this->match(TokenType::GT)) {
                    $this->raiseError('Expecting >');
                }
            } else {
                $value = $this->parseSchema(Builder::var('TABLE'));
            }
        } elseif ($this->matchTextSeq('NULL', 'ON', 'NULL', 'INPUT')) {
            $null = true;
            $value = null;
        } else {
            $value = $this->parseTypes();
        }

        return $this->expression(new ReturnsProperty(['this' => $value, 'is_table' => $isTable, 'null' => $null]));
    }
}
