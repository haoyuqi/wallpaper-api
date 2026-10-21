# Project agent guide

This guide supplements global instructions. Follow the user's requirements for
the environment, safety and authorization.

## Architecture and navigation

Before changing code, read the [architecture and feature map](docs/architecture.md)
to locate implementations and tests, then verify them against the source.
Update the owning documentation in the same PR when responsibilities, interfaces,
execution paths or failure behavior change.

## Code style

Follow [code style](docs/code-style.md) when writing and reviewing code.

## Development and review

Follow the [contributing guide](CONTRIBUTING.md) for development, verification and PR review.
Run the complete local test suite against the dedicated PostgreSQL 18 test database;
SQLite is a quick check and skips PostgreSQL-specific assertions.

## Contracts and scheduling

Read the [v1 contract](docs/contracts/v1.md) before changing inputs, outputs or the
Composer integration, and [daily synchronization](docs/operations/daily-sync.md)
before changing scheduled tasks.
