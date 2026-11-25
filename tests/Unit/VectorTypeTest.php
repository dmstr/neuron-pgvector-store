<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore\Tests\Unit;

use Dmstr\NeuronPgvectorStore\Doctrine\VectorType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Database-free checks of the optional Doctrine DBAL type: column
 * declaration with and without dimension, and the conversion between float
 * arrays and pgvector literals.
 */
final class VectorTypeTest extends TestCase
{
    public function testDeclarationWithoutLengthIsDimensionless(): void
    {
        self::assertSame('vector', (new VectorType())->getSQLDeclaration([], new PostgreSQLPlatform()));
    }

    public function testDeclarationWithLengthHasTheDimension(): void
    {
        self::assertSame('vector(768)', (new VectorType())->getSQLDeclaration(['length' => 768], new PostgreSQLPlatform()));
    }

    public function testOtherPlatformsAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        (new VectorType())->getSQLDeclaration([], new SQLitePlatform());
    }

    public function testValuesRoundTrip(): void
    {
        $type = new VectorType();
        $platform = new PostgreSQLPlatform();

        self::assertSame('[0.5,1,-2.25]', $type->convertToDatabaseValue([0.5, 1, -2.25], $platform));
        self::assertSame([0.5, 1.0, -2.25], $type->convertToPHPValue('[0.5,1,-2.25]', $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertSame([], $type->convertToPHPValue(null, $platform));
    }

    public function testNonArrayValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new VectorType())->convertToDatabaseValue('[1,2]', new PostgreSQLPlatform());
    }
}
