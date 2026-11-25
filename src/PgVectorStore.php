<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\HasDocumentSchema;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PDO;

/**
 * PostgreSQL pgvector adapter for Neuron AI, model-agnostic.
 *
 * One generic documents table holds the vectors of several embedding models
 * side by side: id, content, source_type, source_name, metadata JSONB, model
 * and a dimensionless column `vector` of type `vector`. Every row is labelled
 * with the model that produced its vector, and every model gets its own
 * partial HNSW index on `(vector::vector(N))` restricted to
 * `WHERE model = '<model>'` ({@see ensureModelIndex()}, named by
 * {@see modelIndexName()}).
 *
 * Reads and writes go through a scoped instance: {@see forModel()} returns an
 * immutable clone bound to one model and its dimension. Scoped searches cast
 * the column exactly like the index expression and filter by the model as a
 * literal, so the planner can use that model's partial index. An unscoped
 * instance can only set up the table and delete rows across all models.
 *
 * Model names and dimensions end up in SQL as literals (index expression and
 * partial predicate cannot take bound parameters), so both are validated:
 * the model must match {@see MODEL_PATTERN} and be at most
 * {@see MAX_MODEL_LENGTH} characters, the dimension must be within
 * 1..{@see MAX_DIMENSION} (the HNSW limit for `vector`).
 *
 * Similarity search uses the distance operator of the configured
 * {@see Distance} (cosine `<=>` by default) and an index with the matching
 * opclass. Requires the pgvector extension, PostgreSQL >= 13; partial
 * expression indexes with iterative filtered scans need pgvector >= 0.8.
 *
 * Keyword search ({@see keywordSearch()}) is PostgreSQL full-text search on
 * `content`, one text search configuration per language from
 * {@see KEYWORD_LANGUAGES}. Every language has its own GIN expression index
 * on `to_tsvector('<language>'::regconfig, coalesce(content, ''))`
 * ({@see ensureKeywordIndex()}, named by {@see keywordIndexName()}); there
 * is no tsvector column. Like the model, the language ends up in SQL as a
 * literal and is validated against the allow-list.
 *
 * Filters (neuron-ai 4.x): every metadata key a filter names has to be
 * declared filterable in the {@see DocumentSchema} passed to the
 * constructor; `sourceType`, `sourceName` and the store column `model`
 * (declared by the store itself, {@see MODEL_FIELD}) are always filterable.
 * Portable filters ({@see Filter}, {@see FilterGroup}) reach the store
 * through {@see search()} and {@see delete()}; equality on a metadata key
 * compiles to JSONB containment, so the GIN index on `metadata` applies. A
 * raw filter must be tagged with this class
 * (`Filter::raw(PgVectorStore::class, '<SQL condition>')`) and is inlined as
 * it is, so it must never carry request input. The own scoping API
 * ({@see forModel()}, {@see withFilters()}, {@see withTopK()}) stays: a
 * scope from withFilters() is validated against the same schema and ANDed
 * with the filters of each request.
 */
class PgVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    /** Store column `model`, declared filterable in every schema of this store. */
    public const MODEL_FIELD = 'model';

    public const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/';

    public const MAX_MODEL_LENGTH = 190;

    public const MAX_DIMENSION = 2000;

    /** Text search configurations keywordSearch() and the FTS indexes support. */
    public const KEYWORD_LANGUAGES = ['german', 'english'];

    /** Metadata keys that are never copied from the row's metadata ({@see DocumentSchema::RESERVED_FIELDS} plus the store column `model`). */
    private const RESERVED_METADATA_KEYS = [...DocumentSchema::RESERVED_FIELDS, self::MODEL_FIELD];

    /** Columns that filters on these document fields compare directly. */
    private const FILTER_COLUMNS = ['sourceType' => 'source_type', 'sourceName' => 'source_name', self::MODEL_FIELD => 'model'];

    /**
     * Metadata scope of {@see withFilters()}, JSONB containment.
     *
     * @var array<string, scalar>
     */
    protected array $filters = [];

    /** Model this instance is scoped to; null = unscoped. */
    protected ?string $model = null;

    /** Vector dimension of the scoped model. */
    protected ?int $dimension = null;

    /** Index access method for the per-model indexes (set by setupTable()). */
    protected string $indexMethod = 'hnsw';

    public function __construct(
        protected PDO $pdo,
        protected string $tableName = 'neuron_documents',
        protected int $topK = 4,
        protected Distance $distance = Distance::Cosine,
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema(self::withModelField($schema ?? DocumentSchema::default()));
    }

    /**
     * Create the extension, the dimensionless table and the non-vector
     * indexes (GIN on metadata, source, one full-text GIN index per
     * {@see KEYWORD_LANGUAGES}). Vector indexes are per model and
     * created by {@see ensureModelIndex()}; $indexMethod is remembered for
     * them. Applications that manage their schema with Doctrine migrations
     * should mirror these statements there (see Doctrine\VectorType) and
     * keep this method for standalone use.
     */
    public function setupTable(string $indexMethod = 'hnsw'): void
    {
        $this->indexMethod = self::assertValidIndexMethod($indexMethod);

        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        $this->pdo->exec(sprintf(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS %1$s (
                    id UUID NOT NULL PRIMARY KEY,
                    content TEXT,
                    source_type VARCHAR(255),
                    source_name VARCHAR(255),
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    model VARCHAR(190) NOT NULL,
                    vector vector NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_%1$s_metadata ON %1$s USING gin (metadata);
                CREATE INDEX IF NOT EXISTS idx_%1$s_source ON %1$s (source_type, source_name);
                SQL,
            $this->tableName,
        ));

        foreach (self::KEYWORD_LANGUAGES as $language) {
            $this->ensureKeywordIndex($language);
        }
    }

    public function dropTable(): void
    {
        $this->pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $this->tableName));
    }

    /**
     * Creates the partial vector index of $model if it does not exist yet:
     * `USING hnsw ((vector::vector(<dimension>)) <opclass>) WHERE model = '<model>'`.
     */
    public function ensureModelIndex(string $model, int $dimension): void
    {
        self::assertValidModel($model);
        self::assertValidDimension($dimension);

        $this->pdo->exec(sprintf(
            "CREATE INDEX IF NOT EXISTS %s ON %s USING %s ((vector::vector(%d)) %s) WHERE model = '%s'",
            $this->modelIndexName($model),
            $this->tableName,
            $this->indexMethod,
            $dimension,
            $this->distance->indexOpclass(),
            $model,
        ));
    }

    /**
     * Name of the partial vector index of $model:
     * `idx_<table>_vector_` + the first 12 hex characters of sha1($model).
     * Model names may contain characters that are not valid in identifiers
     * (`:`, `.`, `/`), and PostgreSQL truncates identifiers at 63 characters.
     */
    public function modelIndexName(string $model): string
    {
        return sprintf('idx_%s_vector_%s', $this->tableName, substr(sha1($model), 0, 12));
    }

    /**
     * Whether the partial vector index of $model exists (looked up by
     * {@see modelIndexName()} on the current search_path).
     */
    public function modelIndexExists(string $model): bool
    {
        $stmt = $this->pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
        $stmt->execute([':name' => $this->modelIndexName($model)]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Creates the full-text index of $language if it does not exist yet:
     * `USING gin (to_tsvector('<language>'::regconfig, coalesce(content, '')))`.
     * The expression is exactly the one keywordSearchSql() matches against.
     *
     * @throws \InvalidArgumentException when $language is not in {@see KEYWORD_LANGUAGES}
     */
    public function ensureKeywordIndex(string $language): void
    {
        $this->pdo->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING gin (%s)',
            $this->keywordIndexName($language),
            $this->tableName,
            self::keywordVectorExpression($language),
        ));
    }

    /**
     * Name of the full-text index of $language: `idx_<table>_fts_<language>`.
     *
     * @throws \InvalidArgumentException when $language is not in {@see KEYWORD_LANGUAGES}
     */
    public function keywordIndexName(string $language): string
    {
        self::assertValidKeywordLanguage($language);

        return sprintf('idx_%s_fts_%s', $this->tableName, $language);
    }

    /**
     * Returns a copy scoped to one embedding model; reads and writes of the
     * copy only see rows of $model and require vectors of $dimension values.
     */
    public function forModel(string $model, int $dimension): static
    {
        self::assertValidModel($model);
        self::assertValidDimension($dimension);

        $clone = clone $this;
        $clone->model = $model;
        $clone->dimension = $dimension;

        return $clone;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getDimension(): ?int
    {
        return $this->dimension;
    }

    /**
     * Returns a copy whose search() and keywordSearch() are restricted to documents
     * whose metadata contains the given key/value pairs (JSONB containment,
     * uses the GIN index). The original instance is not changed. Every key
     * has to be declared filterable in the schema, and the values must
     * match the declared types (as for `Filter::where($key, $value)`).
     *
     * @param array<string, scalar> $filters
     *
     * @throws VectorStoreException for an undeclared key, a value of the wrong type or a null value
     */
    public function withFilters(array $filters): static
    {
        $this->validateMetadataFilters($filters);

        $clone = clone $this;
        $clone->filters = $filters;

        return $clone;
    }

    /**
     * Returns a copy with another result limit; the original instance is
     * not changed.
     */
    public function withTopK(int $topK): static
    {
        $clone = clone $this;
        $clone->topK = $topK;

        return $clone;
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * Upserts documents (by id) as rows of the scoped model.
     *
     * @throws \LogicException           when the instance is not scoped to a model
     * @throws \InvalidArgumentException when a vector has the wrong length
     * @throws VectorStoreException      for a document without embedding or with metadata the schema rejects
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        [$model, $dimension] = $this->requireScope(__FUNCTION__);

        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

        $stmt = $this->pdo->prepare(sprintf(
            <<<'SQL'
                INSERT INTO %s (id, content, source_type, source_name, metadata, model, vector)
                VALUES (:id, :content, :sourceType, :sourceName, :metadata, :model, :vector)
                ON CONFLICT (id) DO UPDATE SET
                    content = EXCLUDED.content,
                    source_type = EXCLUDED.source_type,
                    source_name = EXCLUDED.source_name,
                    metadata = EXCLUDED.metadata,
                    model = EXCLUDED.model,
                    vector = EXCLUDED.vector
                SQL,
            $this->tableName,
        ));

        foreach ($documents as $document) {
            /** @var float[] $vector validateDocuments() refused documents without embedding */
            $vector = $document->getEmbedding();
            $this->assertVectorLength($vector, $dimension);

            $stmt->execute([
                ':id' => $document->getId(),
                ':content' => $document->getContent(),
                ':sourceType' => $document->getSourceType(),
                ':sourceName' => $document->getSourceName(),
                ':metadata' => json_encode($document->getMetadata(), JSON_THROW_ON_ERROR),
                ':model' => $model,
                ':vector' => self::vectorToLiteral($vector),
            ]);
        }

        return $this;
    }

    /**
     * Deletes every row matching $filters: only the rows of the scoped model
     * on a scoped instance, the rows of all models on an unscoped one (as
     * {@see deleteBy()}).
     *
     * @throws VectorStoreException for a filter the schema rejects or a raw filter of another store
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);

        $params = [];
        $conditions = [$this->compileFilters($filters, $params)];
        if ($this->model !== null) {
            $conditions[] = 'model = :model';
            $params[':model'] = $this->model;
        }

        $this->pdo->prepare(sprintf('DELETE FROM %s WHERE %s', $this->tableName, implode(' AND ', $conditions)))
            ->execute($params);

        return $this;
    }

    /**
     * Deletes a source group: only the rows of the scoped model on a scoped
     * instance, the rows of all models on an unscoped one. Shorthand for
     * {@see delete()} with `sourceType` (and `sourceName`).
     */
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        return $this->delete($sourceName === null
            ? Filter::eq('sourceType', $sourceType)
            : Filter::where('sourceType', $sourceType)->where('sourceName', $sourceName));
    }

    /**
     * Deletes every row of $model, optionally only those whose metadata
     * contains $filters (JSONB containment). Works on scoped and unscoped
     * instances alike. The keys of $filters have to be declared filterable
     * in the schema (see {@see withFilters()}).
     *
     * @param array<string, scalar> $filters
     */
    public function deleteByModel(string $model, array $filters = []): void
    {
        self::assertValidModel($model);
        $this->validateMetadataFilters($filters);

        $sql = sprintf('DELETE FROM %s WHERE model = :model', $this->tableName);
        $params = [':model' => $model];
        if ($filters !== []) {
            $sql .= ' AND metadata @> :filters';
            $params[':filters'] = json_encode($filters, JSON_THROW_ON_ERROR);
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Number of rows of the scoped model for one source group.
     *
     * @throws \LogicException when the instance is not scoped to a model
     */
    public function countBy(string $sourceType, string $sourceName): int
    {
        [$model] = $this->requireScope(__FUNCTION__);

        $stmt = $this->pdo->prepare(sprintf(
            'SELECT count(*) FROM %s WHERE model = :model AND source_type = :sourceType AND source_name = :sourceName',
            $this->tableName,
        ));
        $stmt->execute([':model' => $model, ':sourceType' => $sourceType, ':sourceName' => $sourceName]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Nearest rows of the scoped model, at most the request's topK (the
     * store's topK without one), restricted by the scope of
     * {@see withFilters()} and the request's filters. Every returned
     * document carries the model under the metadata key "model".
     *
     * @return iterable<Document>
     *
     * @throws \LogicException           when the instance is not scoped to a model
     * @throws \InvalidArgumentException when the query vector has the wrong length
     * @throws VectorStoreException      for a filter the schema rejects or a raw filter of another store
     */
    public function search(SearchRequest $request): iterable
    {
        [, $dimension] = $this->requireScope(__FUNCTION__);
        $this->assertVectorLength($request->embedding, $dimension);

        // The HNSW index hands out hnsw.ef_search (default 40) candidates before the filters apply: when they
        // all belong to other collections, the search finds nothing. Iterative scans keep scanning instead.
        if ('hnsw' === $this->indexMethod) {
            $this->pdo->exec('SET hnsw.iterative_scan = strict_order');
        }

        $stmt = $this->pdo->prepare($this->searchSql($request));
        $stmt->execute($this->searchParameters($request));

        $documents = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $documents[] = $this->documentFromRow($row, VectorSimilarity::similarityFromDistance((float) $row['distance']));
        }

        return $documents;
    }

    /**
     * The statement search() prepares for the current scope, filters and
     * topK and for the filters and topK of $request, with the placeholders
     * :vector, (with a scope) :filters and (with request filters) :f0, :f1,
     * .... The column cast equals the index expression and the model is an
     * inlined literal, so the model's partial index qualifies. Exposed for
     * EXPLAIN-based tests.
     *
     * @throws \LogicException      when the instance is not scoped to a model
     * @throws VectorStoreException for a filter the schema rejects or a raw filter of another store
     */
    public function searchSql(SearchRequest $request): string
    {
        [$model, $dimension] = $this->requireScope(__FUNCTION__);
        $bindings = [];

        return sprintf(
            <<<'SQL'
                SELECT id, content, source_type, source_name, metadata, model,
                       (vector::vector(%1$d)) %2$s :vector::vector(%1$d) AS distance
                FROM %3$s
                WHERE model = '%4$s'%5$s%6$s
                ORDER BY distance ASC
                LIMIT %7$d
                SQL,
            $dimension,
            $this->distance->operator(),
            $this->tableName,
            $model,
            $this->filters !== [] ? ' AND metadata @> :filters' : '',
            $this->requestCondition($request, $bindings),
            $request->topK ?? $this->topK,
        );
    }

    /**
     * Parameters for {@see searchSql()}.
     *
     * @return array<string, scalar>
     */
    public function searchParameters(SearchRequest $request): array
    {
        $params = [':vector' => self::vectorToLiteral($request->embedding)];
        if ($this->filters !== []) {
            $params[':filters'] = json_encode($this->filters, JSON_THROW_ON_ERROR);
        }
        $bindings = [];
        $this->requestCondition($request, $bindings);

        return $params + $bindings;
    }

    /**
     * Full-text matches of the scoped model in the text search configuration
     * $language, at most topK, restricted by the metadata filters. The terms
     * of $query are ORed (any term matches), so a natural-language question
     * finds the rows that share some of its words. Ranked by ts_rank_cd
     * descending, ties by id ascending; the score is the ts_rank_cd value.
     * A query without lexemes (empty, only stop words) yields no rows. Every
     * returned document carries the model under the metadata key "model".
     *
     * @return iterable<Document>
     *
     * @throws \LogicException           when the instance is not scoped to a model
     * @throws \InvalidArgumentException when $language is not in {@see KEYWORD_LANGUAGES}
     */
    public function keywordSearch(string $query, string $language): iterable
    {
        $this->requireScope(__FUNCTION__);

        $stmt = $this->pdo->prepare($this->keywordSearchSql($language));
        $stmt->execute($this->keywordSearchParameters($query));

        $documents = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $documents[] = $this->documentFromRow($row, (float) $row['rank']);
        }

        return $documents;
    }

    /**
     * The statement keywordSearch() prepares for the current scope, filters
     * and topK, with the placeholders :query and (with filters) :filters.
     *
     * The query is parsed with plainto_tsquery(), whose AND operators (and
     * phrase operators of compound words) are replaced by OR; the result is
     * cast to tsquery as-is, so the lexemes are not normalised twice. The
     * match uses exactly the index expression of {@see ensureKeywordIndex()}
     * and the model is an inlined literal, as in searchSql().
     * Exposed for EXPLAIN-based tests.
     *
     * @throws \LogicException           when the instance is not scoped to a model
     * @throws \InvalidArgumentException when $language is not in {@see KEYWORD_LANGUAGES}
     */
    public function keywordSearchSql(string $language): string
    {
        [$model] = $this->requireScope(__FUNCTION__);
        self::assertValidKeywordLanguage($language);

        return sprintf(
            <<<'SQL'
                SELECT id, content, source_type, source_name, metadata, model,
                       ts_rank_cd(%1$s, q.query) AS rank
                FROM %2$s,
                     (SELECT regexp_replace(plainto_tsquery('%3$s'::regconfig, :query)::text, ' (&|<->|<[0-9]+>) ', ' | ', 'g')::tsquery AS query) q
                WHERE model = '%4$s' AND %1$s @@ q.query%5$s
                ORDER BY rank DESC, id ASC
                LIMIT %6$d
                SQL,
            self::keywordVectorExpression($language),
            $this->tableName,
            $language,
            $model,
            $this->filters !== [] ? ' AND metadata @> :filters' : '',
            $this->topK,
        );
    }

    /**
     * Parameters for {@see keywordSearchSql()}.
     *
     * @return array<string, string>
     */
    public function keywordSearchParameters(string $query): array
    {
        $params = [':query' => $query];
        if ($this->filters !== []) {
            $params[':filters'] = json_encode($this->filters, JSON_THROW_ON_ERROR);
        }

        return $params;
    }

    /**
     * Builds a result document from a search row (id, content, source_type,
     * source_name, metadata, model).
     *
     * @param array<string, mixed> $row
     */
    protected function documentFromRow(array $row, float $score): Document
    {
        return self::hydrate($row)->setScore($score);
    }

    /**
     * A document from a row of the documents table (id, content,
     * source_type, source_name, metadata, model), without score. The row's
     * model goes into the metadata key "model"; metadata keys reserved by
     * neuron-ai ({@see DocumentSchema::RESERVED_FIELDS}) and a stored
     * "model" key are skipped. Shared with readers of the table outside the
     * store, so every reader builds the same document.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Document
    {
        $document = (new Document((string) $row['content']))
            ->setId((string) $row['id'])
            ->setSourceType((string) $row['source_type'])
            ->setSourceName((string) $row['source_name']);

        $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
        if (is_array($metadata)) {
            foreach ($metadata as $key => $value) {
                if (is_string($key) && $key !== '' && !in_array($key, self::RESERVED_METADATA_KEYS, true)) {
                    $document->addMetadata($key, $value);
                }
            }
        }

        return $document->addMetadata(self::MODEL_FIELD, (string) $row['model']);
    }

    /**
     * The SQL condition of the request's filters, ` AND (...)`, or an empty
     * string without filters; collects the bound values in $bindings
     * (start with an empty array, the placeholders are numbered by it).
     *
     * @param array<string, scalar> $bindings
     */
    protected function requestCondition(SearchRequest $request, array &$bindings): string
    {
        if ($request->filters === null) {
            return '';
        }
        $this->validateFilters($request->filters);

        return ' AND '.$this->compileFilters($request->filters, $bindings);
    }

    /**
     * Compiles a validated filter expression to a parenthesised SQL
     * condition with the placeholders :f0, :f1, ... (numbered by the
     * entries already in $params, so pass the bindings of this compilation
     * only and merge other parameters afterwards).
     *
     * - `sourceType`, `sourceName` and `model` compare their columns;
     * - eq, in, containsAny and containsAll on a metadata key are JSONB
     *   containment (GIN index), neq is its negation (the field is
     *   required, so it is present);
     * - gt, gte, lt and lte compare the numeric metadata value;
     * - a raw filter tagged with this class is inlined as it is.
     *
     * @param array<string, scalar> $params
     *
     * @throws VectorStoreException for a raw filter of another store or an expression of an unknown class
     */
    protected function compileFilters(FilterExpression $filters, array &$params): string
    {
        if ($filters instanceof RawFilter) {
            if ($filters->store !== self::class || !is_string($filters->fragment) || trim($filters->fragment) === '') {
                throw new VectorStoreException(sprintf('Raw filter targets %s; it cannot be compiled for %s (a non-empty SQL condition tagged with %s is expected).', $filters->store, static::class, self::class));
            }

            return '('.$filters->fragment.')';
        }

        if ($filters instanceof FilterGroup) {
            $separator = $filters->operator() === FilterCombinator::And ? ' AND ' : ' OR ';
            $clauses = [];
            foreach ($filters->conditions() as $condition) {
                $clauses[] = $this->compileFilters($condition, $params);
            }

            return '('.implode($separator, $clauses).')';
        }

        if (!$filters instanceof Filter) {
            throw new VectorStoreException('Unsupported filter expression: '.$filters::class.'.');
        }

        $bind = static function (mixed $value) use (&$params): string {
            $placeholder = ':f'.count($params);
            $params[$placeholder] = is_bool($value) ? ($value ? 'true' : 'false') : $value;

            return $placeholder;
        };

        $column = self::FILTER_COLUMNS[$filters->field] ?? null;
        if ($column !== null) {
            return match ($filters->operator) {
                FilterOperator::Eq => sprintf('(%s = %s)', $column, $bind($filters->value)),
                FilterOperator::Neq => sprintf('(%s <> %s)', $column, $bind($filters->value)),
                FilterOperator::In => sprintf('(%s IN (%s))', $column, implode(', ', array_map($bind, (array) $filters->value))),
                default => throw new VectorStoreException(sprintf('Filter "%s" is not supported on field "%s".', $filters->operator->value, $filters->field)),
            };
        }

        $field = $filters->field;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) {
            throw new VectorStoreException(sprintf('Metadata field "%s" is not a valid identifier for a SQL filter.', $field));
        }
        $contains = static fn (mixed $value): string => sprintf('metadata @> %s::jsonb', $bind(json_encode([$field => $value], JSON_THROW_ON_ERROR)));
        $numeric = sprintf("(CASE WHEN jsonb_typeof(metadata->'%1\$s') = 'number' THEN (metadata->>'%1\$s')::numeric END)", $field);

        return match ($filters->operator) {
            FilterOperator::Eq => '('.$contains($filters->value).')',
            FilterOperator::Neq => '(NOT '.$contains($filters->value).')',
            FilterOperator::In, FilterOperator::ContainsAny => '('.implode(' OR ', array_map(
                $filters->operator === FilterOperator::In ? $contains : static fn (mixed $value): string => $contains([$value]),
                (array) $filters->value,
            )).')',
            FilterOperator::ContainsAll => '('.$contains(array_values((array) $filters->value)).')',
            FilterOperator::Gt => sprintf('(%s > %s)', $numeric, $bind($filters->value)),
            FilterOperator::Gte => sprintf('(%s >= %s)', $numeric, $bind($filters->value)),
            FilterOperator::Lt => sprintf('(%s < %s)', $numeric, $bind($filters->value)),
            FilterOperator::Lte => sprintf('(%s <= %s)', $numeric, $bind($filters->value)),
        };
    }

    /**
     * Validates a metadata containment scope ({@see withFilters()},
     * {@see deleteByModel()}) like the equality filter it stands for.
     *
     * @param array<string, scalar> $filters
     *
     * @throws VectorStoreException for an undeclared key, a value of the wrong type or a null value
     */
    protected function validateMetadataFilters(array $filters): void
    {
        $conditions = [];
        foreach ($filters as $key => $value) {
            if (!is_scalar($value)) {
                throw new VectorStoreException(sprintf('Metadata filter "%s" needs a scalar value; %s given.', $key, get_debug_type($value)));
            }
            $conditions[] = Filter::eq((string) $key, $value);
        }

        if ($conditions !== []) {
            $this->validateFilters(FilterGroup::allOf(...$conditions));
        }
    }

    /**
     * $schema with the store column `model` declared as a filterable string
     * (unless the schema declares it itself).
     */
    protected static function withModelField(DocumentSchema $schema): DocumentSchema
    {
        if ($schema->getField(self::MODEL_FIELD) !== null) {
            return $schema;
        }

        return DocumentSchema::of(...[...$schema->fields(), DocumentField::string(self::MODEL_FIELD)->filterable()]);
    }

    /**
     * The indexed full-text expression of $language:
     * `to_tsvector('<language>'::regconfig, coalesce(content, ''))`.
     *
     * @throws \InvalidArgumentException when $language is not in {@see KEYWORD_LANGUAGES}
     */
    protected static function keywordVectorExpression(string $language): string
    {
        self::assertValidKeywordLanguage($language);

        return sprintf("to_tsvector('%s'::regconfig, coalesce(content, ''))", $language);
    }

    /**
     * @param float[] $vector
     */
    protected static function vectorToLiteral(array $vector): string
    {
        return '['.implode(',', array_map(floatval(...), $vector)).']';
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected static function assertValidModel(string $model): void
    {
        if (strlen($model) > self::MAX_MODEL_LENGTH || preg_match(self::MODEL_PATTERN, $model) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid embedding model name "%s": expected at most %d characters matching %s.', $model, self::MAX_MODEL_LENGTH, self::MODEL_PATTERN));
        }
    }

    /**
     * Rejects every language that is not in {@see KEYWORD_LANGUAGES}; the
     * language is written into SQL as a literal.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidKeywordLanguage(string $language): void
    {
        if (!in_array($language, self::KEYWORD_LANGUAGES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid keyword search language "%s": expected one of %s.', $language, implode(', ', self::KEYWORD_LANGUAGES)));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected static function assertValidDimension(int $dimension): void
    {
        if ($dimension < 1 || $dimension > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException(sprintf('Invalid vector dimension %d: expected 1..%d.', $dimension, self::MAX_DIMENSION));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected static function assertValidIndexMethod(string $indexMethod): string
    {
        if (preg_match('/^[a-z_]+$/', $indexMethod) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid index method "%s".', $indexMethod));
        }

        return $indexMethod;
    }

    /**
     * @param float[] $vector
     *
     * @throws \InvalidArgumentException
     */
    protected function assertVectorLength(array $vector, int $dimension): void
    {
        if (\count($vector) !== $dimension) {
            throw new \InvalidArgumentException(sprintf('Vector has %d values, but model "%s" expects %d.', \count($vector), $this->model, $dimension));
        }
    }

    /**
     * @return array{string, int} model and dimension of the scope
     *
     * @throws \LogicException when the instance is not scoped to a model
     */
    protected function requireScope(string $operation): array
    {
        if ($this->model === null || $this->dimension === null) {
            throw new \LogicException(sprintf('%s::%s() needs an instance scoped to an embedding model; call forModel() first.', static::class, $operation));
        }

        return [$this->model, $this->dimension];
    }
}
