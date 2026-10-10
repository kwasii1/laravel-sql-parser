<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Generator\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CurrentDate;
use Kwasii\LaravelSqlParser\Expressions\CurrentTime;
use Kwasii\LaravelSqlParser\Expressions\CurrentTimestamp;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;

/**
 * The base TRANSFORMS table's handlers (sqlglot's generator lambdas), plus the
 * AFTER_HAVING_MODIFIER_TRANSFORMS used by queryModifiers().
 */
trait HandlesTransforms
{
    protected function afterHavingCluster(Expression $e): string
    {
        return $this->sql($e, 'cluster');
    }

    protected function afterHavingDistribute(Expression $e): string
    {
        return $this->sql($e, 'distribute');
    }

    protected function afterHavingSort(Expression $e): string
    {
        return $this->sql($e, 'sort');
    }

    protected function afterHavingWindows(Expression $e): string
    {
        return $e->argList('windows') !== [] ? $this->seg('WINDOW ').$this->expressions($e, key: 'windows', flat: true) : '';
    }

    protected function afterHavingQualify(Expression $e): string
    {
        return $this->sql($e, 'qualify');
    }

    protected function transformJSONPathFilter(Expression $e): string
    {
        return '?'.$this->jsonPathRaw($e->args['this'] ?? null);
    }

    protected function transformJSONPathKey(Expression $e): string
    {
        return $this->jsonpathkeySqlInternal($e);
    }

    protected function transformJSONPathRecursive(Expression $e): string
    {
        return '..'.$this->jsonPathRaw($e->args['this'] ?? null);
    }

    protected function transformJSONPathRoot(Expression $e): string
    {
        return '$';
    }

    protected function transformJSONPathScript(Expression $e): string
    {
        return '('.$this->jsonPathRaw($e->args['this'] ?? null);
    }

    protected function transformJSONPathSelector(Expression $e): string
    {
        return '['.$this->jsonPathPart($e->args['this'] ?? '').']';
    }

    protected function transformJSONPathSlice(Expression $e): string
    {
        $parts = [];

        foreach (['start', 'end', 'step'] as $key) {
            $part = $e->args[$key] ?? null;

            if ($part !== null) {
                $parts[] = $part === false ? '' : $this->jsonPathPart($part);
            }
        }

        return implode(':', $parts);
    }

    protected function transformJSONPathSubscript(Expression $e): string
    {
        return $this->jsonpathsubscriptSqlInternal($e);
    }

    protected function transformJSONPathUnion(Expression $e): string
    {
        $parts = [];

        foreach ($e->args['expressions'] ?? [] as $part) {
            $parts[] = $this->jsonPathPart($part);
        }

        return '['.implode(',', $parts).']';
    }

    protected function transformJSONPathWildcard(Expression $e): string
    {
        return '*';
    }

    /**
     * Python's str() of a JSONPath part's raw value (None renders as '').
     */
    private function jsonPathRaw(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return is_scalar($value) || $value instanceof Expression ? (string) $value : '';
    }

    protected function transformAdjacent(Expression $e): string
    {
        return $this->binary($e, '-|-');
    }

    protected function transformAllowedValuesProperty(Expression $e): string
    {
        return 'ALLOWED_VALUES '.$this->expressions($e, flat: true);
    }

    protected function transformAnalyzeColumns(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    protected function transformAnalyzeWith(Expression $e): string
    {
        return $this->expressions($e, prefix: 'WITH ', sep: ' ');
    }

    protected function transformArrayContainedBy(Expression $e): string
    {
        return $this->binary($e, '<@');
    }

    protected function transformArrayContainsAll(Expression $e): string
    {
        return $this->binary($e, '@>');
    }

    protected function transformArrayOverlaps(Expression $e): string
    {
        return $this->binary($e, '&&');
    }

    protected function transformAssumeColumnConstraint(Expression $e): string
    {
        return 'ASSUME ('.$this->sql($e, 'this').')';
    }

    protected function transformAutoRefreshProperty(Expression $e): string
    {
        return 'AUTO REFRESH '.$this->sql($e, 'this');
    }

    protected function transformBackupProperty(Expression $e): string
    {
        return 'BACKUP '.$this->sql($e, 'this');
    }

    protected function transformBinaryColumnConstraint(Expression $e): string
    {
        return 'BINARY';
    }

    protected function transformCaseSpecificColumnConstraint(Expression $e): string
    {
        return ($e->flag('not_') ? 'NOT ' : '').'CASESPECIFIC';
    }

    protected function transformCalledOnNullInputProperty(Expression $e): string
    {
        return 'CALLED ON NULL INPUT';
    }

    protected function transformCeil(Expression $e): string
    {
        return $this->ceilFloor($e);
    }

    protected function transformCharacterSetColumnConstraint(Expression $e): string
    {
        return 'CHARACTER SET '.$this->sql($e, 'this');
    }

    protected function transformCharacterSetProperty(Expression $e): string
    {
        return ($e->flag('default') ? 'DEFAULT ' : '').'CHARACTER SET='.$this->sql($e, 'this');
    }

    protected function transformClusteredColumnConstraint(Expression $e): string
    {
        return 'CLUSTERED ('.$this->expressions($e, key: 'this', indent: false).')';
    }

    protected function transformCollateColumnConstraint(Expression $e): string
    {
        return 'COLLATE '.$this->sql($e, 'this');
    }

    protected function transformCommentColumnConstraint(Expression $e): string
    {
        return 'COMMENT '.$this->sql($e, 'this');
    }

    protected function transformConnectByRoot(Expression $e): string
    {
        return 'CONNECT_BY_ROOT '.$this->sql($e, 'this');
    }

    protected function transformConvertToCharset(Expression $e): string
    {
        return $this->func('CONVERT', $e->args['this'] ?? null, $e->args['dest'] ?? null, $e->args['source'] ?? null);
    }

    protected function transformCopyGrantsProperty(Expression $e): string
    {
        return 'COPY GRANTS';
    }

    protected function transformCredentialsProperty(Expression $e): string
    {
        return 'CREDENTIALS=('.$this->expressions($e, key: 'expressions', sep: ' ').')';
    }

    protected function transformCurrentCatalog(Expression $e): string
    {
        return 'CURRENT_CATALOG';
    }

    protected function transformSessionUser(Expression $e): string
    {
        return 'SESSION_USER';
    }

    protected function transformDateFormatColumnConstraint(Expression $e): string
    {
        return 'FORMAT '.$this->sql($e, 'this');
    }

    protected function transformDefaultColumnConstraint(Expression $e): string
    {
        return 'DEFAULT '.$this->sql($e, 'this');
    }

    protected function transformApiProperty(Expression $e): string
    {
        return 'API';
    }

    protected function transformApplicationProperty(Expression $e): string
    {
        return 'APPLICATION';
    }

    protected function transformCatalogProperty(Expression $e): string
    {
        return 'CATALOG';
    }

    protected function transformComputeProperty(Expression $e): string
    {
        return 'COMPUTE';
    }

    protected function transformDatabaseProperty(Expression $e): string
    {
        return 'DATABASE';
    }

    protected function transformDynamicProperty(Expression $e): string
    {
        return 'DYNAMIC';
    }

    protected function transformEmptyProperty(Expression $e): string
    {
        return 'EMPTY';
    }

    protected function transformEncodeColumnConstraint(Expression $e): string
    {
        return 'ENCODE '.$this->sql($e, 'this');
    }

    protected function transformEndStatement(Expression $e): string
    {
        return 'END';
    }

    protected function transformEnviromentProperty(Expression $e): string
    {
        return 'ENVIRONMENT ('.$this->expressions($e, flat: true).')';
    }

    protected function transformHandlerProperty(Expression $e): string
    {
        return 'HANDLER '.$this->sql($e, 'this');
    }

    protected function transformParameterStyleProperty(Expression $e): string
    {
        return 'PARAMETER STYLE '.$this->sql($e, 'this');
    }

    protected function transformEphemeralColumnConstraint(Expression $e): string
    {
        return 'EPHEMERAL'.($this->truthy($e->args['this'] ?? null) ? ' '.$this->sql($e, 'this') : '');
    }

    protected function transformExcludeColumnConstraint(Expression $e): string
    {
        return 'EXCLUDE '.ltrim($this->sql($e, 'this'));
    }

    protected function transformExecuteAsProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformExcept(Expression $e): string
    {
        return $e instanceof SetOperation ? $this->setOperations($e) : '';
    }

    protected function transformExternalProperty(Expression $e): string
    {
        return 'EXTERNAL';
    }

    protected function transformFloor(Expression $e): string
    {
        return $this->ceilFloor($e);
    }

    protected function transformGet(Expression $e): string
    {
        return $this->getPutSql($e);
    }

    protected function transformGlobalProperty(Expression $e): string
    {
        return 'GLOBAL';
    }

    protected function transformHeapProperty(Expression $e): string
    {
        return 'HEAP';
    }

    protected function transformHybridProperty(Expression $e): string
    {
        return 'HYBRID';
    }

    protected function transformIcebergProperty(Expression $e): string
    {
        return 'ICEBERG';
    }

    protected function transformInheritsProperty(Expression $e): string
    {
        return 'INHERITS ('.$this->expressions($e, flat: true).')';
    }

    protected function transformInlineLengthColumnConstraint(Expression $e): string
    {
        return 'INLINE LENGTH '.$this->sql($e, 'this');
    }

    protected function transformInputModelProperty(Expression $e): string
    {
        return 'INPUT'.$this->sql($e, 'this');
    }

    protected function transformIntersect(Expression $e): string
    {
        return $e instanceof SetOperation ? $this->setOperations($e) : '';
    }

    protected function transformIntervalSpan(Expression $e): string
    {
        return $this->sql($e, 'this').' TO '.$this->sql($e, 'expression');
    }

    protected function transformInt64(Expression $e): string
    {
        return $this->sql(Builder::cast($e->argExpr('this') ?? Builder::null(), DType::BIGINT));
    }

    protected function transformJSONBContainsAnyTopKeys(Expression $e): string
    {
        return $this->binary($e, '?|');
    }

    protected function transformJSONBContainsAllTopKeys(Expression $e): string
    {
        return $this->binary($e, '?&');
    }

    protected function transformJSONBContainsTopKey(Expression $e): string
    {
        return $this->binary($e, '?');
    }

    protected function transformJSONBDeleteAtPath(Expression $e): string
    {
        return $this->binary($e, '#-');
    }

    protected function transformJSONBPathExists(Expression $e): string
    {
        return $this->binary($e, '@?');
    }

    protected function transformJSONObject(Expression $e): string
    {
        return $this->jsonobjectSqlInternal($e);
    }

    protected function transformJSONObjectAgg(Expression $e): string
    {
        return $this->jsonobjectSqlInternal($e);
    }

    protected function transformLanguageProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformLocationProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformLogProperty(Expression $e): string
    {
        return ($e->flag('no') ? 'NO ' : '').'LOG';
    }

    protected function transformMaskingProperty(Expression $e): string
    {
        return 'MASKING';
    }

    protected function transformMaterializedProperty(Expression $e): string
    {
        return 'MATERIALIZED';
    }

    protected function transformNetFunc(Expression $e): string
    {
        return 'NET.'.$this->sql($e, 'this');
    }

    protected function transformNetworkProperty(Expression $e): string
    {
        return 'NETWORK';
    }

    protected function transformNonClusteredColumnConstraint(Expression $e): string
    {
        return 'NONCLUSTERED ('.$this->expressions($e, key: 'this', indent: false).')';
    }

    protected function transformNoPrimaryIndexProperty(Expression $e): string
    {
        return 'NO PRIMARY INDEX';
    }

    protected function transformNotForReplicationColumnConstraint(Expression $e): string
    {
        return 'NOT FOR REPLICATION';
    }

    protected function transformOnCommitProperty(Expression $e): string
    {
        return 'ON COMMIT '.($e->flag('delete') ? 'DELETE' : 'PRESERVE').' ROWS';
    }

    protected function transformOnProperty(Expression $e): string
    {
        return 'ON '.$this->sql($e, 'this');
    }

    protected function transformOnUpdateColumnConstraint(Expression $e): string
    {
        return 'ON UPDATE '.$this->sql($e, 'this');
    }

    protected function transformOperator(Expression $e): string
    {
        // The operator is produced in binary()
        return $this->binary($e, '');
    }

    protected function transformOutputModelProperty(Expression $e): string
    {
        return 'OUTPUT'.$this->sql($e, 'this');
    }

    protected function transformExtendsLeft(Expression $e): string
    {
        return $this->binary($e, '&<');
    }

    protected function transformExtendsRight(Expression $e): string
    {
        return $this->binary($e, '&>');
    }

    protected function transformPathColumnConstraint(Expression $e): string
    {
        return 'PATH '.$this->sql($e, 'this');
    }

    protected function transformPartitionedByBucket(Expression $e): string
    {
        return $this->func('BUCKET', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformPartitionByTruncate(Expression $e): string
    {
        return $this->func('TRUNCATE', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformPivotAny(Expression $e): string
    {
        return 'ANY'.$this->sql($e, 'this');
    }

    protected function transformPositionalColumn(Expression $e): string
    {
        return '#'.$this->sql($e, 'this');
    }

    protected function transformProjectionPolicyColumnConstraint(Expression $e): string
    {
        return 'PROJECTION POLICY '.$this->sql($e, 'this');
    }

    protected function transformInvisibleColumnConstraint(Expression $e): string
    {
        return 'INVISIBLE';
    }

    protected function transformZeroFillColumnConstraint(Expression $e): string
    {
        return 'ZEROFILL';
    }

    protected function transformPut(Expression $e): string
    {
        return $this->getPutSql($e);
    }

    protected function transformRemoteWithConnectionModelProperty(Expression $e): string
    {
        return 'REMOTE WITH CONNECTION '.$this->sql($e, 'this');
    }

    protected function transformReturnsProperty(Expression $e): string
    {
        return $e->flag('null') ? 'RETURNS NULL ON NULL INPUT' : $this->nakedProperty($e);
    }

    protected function transformRowAccessProperty(Expression $e): string
    {
        return 'ROW ACCESS';
    }

    protected function transformSafeFunc(Expression $e): string
    {
        return 'SAFE.'.$this->sql($e, 'this');
    }

    protected function transformSampleProperty(Expression $e): string
    {
        return 'SAMPLE BY '.$this->sql($e, 'this');
    }

    protected function transformSecureProperty(Expression $e): string
    {
        return 'SECURE';
    }

    protected function transformSecurityIntegrationProperty(Expression $e): string
    {
        return 'SECURITY';
    }

    protected function transformSetConfigProperty(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    protected function transformSetProperty(Expression $e): string
    {
        return ($e->flag('multi') ? 'MULTI' : '').'SET';
    }

    protected function transformSettingsProperty(Expression $e): string
    {
        return 'SETTINGS'.$this->seg('').$this->expressions($e);
    }

    protected function transformSharingProperty(Expression $e): string
    {
        return 'SHARING='.$this->sql($e, 'this');
    }

    protected function transformSqlReadWriteProperty(Expression $e): string
    {
        return $e->name();
    }

    protected function transformSqlSecurityProperty(Expression $e): string
    {
        return 'SQL SECURITY '.$this->sql($e, 'this');
    }

    protected function transformStabilityProperty(Expression $e): string
    {
        return $e->name();
    }

    protected function transformStream(Expression $e): string
    {
        return 'STREAM '.$this->sql($e, 'this');
    }

    protected function transformStreamingTableProperty(Expression $e): string
    {
        return 'STREAMING';
    }

    protected function transformStrictProperty(Expression $e): string
    {
        return 'STRICT';
    }

    protected function transformSwapTable(Expression $e): string
    {
        return 'SWAP WITH '.$this->sql($e, 'this');
    }

    protected function transformTableColumn(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    protected function transformTags(Expression $e): string
    {
        return 'TAG ('.$this->expressions($e, flat: true).')';
    }

    protected function transformTemporaryProperty(Expression $e): string
    {
        return 'TEMPORARY';
    }

    protected function transformTitleColumnConstraint(Expression $e): string
    {
        return 'TITLE '.$this->sql($e, 'this');
    }

    protected function transformToMap(Expression $e): string
    {
        return 'MAP '.$this->sql($e, 'this');
    }

    protected function transformToTableProperty(Expression $e): string
    {
        return 'TO '.$this->sql($e, 'this');
    }

    protected function transformTransformModelProperty(Expression $e): string
    {
        return $this->funcWith('TRANSFORM', $e->argList('expressions'));
    }

    protected function transformTransientProperty(Expression $e): string
    {
        return 'TRANSIENT';
    }

    protected function transformVirtualProperty(Expression $e): string
    {
        return 'VIRTUAL';
    }

    protected function transformTriggerExecute(Expression $e): string
    {
        return 'EXECUTE FUNCTION '.$this->sql($e, 'this');
    }

    protected function transformUnion(Expression $e): string
    {
        return $e instanceof SetOperation ? $this->setOperations($e) : '';
    }

    protected function transformUnloggedProperty(Expression $e): string
    {
        return 'UNLOGGED';
    }

    protected function transformUsingTemplateProperty(Expression $e): string
    {
        return 'USING TEMPLATE '.$this->sql($e, 'this');
    }

    protected function transformUsingData(Expression $e): string
    {
        return 'USING DATA '.$this->sql($e, 'this');
    }

    protected function transformUppercaseColumnConstraint(Expression $e): string
    {
        return 'UPPERCASE';
    }

    protected function transformUtcDate(Expression $e): string
    {
        return $this->sql(new CurrentDate(['this' => Literal::string('UTC')]));
    }

    protected function transformUtcTime(Expression $e): string
    {
        return $this->sql(new CurrentTime(['this' => Literal::string('UTC')]));
    }

    protected function transformUtcTimestamp(Expression $e): string
    {
        return $this->sql(new CurrentTimestamp(['this' => Literal::string('UTC')]));
    }

    protected function transformVariadic(Expression $e): string
    {
        return 'VARIADIC '.$this->sql($e, 'this');
    }

    protected function transformVarMap(Expression $e): string
    {
        return $this->func('MAP', $e->args['keys'] ?? null, $e->args['values'] ?? null);
    }

    protected function transformViewAttributeProperty(Expression $e): string
    {
        return 'WITH '.$this->sql($e, 'this');
    }

    protected function transformVolatileProperty(Expression $e): string
    {
        return 'VOLATILE';
    }

    protected function transformWithJournalTableProperty(Expression $e): string
    {
        return 'WITH JOURNAL TABLE='.$this->sql($e, 'this');
    }

    protected function transformWithProcedureOptions(Expression $e): string
    {
        return 'WITH '.$this->expressions($e, flat: true);
    }

    protected function transformWithSchemaBindingProperty(Expression $e): string
    {
        return 'WITH SCHEMA '.$this->sql($e, 'this');
    }

    protected function transformWithOperator(Expression $e): string
    {
        return $this->sql($e, 'this').' WITH '.$this->sql($e, 'op');
    }

    protected function transformForceProperty(Expression $e): string
    {
        return 'FORCE';
    }
}
