# Reproducible Project Runtime Specification

## Purpose

Define a repeatable local PHP runtime with auditable dependencies, validated configuration, local assets, and canonical development commands.

## Requirements

### Requirement: Locked and Auditable Runtime Inputs

The project MUST commit its dependency manifest and resolved lock, and MUST record the source, verified version, checksum, and review date of locally served HTMX and Bulma assets. Exact versions MUST be selected only after local compatibility verification and pinning.

#### Scenario: Install from committed inputs

- GIVEN a fresh checkout and compatible prerequisite tools
- WHEN the setup command installs dependencies and assets
- THEN it MUST use the committed lock and provenance records
- AND a repeated install MUST resolve the same recorded inputs

#### Scenario: Runtime input cannot be verified

- GIVEN a locked dependency or asset does not match its recorded input
- WHEN setup verifies the installation
- THEN setup MUST fail without silently selecting another version

### Requirement: Safe Environment Configuration

The project MUST commit a non-secret `.env.example`, MUST ignore local secret-bearing environment files, and MUST validate required configuration before serving or database work. Failures MUST NOT disclose secret values.

#### Scenario: Configure from the example

- GIVEN a fresh checkout with no local environment file
- WHEN the documented configuration step is followed
- THEN all required variables MUST be discoverable from `.env.example`
- AND no real credential MUST be supplied by the repository

#### Scenario: Configuration is missing or invalid

- GIVEN a required variable is absent or malformed
- WHEN a canonical command starts
- THEN it MUST fail before dependent work begins
- AND its diagnostic MUST identify the field without exposing secrets

### Requirement: Canonical Local Commands

Composer MUST expose canonical `setup`, `serve`, `migrate`, `seed`, `test`, and `analyse` commands. Their documented order MUST bootstrap a fresh checkout, and rerunning the bootstrap MUST leave it usable without duplicate seed effects.

#### Scenario: Bootstrap a fresh checkout

- GIVEN prerequisites and valid local configuration
- WHEN `setup`, `migrate`, `seed`, `test`, and `analyse` run in the documented order
- THEN each command MUST complete successfully
- AND `serve` MUST make the foundation HTTP proof reachable

#### Scenario: Repeat local bootstrap

- GIVEN the checkout was already bootstrapped
- WHEN the documented bootstrap sequence runs again
- THEN it MUST complete without corrupting runtime or development data

### Requirement: Build-Free Browser Runtime

The browser experience MUST use locally served HTMX and Bulma assets, MUST require no frontend build, and MUST NOT depend on a CDN at runtime. Custom JavaScript MUST remain limited to behavior unavailable through server-rendered HTML and HTMX conventions.

#### Scenario: Serve without external asset access

- GIVEN dependencies were installed and external network access is unavailable
- WHEN a foundation page is requested
- THEN its required browser assets MUST be served locally
- AND no frontend compilation MUST be required

### Requirement: Foundation Verification Entry Point

The `test` command MUST execute at least one concept-free infrastructure proof and MUST fail with a non-zero result when that proof fails, enabling future strict TDD without introducing business behavior.

#### Scenario: Run the foundation test command

- GIVEN a valid bootstrapped environment
- WHEN `composer test` runs
- THEN it MUST verify a non-business runtime capability
- AND it MUST report a failing result when the observed contract is violated
