<!-- file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC] -->

# CLAUDE.md

This is a public open-source package maintained under github.com/dmstr.

A PostgreSQL pgvector vector store adapter for the Neuron AI PHP framework (`neuron-core/neuron-ai` 4.x): a plain Composer library with the namespace `Dmstr\NeuronPgvectorStore\`, no framework integration. See `./README.md` for the table schema, the indexes, filters and keyword search.

## Language policy

**Everything in this repository is in English**: commit messages, code, comments, documentation, test data and all communication about the repository (pull request titles and descriptions, code review, issues, and chat with the coding assistant). These packages are public and English-only.

## Requirements and tests

PHP >= 8.5 (see `./composer.json`). The PHPUnit 12 configuration `./phpunit.dist.xml` has two suites:

- `unit` (`./tests/Unit`) needs no database: `vendor/bin/phpunit --testsuite unit`.
- `integration` (`./tests/Integration`) needs PostgreSQL with the pgvector extension, configured by `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER` and `PGPASSWORD` (see `./tests/Integration/PostgresConnection.php`); without `PGHOST` its tests are skipped. Run it with `vendor/bin/phpunit --testsuite integration --fail-on-skipped`.

CI is the GitHub Action `./.github/workflows/tests.yml` (PHP 8.5, a `pgvector/pgvector` service container, both suites).

## Conventions

- Model names, dimensions, keyword languages and the index method are written into SQL as literals and are validated; every other value is a bound parameter. Keep it that way.
- A change to the table, an index name or an index expression is a breaking change for applications that mirror the schema in their own migrations: document it in `./README.md` and `./CHANGELOG.md`.
- Versions come from Git tags (plain SemVer, no `v` prefix); `composer.json` has no `version` field.
