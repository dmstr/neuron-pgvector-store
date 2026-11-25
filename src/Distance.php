<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore;

/**
 * pgvector distance operators (pattern borrowed from symfony/ai-postgres-store).
 */
enum Distance: string
{
    case Cosine = 'cosine';
    case InnerProduct = 'inner_product';
    case L1 = 'l1';
    case L2 = 'l2';

    public function operator(): string
    {
        return match ($this) {
            self::Cosine => '<=>',
            self::InnerProduct => '<#>',
            self::L1 => '<+>',
            self::L2 => '<->',
        };
    }

    public function indexOpclass(): string
    {
        return match ($this) {
            self::Cosine => 'vector_cosine_ops',
            self::InnerProduct => 'vector_ip_ops',
            self::L1 => 'vector_l1_ops',
            self::L2 => 'vector_l2_ops',
        };
    }
}
