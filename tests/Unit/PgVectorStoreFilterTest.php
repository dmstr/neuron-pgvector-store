<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore\Tests\Unit;

use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Database-free checks of the neuron-ai 4.x filter contract of the pgvector
 * store: the document schema of the test declares `collection_id` and
 * `topic`, the store adds `model`; portable filters compile to SQL with
 * bound values; filters on undeclared fields and raw filters of other
 * stores are refused. The SQL itself runs in
 * {@see \Dmstr\NeuronPgvectorStore\Tests\Integration\PgVectorStoreTest}
 * (PostgreSQL with pgvector). The in-memory SQLite connection only
 * satisfies the constructor and is never queried.
 */
final class PgVectorStoreFilterTest extends TestCase
{
    private const MODEL = 'nomic-embed-text';

    public function testSchemaDeclaresTheDocumentFieldsAndTheModel(): void
    {
        $schema = $this->store()->getSchema();

        self::assertInstanceOf(VectorStoreInterface::class, $this->store());
        foreach (['collection_id', 'topic', 'model'] as $field) {
            self::assertTrue($schema->getField($field)?->isFilterable(), $field.' is filterable');
        }
        self::assertTrue((new PgVectorStore(new \PDO('sqlite::memory:')))->getSchema()->getField('model')?->isFilterable(), 'the store declares model without a schema too');
    }

    public function testWithoutRequestFiltersTheStatementKeepsTheScope(): void
    {
        $scoped = $this->scoped()->withFilters(['collection_id' => 'c1']);
        $request = new SearchRequest([1.0, 0.0, 0.0]);

        $sql = $scoped->searchSql($request);

        self::assertStringContainsString("WHERE model = 'nomic-embed-text' AND metadata @> :filters\n", $sql);
        self::assertStringContainsString('LIMIT 4', $sql);
        self::assertSame([':vector' => '[1,0,0]', ':filters' => '{"collection_id":"c1"}'], $scoped->searchParameters($request));
    }

    public function testPortableFiltersCompileToBoundConditions(): void
    {
        $request = new SearchRequest([1.0, 0.0, 0.0], FilterGroup::allOf(
            Filter::where('collection_id', 'c1')->where('sourceType', 'chunk'),
            FilterGroup::anyOf(Filter::in('topic', ['a', 'b']), Filter::eq('model', self::MODEL)),
        ), topK: 7);

        $sql = $this->scoped()->searchSql($request);

        self::assertStringContainsString(
            "WHERE model = 'nomic-embed-text' AND ((metadata @> :f0::jsonb) AND (source_type = :f1) AND ((metadata @> :f2::jsonb OR metadata @> :f3::jsonb) OR (model = :f4)))\n",
            $sql,
        );
        self::assertStringContainsString('LIMIT 7', $sql);
        self::assertSame([
            ':vector' => '[1,0,0]',
            ':f0' => '{"collection_id":"c1"}',
            ':f1' => 'chunk',
            ':f2' => '{"topic":"a"}',
            ':f3' => '{"topic":"b"}',
            ':f4' => self::MODEL,
        ], $this->scoped()->searchParameters($request));
    }

    public function testRawFilterOfTheStoreIsInlined(): void
    {
        $sql = $this->scoped()->searchSql(new SearchRequest([1.0], Filter::raw(PgVectorStore::class, "metadata->>'chunk_number' = '0'")));

        self::assertStringContainsString(" AND (metadata->>'chunk_number' = '0')\n", $sql);
    }

    public function testRawFilterOfAnotherStoreIsRefused(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets');

        $this->scoped()->searchSql(new SearchRequest([1.0], Filter::raw(VectorStoreInterface::class, 'true')));
    }

    public function testRequestFilterOnAnUndeclaredFieldIsRefused(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "chunk_id" is not declared');

        $this->scoped()->searchSql(new SearchRequest([1.0], Filter::eq('chunk_id', 'x')));
    }

    public function testScopeOnAnUndeclaredFieldIsRefused(): void
    {
        $this->expectException(DocumentSchemaException::class);

        $this->scoped()->withFilters(['chunk_id' => 'x']);
    }

    public function testScopeWithAValueOfTheWrongTypeIsRefused(): void
    {
        $this->expectException(DocumentSchemaException::class);

        $this->store()->deleteByModel(self::MODEL, ['collection_id' => 42]);
    }

    public function testRequestTopKMustBePositive(): void
    {
        $this->expectException(VectorStoreException::class);

        new SearchRequest([1.0], topK: 0);
    }

    public function testDocumentWithoutEmbeddingIsRefusedBeforeAnyWrite(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('must have an embedding');

        $this->scoped()->addDocuments([new Document('without vector')]);
    }

    public function testHydratedRowsSkipReservedKeysAndCarryTheModel(): void
    {
        $document = PgVectorStore::hydrate([
            'id' => 'e1',
            'content' => 'Some text',
            'source_type' => 'chunk',
            'source_name' => 'c1',
            'metadata' => '{"collection_id": "k1", "id": "stale", "score": 3, "model": "old"}',
            'model' => self::MODEL,
        ]);

        self::assertSame('e1', $document->getId());
        self::assertSame('chunk', $document->getSourceType());
        self::assertSame('c1', $document->getSourceName());
        self::assertSame(['collection_id' => 'k1', 'model' => self::MODEL], $document->getMetadata());
        self::assertNull($document->getScore());
        self::assertNull($document->getEmbedding());
    }

    /**
     * Two optional, filterable string fields, as an application would
     * declare for its own metadata.
     */
    private static function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('collection_id')->filterable(),
            DocumentField::string('topic')->filterable(),
        );
    }

    private function store(): PgVectorStore
    {
        return new PgVectorStore(new \PDO('sqlite::memory:'), 'embedding', schema: self::schema());
    }

    private function scoped(): PgVectorStore
    {
        return $this->store()->forModel(self::MODEL, 3);
    }
}
