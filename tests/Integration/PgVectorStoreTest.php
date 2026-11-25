<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore\Tests\Integration;

use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against PostgreSQL with pgvector ({@see PostgresConnection})
 * on a scratch table: two models with different dimensions in one table, upsert,
 * cosine search, metadata filters, model-scoped deletes and counts, the
 * use of the per-model partial HNSW indexes, and keyword (full-text) search
 * with its per-language GIN indexes. The keyword tests use the `english`
 * text search configuration. The metadata key `collection` is
 * declared filterable in the store's document schema (neuron-ai 4.x).
 */
final class PgVectorStoreTest extends TestCase
{
    private const TABLE = 'pgvector_store_test';
    private const DOC_A = '00000000-0000-0000-0000-00000000000a';
    private const DOC_B = '00000000-0000-0000-0000-00000000000b';
    private const DOC_C = '00000000-0000-0000-0000-00000000000c';
    private const DOC_D = '00000000-0000-0000-0000-00000000000d';
    private const DOC_X = '00000000-0000-0000-0000-0000000000a1';
    private const DOC_Y = '00000000-0000-0000-0000-0000000000a2';

    private const SMALL = 'test-small:v1';
    private const SMALL_DIM = 768;
    private const LARGE = 'test/large-model';
    private const LARGE_DIM = 1024;

    private \PDO $pdo;

    private PgVectorStore $store;

    private PgVectorStore $small;

    private PgVectorStore $large;

    protected function setUp(): void
    {
        $this->pdo = PostgresConnection::pdo();
        $this->store = new PgVectorStore($this->pdo, self::TABLE, topK: 4, schema: DocumentSchema::of(DocumentField::string('collection')->filterable()));
        $this->store->dropTable();
        $this->store->setupTable();
        $this->store->ensureModelIndex(self::SMALL, self::SMALL_DIM);
        $this->store->ensureModelIndex(self::LARGE, self::LARGE_DIM);

        $this->small = $this->store->forModel(self::SMALL, self::SMALL_DIM);
        $this->large = $this->store->forModel(self::LARGE, self::LARGE_DIM);
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) {
            $this->store->dropTable();
        }
        parent::tearDown();
    }

    public function testAddSearchFilterAndDelete(): void
    {
        $this->small->addDocuments([
            $this->document(self::DOC_A, 'Governance report', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM),
            $this->document(self::DOC_B, 'Board of directors', ['collection' => 'alpha'], [0.9, 0.1, 0], self::SMALL_DIM),
            $this->document(self::DOC_C, 'Apple pie recipe', ['collection' => 'beta'], [0, 0, 1], self::SMALL_DIM),
        ]);

        // Cosine: query near doc-a must rank doc-a first, doc-c last.
        $hits = [...$this->small->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))];
        self::assertCount(3, $hits);
        self::assertSame(self::DOC_A, $hits[0]->getId());
        self::assertSame(self::DOC_C, $hits[2]->getId());
        self::assertGreaterThan($hits[2]->getScore(), $hits[0]->getScore());
        self::assertSame('alpha', $hits[0]->getMetadata()['collection']);
        self::assertSame(self::SMALL, $hits[0]->getMetadata()['model']);

        // JSONB containment filter.
        $filtered = [...$this->small->withFilters(['collection' => 'beta'])->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))];
        self::assertCount(1, $filtered);
        self::assertSame(self::DOC_C, $filtered[0]->getId());

        // Upsert: same id, new content.
        $this->small->addDocument($this->document(self::DOC_A, 'Governance report v2', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM));
        $hits = [...$this->small->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))];
        self::assertCount(3, $hits);
        self::assertSame('Governance report v2', $hits[0]->getContent());

        // deleteBy source.
        $this->small->deleteBy('test', 'source-1');
        self::assertCount(0, [...$this->small->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))]);
    }

    public function testModelsWithDifferentDimensionsShareTheTable(): void
    {
        $this->small->addDocument($this->document(self::DOC_A, 'small A', [], [1, 0, 0], self::SMALL_DIM));
        $this->large->addDocument($this->document(self::DOC_X, 'large X', [], [1, 0, 0], self::LARGE_DIM));
        $this->small->addDocument($this->document(self::DOC_B, 'small B', [], [0, 1, 0], self::SMALL_DIM));
        $this->large->addDocument($this->document(self::DOC_Y, 'large Y', [], [0, 1, 0], self::LARGE_DIM));

        $smallHits = [...$this->small->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))];
        self::assertSame([self::DOC_A, self::DOC_B], array_map(static fn (Document $d) => $d->getId(), $smallHits));

        $largeHits = [...$this->large->search(new SearchRequest($this->vector([0, 1, 0], self::LARGE_DIM)))];
        self::assertSame([self::DOC_Y, self::DOC_X], array_map(static fn (Document $d) => $d->getId(), $largeHits));
        self::assertSame([self::LARGE, self::LARGE], array_map(static fn (Document $d) => $d->getMetadata()['model'], $largeHits));

        $dims = $this->pdo->query(sprintf('SELECT model, vector_dims(vector) FROM %s GROUP BY 1, 2 ORDER BY 1', self::TABLE))->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame([self::LARGE => self::LARGE_DIM, self::SMALL => self::SMALL_DIM], $dims);
    }

    public function testWrongVectorLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->small->addDocument($this->document(self::DOC_A, 'wrong length', [], [1, 0, 0], self::LARGE_DIM));
    }

    public function testWrongQueryLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->small->search(new SearchRequest($this->vector([1, 0, 0], self::LARGE_DIM)));
    }

    public function testUnscopedWriteIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->store->addDocument($this->document(self::DOC_A, 'unscoped', [], [1, 0, 0], self::SMALL_DIM));
    }

    public function testUnscopedSearchIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->store->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'quote in model' => ["x' OR '1'='1", 8];
        yield 'empty model' => ['', 8];
        yield 'dimension zero' => ['model', 0];
        yield 'dimension above 2000' => ['model', 2001];
    }

    #[DataProvider('invalidScopes')]
    public function testInvalidModelOrDimensionIsRejected(string $model, int $dimension): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->forModel($model, $dimension);
    }

    public function testDeleteByAndCountByAreModelScoped(): void
    {
        $this->small->addDocuments([
            $this->document(self::DOC_A, 'small A', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM),
            $this->document(self::DOC_B, 'small B', ['collection' => 'beta'], [0, 1, 0], self::SMALL_DIM),
        ]);
        $this->large->addDocument($this->document(self::DOC_X, 'large X', ['collection' => 'alpha'], [1, 0, 0], self::LARGE_DIM));

        self::assertSame(2, $this->small->countBy('test', 'source-1'));
        self::assertSame(1, $this->large->countBy('test', 'source-1'));

        // Scoped deleteBy leaves the other model alone.
        $this->large->deleteBy('test', 'source-1');
        self::assertSame(2, $this->small->countBy('test', 'source-1'));
        self::assertSame(0, $this->large->countBy('test', 'source-1'));

        // deleteByModel with a metadata filter.
        $this->store->deleteByModel(self::SMALL, ['collection' => 'beta']);
        self::assertSame(1, $this->small->countBy('test', 'source-1'));

        // Unscoped deleteBy removes all models.
        $this->large->addDocument($this->document(self::DOC_X, 'large X', [], [1, 0, 0], self::LARGE_DIM));
        $this->store->deleteBy('test', 'source-1');
        self::assertSame(0, $this->small->countBy('test', 'source-1'));
        self::assertSame(0, $this->large->countBy('test', 'source-1'));
    }

    public function testDeleteByModelRemovesOnlyThatModel(): void
    {
        $this->small->addDocument($this->document(self::DOC_A, 'small A', [], [1, 0, 0], self::SMALL_DIM));
        $this->large->addDocument($this->document(self::DOC_X, 'large X', [], [1, 0, 0], self::LARGE_DIM));

        $this->store->deleteByModel(self::LARGE);

        self::assertSame(1, $this->small->countBy('test', 'source-1'));
        self::assertSame(0, $this->large->countBy('test', 'source-1'));
    }

    public function testUnscopedCountIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->store->countBy('test', 'source-1');
    }

    public function testWithFiltersAndWithTopKAreImmutable(): void
    {
        $this->small->addDocuments([
            $this->document(self::DOC_A, 'A', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM),
            $this->document(self::DOC_B, 'B', ['collection' => 'alpha'], [0.9, 0.1, 0], self::SMALL_DIM),
            $this->document(self::DOC_C, 'C', ['collection' => 'beta'], [0, 0, 1], self::SMALL_DIM),
        ]);
        $query = $this->vector([1, 0, 0], self::SMALL_DIM);

        $filtered = $this->small->withFilters(['collection' => 'beta']);
        $limited = $this->small->withTopK(1);

        self::assertNotSame($this->small, $filtered);
        self::assertCount(1, [...$filtered->search(new SearchRequest($query))]);
        self::assertCount(1, [...$limited->search(new SearchRequest($query))]);
        // The original keeps no filters and topK 4.
        self::assertCount(3, [...$this->small->search(new SearchRequest($query))]);
        self::assertNull($this->store->getModel());
    }

    public function testMissingEmbeddingIsRejected(): void
    {
        $document = (new Document('without embedding'))->setId('00000000-0000-0000-0000-0000000000ff');

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('must have an embedding');
        $this->small->addDocument($document);
    }

    public function testModelIndexesArePartialExpressionIndexes(): void
    {
        $indexes = $this->pdo->query(sprintf(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = '%s'",
            self::TABLE,
        ))->fetchAll(\PDO::FETCH_KEY_PAIR);

        $name = $this->store->modelIndexName(self::LARGE);
        self::assertSame('idx_'.self::TABLE.'_vector_'.substr(sha1(self::LARGE), 0, 12), $name);
        self::assertArrayHasKey($name, $indexes);
        self::assertStringContainsString('USING hnsw', $indexes[$name]);
        self::assertStringContainsString('vector(1024)', $indexes[$name]);
        self::assertStringContainsString("'test/large-model'", $indexes[$name]);
        self::assertTrue($this->store->modelIndexExists(self::LARGE));
        self::assertFalse($this->store->modelIndexExists('not-indexed'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function models(): iterable
    {
        yield 'small' => [self::SMALL, self::SMALL_DIM];
        yield 'large' => [self::LARGE, self::LARGE_DIM];
    }

    /**
     * The statement search() issues uses the model's partial index
     * (sequential scans disabled so the tiny table does not tip the planner).
     */
    #[DataProvider('models')]
    public function testSearchUsesThePartialIndexOfTheModel(string $model, int $dimension): void
    {
        $scoped = $this->store->forModel($model, $dimension);
        $scoped->addDocument($this->document(self::DOC_A, 'A', ['collection' => 'alpha'], [1, 0, 0], $dimension));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET LOCAL enable_seqscan = off');
            $request = new SearchRequest($this->vector([1, 0, 0], $dimension));
            $stmt = $this->pdo->prepare('EXPLAIN '.$scoped->searchSql($request));
            $stmt->execute($scoped->searchParameters($request));
            $plan = implode("\n", $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $this->pdo->rollBack();
        }

        self::assertStringContainsString('Index Scan using '.$this->store->modelIndexName($model), $plan);
    }

    /**
     * A filter that matches none of the first hnsw.ef_search (40) index
     * candidates still finds its rows: 60 rows of another collection lie
     * closer to the query than the two rows searched for.
     */
    public function testFilteredSearchFindsRowsBeyondTheFirstIndexCandidates(): void
    {
        $documents = [];
        for ($i = 1; $i <= 60; ++$i) {
            $documents[] = $this->document(\sprintf('00000000-0000-0000-0001-%012d', $i), 'alpha '.$i, ['collection' => 'alpha'], [1, $i / 1000, 0], self::SMALL_DIM);
        }
        $documents[] = $this->document(self::DOC_X, 'beta 1', ['collection' => 'beta'], [0.5, 0, 1], self::SMALL_DIM);
        $documents[] = $this->document(self::DOC_Y, 'beta 2', ['collection' => 'beta'], [0.3, 0, 1], self::SMALL_DIM);
        $this->small->addDocuments($documents);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET LOCAL enable_seqscan = off');
            $hits = [...$this->small->withFilters(['collection' => 'beta'])->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))];
        } finally {
            $this->pdo->rollBack();
        }

        self::assertSame([self::DOC_X, self::DOC_Y], $this->ids($hits));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exactTerms(): iterable
    {
        yield 'product code with space' => ['XR 200', self::DOC_A];
        yield 'product code with hyphen' => ['XR-200', self::DOC_A];
        yield 'lower-case code' => ['s181', self::DOC_B];
        yield 'upper-case code' => ['S181', self::DOC_B];
        yield 'abbreviation' => ['HVAC', self::DOC_B];
        yield 'upper-case word' => ['CONTROLLER', self::DOC_A];
        yield 'plural of a word' => ['warehouses', self::DOC_C];
    }

    #[DataProvider('exactTerms')]
    public function testKeywordSearchFindsExactTerms(string $query, string $expectedId): void
    {
        $this->small->addDocuments($this->sampleDocuments(self::SMALL_DIM));

        $hits = [...$this->small->keywordSearch($query, 'english')];

        self::assertSame([$expectedId], $this->ids($hits));
        self::assertGreaterThan(0.0, $hits[0]->getScore());
    }

    public function testKeywordSearchFindsSpacedCodeByHyphenatedQuery(): void
    {
        $this->small->addDocument($this->document(self::DOC_A, 'Order a spare part for the XR 200', [], [1, 0, 0], self::SMALL_DIM));

        self::assertSame([self::DOC_A], $this->ids([...$this->small->keywordSearch('XR-200', 'english')]));
    }

    /**
     * The terms of a question are ORed: with AND semantics (plain
     * plainto_tsquery) the question below matches nothing, because no chunk
     * contains "maximum". More matching terms rank higher.
     */
    public function testNaturalLanguageQuestionYieldsKeywordHits(): void
    {
        $this->small->addDocuments($this->sampleDocuments(self::SMALL_DIM));
        $question = 'What is the maximum temperature of the XR-200 controller?';

        $stmt = $this->pdo->prepare(sprintf(
            "SELECT count(*) FROM %s WHERE to_tsvector('english'::regconfig, coalesce(content, '')) @@ plainto_tsquery('english'::regconfig, :q)",
            self::TABLE,
        ));
        $stmt->execute([':q' => $question]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Guard: AND semantics must not match.');

        $hits = [...$this->small->keywordSearch($question, 'english')];
        self::assertSame([self::DOC_A, self::DOC_D], $this->ids($hits));
        self::assertGreaterThan($hits[1]->getScore(), $hits[0]->getScore());
    }

    public function testKeywordSearchReturnsDocumentsLikeSearch(): void
    {
        $this->small->addDocuments($this->sampleDocuments(self::SMALL_DIM));

        $hits = [...$this->small->keywordSearch('HVAC', 'english')];

        self::assertCount(1, $hits);
        self::assertInstanceOf(Document::class, $hits[0]);
        self::assertSame(self::DOC_B, $hits[0]->getId());
        self::assertSame('The S181 sensor reports errors to the HVAC unit.', $hits[0]->getContent());
        self::assertSame('test', $hits[0]->getSourceType());
        self::assertSame('source-1', $hits[0]->getSourceName());
        self::assertSame(['collection' => 'beta', 'model' => self::SMALL], $hits[0]->getMetadata());
        self::assertIsFloat($hits[0]->getScore());
    }

    /**
     * During a reindex a document has rows of two models; keyword search
     * only returns the rows of the scoped model.
     */
    public function testKeywordSearchIsModelScoped(): void
    {
        $content = 'The XR-200 controller regulates the temperature.';
        $this->small->addDocument($this->document(self::DOC_A, $content, [], [1, 0, 0], self::SMALL_DIM));
        $this->large->addDocument($this->document(self::DOC_X, $content, [], [1, 0, 0], self::LARGE_DIM));

        $smallHits = [...$this->small->keywordSearch('XR-200', 'english')];
        self::assertSame([self::DOC_A], $this->ids($smallHits));
        self::assertSame(self::SMALL, $smallHits[0]->getMetadata()['model']);

        $largeHits = [...$this->large->keywordSearch('XR-200', 'english')];
        self::assertSame([self::DOC_X], $this->ids($largeHits));
        self::assertSame(self::LARGE, $largeHits[0]->getMetadata()['model']);
    }

    public function testKeywordSearchRespectsFiltersAndTopK(): void
    {
        $this->small->addDocuments([
            $this->document(self::DOC_A, 'HVAC maintenance', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM),
            $this->document(self::DOC_B, 'HVAC maintenance and HVAC inspection', ['collection' => 'alpha'], [0, 1, 0], self::SMALL_DIM),
            $this->document(self::DOC_C, 'HVAC manual', ['collection' => 'beta'], [0, 0, 1], self::SMALL_DIM),
        ]);

        self::assertCount(3, [...$this->small->keywordSearch('HVAC', 'english')]);
        self::assertSame([self::DOC_C], $this->ids([...$this->small->withFilters(['collection' => 'beta'])->keywordSearch('HVAC', 'english')]));
        self::assertCount(1, [...$this->small->withTopK(1)->keywordSearch('HVAC', 'english')]);
        self::assertCount(2, [...$this->small->withFilters(['collection' => 'alpha'])->withTopK(5)->keywordSearch('HVAC', 'english')]);
    }

    public function testKeywordSearchBreaksTiesById(): void
    {
        // Inserted in descending id order; equal content means equal rank.
        foreach ([self::DOC_C, self::DOC_B, self::DOC_A] as $id) {
            $this->small->addDocument($this->document($id, 'HVAC maintenance', [], [1, 0, 0], self::SMALL_DIM));
        }

        $hits = [...$this->small->keywordSearch('HVAC', 'english')];

        self::assertSame([self::DOC_A, self::DOC_B, self::DOC_C], $this->ids($hits));
        self::assertSame($hits[0]->getScore(), $hits[2]->getScore());
        self::assertSame([self::DOC_A, self::DOC_B], $this->ids([...$this->small->withTopK(2)->keywordSearch('HVAC', 'english')]));
    }

    public function testKeywordSearchUsesTheLanguage(): void
    {
        $this->small->addDocument($this->document(self::DOC_A, 'The controllers were running', [], [1, 0, 0], self::SMALL_DIM));

        // English stemming: "controller" and "run" match the inflected forms.
        self::assertSame([self::DOC_A], $this->ids([...$this->small->keywordSearch('controller run', 'english')]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queriesWithoutLexemes(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'stop words only' => ['the of and a'];
        yield 'punctuation only' => ['?! & | ( )'];
    }

    #[DataProvider('queriesWithoutLexemes')]
    public function testQueryWithoutLexemesYieldsNoHits(string $query): void
    {
        $this->small->addDocuments($this->sampleDocuments(self::SMALL_DIM));

        self::assertSame([], [...$this->small->keywordSearch($query, 'english')]);
    }

    public function testTsqueryOperatorsInTheQueryAreText(): void
    {
        $this->small->addDocuments($this->sampleDocuments(self::SMALL_DIM));

        $hits = [...$this->small->keywordSearch("XR-200 & !O'Brien | (<-> :* '", 'english')];

        self::assertSame([self::DOC_A], $this->ids($hits));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLanguages(): iterable
    {
        yield 'sql injection' => ["english'::regconfig, content)) OR 1=1 --"];
        yield 'capitalised' => ['English'];
        yield 'other configuration' => ['simple'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidLanguages')]
    public function testInvalidKeywordLanguageIsRejected(string $language): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->small->keywordSearch('HVAC', $language);
    }

    #[DataProvider('invalidLanguages')]
    public function testInvalidKeywordIndexLanguageIsRejected(string $language): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->ensureKeywordIndex($language);
    }

    public function testInvalidKeywordIndexNameLanguageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->keywordIndexName('dutch');
    }

    public function testUnscopedKeywordSearchIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->store->keywordSearch('HVAC', 'english');
    }

    /**
     * setupTable() creates the index of every language; ensureKeywordIndex()
     * is idempotent and recreates a dropped index.
     */
    public function testKeywordIndexesAreGinExpressionIndexes(): void
    {
        self::assertSame('idx_'.self::TABLE.'_fts_german', $this->store->keywordIndexName('german'));
        self::assertSame(['german', 'english'], PgVectorStore::KEYWORD_LANGUAGES);

        $this->pdo->exec(sprintf('DROP INDEX %s', $this->store->keywordIndexName('german')));
        foreach (PgVectorStore::KEYWORD_LANGUAGES as $language) {
            $this->store->ensureKeywordIndex($language);
            $this->store->ensureKeywordIndex($language);
        }

        $indexes = $this->pdo->query(sprintf(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = '%s'",
            self::TABLE,
        ))->fetchAll(\PDO::FETCH_KEY_PAIR);

        foreach (PgVectorStore::KEYWORD_LANGUAGES as $language) {
            $name = $this->store->keywordIndexName($language);
            self::assertArrayHasKey($name, $indexes);
            self::assertStringContainsString('USING gin', $indexes[$name]);
            self::assertStringContainsString(sprintf("to_tsvector('%s'::regconfig, COALESCE(content, ''::text))", $language), $indexes[$name]);
        }
    }

    /**
     * The statement keywordSearch() issues uses the GIN index of the
     * language (sequential and plain index scans disabled so the tiny table
     * does not tip the planner and only the bitmap scan remains).
     */
    #[DataProvider('models')]
    public function testKeywordSearchUsesTheFullTextIndex(string $model, int $dimension): void
    {
        $scoped = $this->store->forModel($model, $dimension);
        $scoped->addDocuments($this->sampleDocuments($dimension));

        foreach (PgVectorStore::KEYWORD_LANGUAGES as $language) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec('SET LOCAL enable_seqscan = off');
                $this->pdo->exec('SET LOCAL enable_indexscan = off');
                $stmt = $this->pdo->prepare('EXPLAIN '.$scoped->keywordSearchSql($language));
                $stmt->execute($scoped->keywordSearchParameters('controller XR-200'));
                $plan = implode("\n", $stmt->fetchAll(\PDO::FETCH_COLUMN));
            } finally {
                $this->pdo->rollBack();
            }

            self::assertStringContainsString('Bitmap Index Scan on '.$this->store->keywordIndexName($language), $plan);
        }
    }

    /**
     * Portable request filters (neuron-ai 4.x): equality and `in` on a
     * declared metadata key, the source columns, OR groups, a raw SQL
     * condition tagged with the store, and the per-request topK; all ANDed
     * with the scope of withFilters().
     */
    public function testSearchRequestFiltersAndTopK(): void
    {
        $this->small->addDocuments([
            $this->document(self::DOC_A, 'A', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM),
            $this->document(self::DOC_B, 'B', ['collection' => 'alpha'], [0.9, 0.1, 0], self::SMALL_DIM),
            $this->document(self::DOC_C, 'C', ['collection' => 'beta'], [0, 0, 1], self::SMALL_DIM),
        ]);
        $query = $this->vector([1, 0, 0], self::SMALL_DIM);

        self::assertSame([self::DOC_C], $this->ids([...$this->small->search(new SearchRequest($query, Filter::eq('collection', 'beta')))]));
        self::assertSame([self::DOC_A, self::DOC_B, self::DOC_C], $this->ids([...$this->small->search(new SearchRequest($query, Filter::in('collection', ['beta', 'alpha'])))]));
        self::assertSame([self::DOC_A], $this->ids([...$this->small->search(new SearchRequest($query, topK: 1))]));
        self::assertCount(3, [...$this->small->search(new SearchRequest($query, Filter::where('sourceType', 'test')->where('sourceName', 'source-1')))]);
        self::assertSame([], [...$this->small->search(new SearchRequest($query, Filter::eq('model', self::LARGE)))]);
        self::assertSame([self::DOC_A, self::DOC_C], $this->ids([...$this->small->search(new SearchRequest($query, FilterGroup::anyOf(Filter::eq('collection', 'beta'), Filter::raw(PgVectorStore::class, sprintf("id = '%s'", self::DOC_A)))))]));
        self::assertSame([], [...$this->small->withFilters(['collection' => 'beta'])->search(new SearchRequest($query, Filter::eq('collection', 'alpha')))]);
    }

    public function testFilterOnAnUndeclaredFieldIsRejected(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->small->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM), Filter::eq('tenant', 'acme')));
    }

    public function testDeleteByFilterIsModelScoped(): void
    {
        $this->small->addDocuments([
            $this->document(self::DOC_A, 'small A', ['collection' => 'alpha'], [1, 0, 0], self::SMALL_DIM),
            $this->document(self::DOC_B, 'small B', ['collection' => 'beta'], [0, 1, 0], self::SMALL_DIM),
        ]);
        $this->large->addDocument($this->document(self::DOC_X, 'large X', ['collection' => 'beta'], [1, 0, 0], self::LARGE_DIM));

        $this->small->delete(Filter::eq('collection', 'beta'));

        self::assertSame([self::DOC_A], $this->ids([...$this->small->search(new SearchRequest($this->vector([1, 0, 0], self::SMALL_DIM)))]));
        self::assertSame(1, $this->large->countBy('test', 'source-1'));
    }

    /**
     * Chunks with English content: product codes, an abbreviation and
     * words the stemmer reduces.
     *
     * @return Document[]
     */
    private function sampleDocuments(int $dimension): array
    {
        return [
            $this->document(self::DOC_A, 'The XR-200 controller regulates the temperature of the oven.', ['collection' => 'beta'], [1, 0, 0], $dimension),
            $this->document(self::DOC_B, 'The S181 sensor reports errors to the HVAC unit.', ['collection' => 'beta'], [0, 1, 0], $dimension),
            $this->document(self::DOC_C, 'Deliveries are handed over at the loading dock of the warehouse.', ['collection' => 'beta'], [0, 0, 1], $dimension),
            $this->document(self::DOC_D, 'The temperature is logged every day.', ['collection' => 'beta'], [1, 1, 0], $dimension),
        ];
    }

    /**
     * @param Document[] $documents
     *
     * @return list<string|int>
     */
    private function ids(array $documents): array
    {
        return array_map(static fn (Document $d) => $d->getId(), $documents);
    }

    /**
     * @param float[] $direction
     */
    private function document(string $id, string $content, array $metadata, array $direction, int $dimension): Document
    {
        return (new Document($content))
            ->setId($id)
            ->setSourceType('test')
            ->setSourceName('source-1')
            ->setEmbedding($this->vector($direction, $dimension))
            ->setMetadata($metadata);
    }

    /**
     * @param float[] $direction first components, padded to $dimension
     *
     * @return float[]
     */
    private function vector(array $direction, int $dimension): array
    {
        return array_pad($direction, $dimension, 0.0);
    }
}
