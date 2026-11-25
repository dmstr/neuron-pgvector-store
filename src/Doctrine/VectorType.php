<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Optional Doctrine DBAL type for pgvector `vector` and `vector(N)` columns.
 *
 * Lets applications map the store's table onto a Doctrine entity (e.g. to
 * expose documents read-only via API Platform) and manage the schema with
 * Doctrine migrations. Requires doctrine/dbal (suggested dependency).
 *
 * PHP value: float[]; database value: '[0.1,0.2,...]' literal.
 *
 * The model-agnostic store uses a dimensionless column `vector` of type
 * `vector`: map it without `length`, e.g.
 * `#[ORM\Column(type: VectorType::VECTOR)]`. Rows of different embedding
 * models and dimensions share the column, and each model is indexed by a
 * partial expression index on `vector::vector(N)` (see
 * PgVectorStore::ensureModelIndex()). A fixed-dimension `vector(N)` column
 * remains supported by declaring `length` (the dimension), e.g.
 * `length: 768`; such a column can be indexed with HNSW directly.
 */
class VectorType extends Type
{
    final public const VECTOR = 'vector';

    /**
     * Required by doctrine/dbal ^3; removed from the base class in ^4.
     */
    public function getName(): string
    {
        return self::VECTOR;
    }

    /**
     * Returns `vector(N)` for a positive integer `length`, plain `vector`
     * otherwise.
     *
     * Plain `vector` is the shape of the model-agnostic store column (see
     * class note). It is also what introspected columns yield: DBAL's
     * PostgreSQL schema manager does not extract the dimension of a
     * `vector(N)` column, so schema comparison tools see `vector` on the
     * database side either way.
     *
     * @param mixed[] $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('The vector type is only supported on PostgreSQL (pgvector).');
        }

        $length = $column['length'] ?? null;
        if (!is_int($length) || $length < 1) {
            return 'vector';
        }

        return sprintf('vector(%d)', $length);
    }

    /**
     * @return float[]
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        if ($value === null) {
            return [];
        }

        $value = is_resource($value) ? stream_get_contents($value) : (string) $value;

        return array_map(floatval(...), explode(',', trim($value, '[]')));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('vector values must be float arrays.');
        }

        return '['.implode(',', array_map(floatval(...), $value)).']';
    }
}
