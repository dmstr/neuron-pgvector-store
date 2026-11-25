<!-- file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC] -->

# Changelog

All notable changes to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [SemVer](https://semver.org/) and come from the Git tags.

## [0.1.0] - 2026-11-25

Initial public release.

### Added

- `PgVectorStore`, a model-agnostic PostgreSQL pgvector store implementing the neuron-ai 4.x `VectorStoreInterface`: one table for the vectors of several embedding models, a partial HNSW index per model, upserts by id, portable filters validated against a `DocumentSchema`, and full-text keyword search with one GIN index per language.
- `Distance` with the pgvector operators and index opclasses (cosine, inner product, L1, L2).
- Optional Doctrine DBAL type `Doctrine\VectorType` for `vector` and `vector(N)` columns.
- PHPUnit suites `unit` (no database) and `integration` (PostgreSQL with pgvector), and a GitHub Action running both.

### Notes

- Licensed under MIT.
- Requires PHP >= 8.5 and `neuron-core/neuron-ai` `^4.1`.

[0.1.0]: https://github.com/dmstr/neuron-pgvector-store/releases/tag/0.1.0
