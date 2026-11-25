<!-- file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC] -->

# dmstr/neuron-pgvector-store

PostgreSQL [pgvector](https://github.com/pgvector/pgvector) vector store adapter for the [Neuron AI](https://github.com/neuron-core/neuron-ai) PHP framework.

The store is model-agnostic: one generic documents table holds the vectors of several embedding models with different dimensions side by side. Every row carries its vector in the dimensionless column `vector` and the name of the model that produced it in the column `model`, and every model gets its own partial HNSW index. Writes are upserts by id, metadata is filtered by JSONB containment (through the neuron-ai 4.x filter API and a document schema, see below), and similarity search uses the distance operator of the configured `Distance` (cosine `<=>` by default; see below). Keyword search is PostgreSQL full-text search on `content`, with one GIN expression index per supported language; there is no tsvector column, so the write path is the same with or without keyword search.

## Requirements

- PHP >= 8.5, `ext-pdo` with the `pdo_pgsql` driver
- PostgreSQL >= 13 with the [pgvector](https://github.com/pgvector/pgvector) extension `vector` >= 0.8 (e.g. the Docker image `pgvector/pgvector:0.8.6-pg16`); the per-model partial expression indexes and iterative filtered index scans need pgvector 0.8
- `neuron-core/neuron-ai` `^4.1`
- optional: `doctrine/dbal`, for the [Doctrine type](#doctrine-dbal-type)

## Installation

```bash
composer require dmstr/neuron-pgvector-store
```

The package is a plain library: there is nothing to register. Create the store with a `PDO` connection to PostgreSQL and pass it to neuron-ai wherever a `VectorStoreInterface` is expected (for example from the `vectorStore()` method of a RAG agent), scoped with `forModel()` to the embedding model and dimension the agent uses.

The database user needs the right to create the extension, or the extension has to exist already: `setupTable()` runs `CREATE EXTENSION IF NOT EXISTS vector`.

## Schema

`setupTable()` creates:

| Column | Type |
| --- | --- |
| `id` | `UUID PRIMARY KEY` |
| `content` | `TEXT` |
| `source_type`, `source_name` | `VARCHAR(255)` |
| `metadata` | `JSONB NOT NULL DEFAULT '{}'` |
| `model` | `VARCHAR(190) NOT NULL` |
| `vector` | `vector NOT NULL` (no dimension) |

plus a GIN index on `metadata` (`idx_<table>_metadata`), an index on `(source_type, source_name)` (`idx_<table>_source`) and one full-text index per keyword language (see below). It creates no vector index: those are per model. `setupTable(string $indexMethod = 'hnsw')` remembers the index access method (validated against `^[a-z_]+$`) for `ensureModelIndex()`.

`ensureModelIndex($model, $dimension)` creates the partial index of one model:

```sql
CREATE INDEX IF NOT EXISTS idx_<table>_vector_<hash>
    ON <table> USING hnsw ((vector::vector(<dimension>)) vector_cosine_ops)
    WHERE model = '<model>'
```

The opclass follows the store's `Distance`, which is the third constructor option after `topK` (`new PgVectorStore($pdo, 'neuron_documents', 4, Distance::L2)`):

| `Distance` | Operator | Opclass |
| --- | --- | --- |
| `Cosine` (default) | `<=>` | `vector_cosine_ops` |
| `InnerProduct` | `<#>` | `vector_ip_ops` |
| `L1` | `<+>` | `vector_l1_ops` |
| `L2` | `<->` | `vector_l2_ops` |

`<hash>` is the first 12 hex characters of `sha1($model)`, because model names may contain characters such as `:`, `.` and `/` and PostgreSQL identifiers are limited to 63 characters. `modelIndexName($model)` returns the name and `modelIndexExists($model)` checks whether the index exists; for a table `embedding` the index of `nomic-embed-text` is `idx_embedding_vector_6f5738e62b5b` and that of `mxbai-embed-large` `idx_embedding_vector_d281fab09939`.

`ensureKeywordIndex($language)` creates the full-text index of one language; `setupTable()` calls it for every language of `PgVectorStore::KEYWORD_LANGUAGES` (`german`, `english`):

```sql
CREATE INDEX IF NOT EXISTS idx_<table>_fts_<language>
    ON <table> USING gin (to_tsvector('<language>'::regconfig, coalesce(content, '')))
```

`keywordIndexName($language)` returns the name. The index covers the rows of all models; the model is a filter of the query, not of the index. An application that manages the schema through its own migrations mirrors these statements there (for a table `embedding`: `idx_embedding_fts_german` and `idx_embedding_fts_english` with exactly this expression; with `IF NOT EXISTS` the migration is harmless where `setupTable()` already ran). A new language means a new entry in `KEYWORD_LANGUAGES`, a new index and a new migration.

## Usage

```php
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;

$store = new PgVectorStore($pdo, tableName: 'neuron_documents', topK: 4, schema: DocumentSchema::of(
    DocumentField::string('collection')->filterable(),
));

// one-time setup (or manage the schema via your migrations)
$store->setupTable();
$store->ensureModelIndex('nomic-embed-text', 768);

// reads and writes go through an instance scoped to one model
$nomic = $store->forModel('nomic-embed-text', 768);

// index documents (embedding must be set and have 768 values)
$nomic->addDocuments($documents);

// search — filters and topK per request (neuron-ai 4.x) ...
$hits = $nomic->search(new SearchRequest($embedding, Filter::eq('collection', 'my-collection'), topK: 5));

// ... or as a scope of a copy, which keywordSearch() honours as well
$hits = $nomic->withFilters(['collection' => 'my-collection'])
    ->withTopK(5)
    ->search(new SearchRequest($embedding));

// keyword (full-text) search in the english configuration, same scope
$keywordHits = $nomic->withFilters(['collection' => 'my-collection'])
    ->withTopK(20)
    ->keywordSearch('What is the maximum temperature of the XR-200 controller?', 'english');

// remove one source group of this model, or of all models (unscoped)
$nomic->deleteBy('chunk', $sourceName);
$store->deleteBy('chunk', $sourceName);
$store->delete(Filter::where('sourceType', 'chunk')->where('collection', 'my-collection'));

// remove all rows of a model, optionally restricted by metadata
$store->deleteByModel('nomic-embed-text', ['collection' => 'my-collection']);
```

`dropTable()` runs `DROP TABLE IF EXISTS`. `getModel()` and `getDimension()` return the scope of an instance (`null` when unscoped). `deleteBy($sourceType, $sourceName)` is a shorthand for `delete()` with `sourceType` (and `sourceName`); the 3.x alias `deleteBySource()` is gone.

`forModel()`, `withFilters()` and `withTopK()` return modified copies and never change the instance they are called on, so a store registered as a shared service can be scoped freely.

A scoped instance only reads and writes rows of its model:

- `addDocuments()` writes the model into each row and rejects vectors whose length differs from the scoped dimension (`InvalidArgumentException`).
- `search(SearchRequest $request)` returns rows of the scoped model only, at most the request's `topK` (the store's without one), restricted by the scope of `withFilters()` and the request's filters; each document carries the model in its metadata under `model`. The SQL casts the column exactly like the index expression and inlines the model as a literal, so the planner can use the model's partial index (`searchSql($request)` and `searchParameters($request)` return the statement and its parameters, e.g. for `EXPLAIN`).
- `keywordSearch($query, $language)` returns rows of the scoped model only, restricted by the metadata filters and limited to `topK`, with the model in the metadata like `search()`. During a reindex a document has rows of two models; the model scope keeps the keyword hits free of stale rows and duplicate ids.
- `countBy($sourceType, $sourceName)` counts the scoped model's rows of a source group.

On an unscoped instance `addDocuments()`, `search()`, `keywordSearch()` and `countBy()` throw a `LogicException`; `delete()` and `deleteBy()` delete across all models.

### Filters and document schema

The store implements the neuron-ai 4.x `VectorStoreInterface` (`getSchema()`, `addDocument(s)()`, `delete(FilterExpression)`, `search(SearchRequest)`). A filter may name `sourceType`, `sourceName`, the store column `model` (declared by the store itself) and the metadata keys that the `DocumentSchema` passed as the last constructor argument declares `filterable()`; any other key throws a `DocumentSchemaException`, also through `withFilters()` and `deleteByModel()`, whose key/value arrays are validated like `Filter::eq()` conditions. Pass the same schema at every construction of the store.

- `eq`, `in`, `containsAny` and `containsAll` on a metadata key compile to JSONB containment (`metadata @> …`, GIN index); `neq` is its negation and needs a `required()` field; `gt`, `gte`, `lt` and `lte` compare the numeric value. On `sourceType`, `sourceName` and `model` the columns are compared. Values are bound parameters.
- `FilterGroup::anyOf()`/`allOf()` nest as OR/AND.
- A raw filter must be tagged with this class, `Filter::raw(PgVectorStore::class, "<SQL condition>")`; it is inlined as it is, so it must never carry request input. A raw filter of another store throws a `VectorStoreException`.
- `addDocuments()` validates every document against the schema and refuses a document without embedding (`VectorStoreException`) before anything is written.

`PgVectorStore::hydrate($row)` builds the document of a table row (id, content, source_type, source_name, metadata, model) without score, for readers of the table outside the store; metadata keys reserved by neuron-ai are skipped.

### Keyword search

`keywordSearch()` parses the query with `plainto_tsquery('<language>', :query)` and then replaces its AND operators (and the phrase operators PostgreSQL inserts for hyphenated words such as `XR-200`) by OR. A row matches when it shares at least one lexeme with the query, so a natural-language question finds the chunks that contain some of its words; with AND semantics a question almost never matches. The match uses exactly the index expression above, so the language's GIN index qualifies (`keywordSearchSql($language)` returns the statement, e.g. for `EXPLAIN`).

Rows are ranked by `ts_rank_cd` descending, ties by `id` ascending, and the `score` of a returned document is the `ts_rank_cd` value (not comparable with the cosine scores of `search()`). A query without lexemes (empty, only stop words) returns no rows. The query text is a bound parameter and is never parsed as tsquery syntax, so operators such as `&`, `!` or `:*` in user input are plain text.

Stemming and stop words follow the PostgreSQL configuration of the language: `english` lowercases and stems every word, so `Controllers` matches `controller`, `warehouses` matches `warehouse` and `s181` matches `S181`, and `XR 200` matches `XR-200` because the hyphenated word is indexed as a whole and as its parts. Cross-language matches (a question in one language against text in another) are not handled.

Scores are `1 − distance` within one model. Scores of different models are not comparable.

Model names, dimensions and keyword languages are inlined into SQL, so they are validated: a model name must match `^[A-Za-z0-9][A-Za-z0-9._:/-]*$` and be at most 190 characters, a dimension must be within 1..2000, and a language must be in the allow-list `PgVectorStore::KEYWORD_LANGUAGES` (`assertValidKeywordLanguage()`). Violations throw an `InvalidArgumentException` in `forModel()`, `ensureModelIndex()`, `deleteByModel()`, `ensureKeywordIndex()`, `keywordIndexName()` and `keywordSearch()`.

`sourceType` and `sourceName` are grouping keys chosen by the caller (for example a source kind and the id of the source document); the store only uses them to replace or delete a group of documents.

The score of a search result (`Document::getScore()`) is `VectorSimilarity::similarityFromDistance()` of the distance.

## Doctrine DBAL type

With `doctrine/dbal` installed (suggested, not required), `Dmstr\NeuronPgvectorStore\Doctrine\VectorType` maps the column `vector` onto a Doctrine entity, for example to expose the documents read-only or to manage the schema with Doctrine migrations. Register it as the type `vector`:

```php
use Doctrine\DBAL\Types\Type;
use Dmstr\NeuronPgvectorStore\Doctrine\VectorType;

Type::addType(VectorType::VECTOR, VectorType::class);
```

Map the store's dimensionless column without `length` (`#[ORM\Column(type: VectorType::VECTOR)]`); a fixed-dimension `vector(N)` column is declared with `length: N`. The PHP value is a float array, the database value the pgvector literal `[0.1,0.2,...]`. The type only supports PostgreSQL.

The ORM's schema tool does not leave a mapped store table alone, even with `schema_filter`: the generated schema then contains the table without the per-model and full-text indexes. Remove the store table from the generated schema (for example in a `postGenerateSchema` listener) and keep the database side out of the diff with `schema_filter`.

## Testing

The PHPUnit configuration `./phpunit.dist.xml` has two suites:

- `unit` (`./tests/Unit`) needs no database: it checks the SQL the filters compile to, the schema validation and the Doctrine type.
- `integration` (`./tests/Integration`) runs against a real PostgreSQL server with pgvector. It creates the extension and a scratch table `pgvector_store_test`, and drops the table after each test. The connection is configured with the libpq environment variables `PGHOST` (required), `PGPORT` (default `5432`), `PGDATABASE` (default `postgres`), `PGUSER` (default `postgres`) and `PGPASSWORD`. Without `PGHOST` the integration tests are skipped.

```bash
composer install
vendor/bin/phpunit --testsuite unit

docker run --rm -d --name pgvector-test -e POSTGRES_PASSWORD=postgres \
    -p 5432:5432 pgvector/pgvector:0.8.6-pg16
PGHOST=127.0.0.1 PGPASSWORD=postgres \
    vendor/bin/phpunit --testsuite integration --fail-on-skipped
```

The GitHub Action `./.github/workflows/tests.yml` runs both suites on PHP 8.5 with a `pgvector/pgvector` service container.

## License

MIT, see [LICENSE](./LICENSE).
