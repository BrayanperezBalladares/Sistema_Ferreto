# Transactional Data Foundation Specification

## Purpose

Define one secure MariaDB OLTP foundation, controlled schema lifecycle, and a future extraction seam without business schema or analytics implementation.

## Requirements

### Requirement: Single Configured OLTP Connection

The application MUST target one MariaDB OLTP database through validated PDO configuration. Database values MUST be parameterized, and handlers MUST NOT construct SQL from request values. The foundation MUST NOT open an ETL, data-warehouse, or analytics connection.

#### Scenario: Execute a parameterized database proof

- GIVEN valid database configuration
- WHEN the concept-free health proof interacts with MariaDB using input data
- THEN the interaction MUST use parameter binding
- AND it MUST report availability without exposing credentials or driver errors

#### Scenario: Database configuration is unusable

- GIVEN database configuration is missing, invalid, or unreachable
- WHEN a database-dependent operation starts
- THEN it MUST fail safely before application data changes

### Requirement: Explicit Transaction Boundary

State-changing data work MUST run within an explicit transaction boundary that commits only after the complete operation succeeds and rolls back on failure.

#### Scenario: Complete transactional work

- GIVEN a concept-free multi-step database proof
- WHEN every step succeeds
- THEN all changes MUST commit as one unit

#### Scenario: Roll back failed work

- GIVEN a transaction has made an intermediate change
- WHEN a later step fails
- THEN all changes from that transaction MUST be rolled back

### Requirement: Module-Owned Data Access

Future modules MUST own focused query and command contracts. HTTP handlers MUST NOT access PDO directly, and the foundation MUST NOT introduce a generic repository or ORM abstraction.

#### Scenario: Add a future module operation

- GIVEN a future module requires persistence
- WHEN its data contract is defined
- THEN reads MUST be expressed by module-owned queries and writes by module-owned commands
- AND no generic business repository contract MUST be required

### Requirement: Ordered Migration Lifecycle

Migrations MUST execute in deterministic order and MUST record identifier, checksum, and applied timestamp. An applied migration whose checksum changed MUST be rejected. A failed migration MUST stop later migrations and MUST NOT be recorded as applied.

#### Scenario: Apply pending migrations

- GIVEN migration history is valid and pending migrations exist
- WHEN the canonical migrate command runs
- THEN pending migrations MUST apply once in deterministic order
- AND their identifiers, checksums, and applied timestamps MUST be recorded

#### Scenario: Reject migration drift or failure

- GIVEN an applied checksum differs or a pending migration fails
- WHEN migration runs
- THEN it MUST stop with a failing result
- AND it MUST NOT mark the invalid or failed migration as applied

### Requirement: Separate Development Seeds

Development seeds MUST run separately from migrations, MUST be idempotent, and MUST contain no R1–R10 business schema or production data assumptions.

#### Scenario: Repeat development seeding

- GIVEN the development seed has already run
- WHEN the canonical seed command runs again
- THEN it MUST complete without duplicate effects

### Requirement: Stable Extraction Seam Without ETL

Foundation persistence conventions MUST support stable identifiers, UTC timestamps, and retained migration history for future read-only extraction. This change MUST NOT create business tables, ETL jobs, warehouse schemas, or event pipelines.

#### Scenario: Inspect the foundation schema

- GIVEN foundation migrations are applied
- WHEN schema objects and connections are inspected
- THEN only concept-free infrastructure objects MUST exist
- AND no ETL or data-warehouse target MUST be configured

### Requirement: Transactional Infrastructure Proof

The canonical `composer test` command MUST exercise a concept-free database interaction, parameterization, commit, rollback, and migration-state safety, and MUST return failure when any contract is violated.

#### Scenario: Run data foundation tests

- GIVEN an isolated configured test database
- WHEN `composer test` runs
- THEN the transactional foundation contracts MUST be verified without business records
