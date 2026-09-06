# Project Foundation Research

```yaml
schema: gentle-ai.sdd-research/v1
revision: 1
change: project-foundation
outcome: blocked
selected: true
admission:
  capability_schema: gentle-ai.sdd-research-capability/v1
  status: denied
  reason: >-
    No exact capability declaration with non-empty documentation or open-web
    grants was admitted for this execution. The declared grants are
    documentation=[] and open-web=[].
  observed_grants:
    documentation: []
    open-web: []
questions:
  - id: php-composer
    requested_classes: [documentation]
    question: PHP supported versions and Composer official compatibility, lock, and platform workflow.
  - id: router
    requested_classes: [documentation, open-web]
    question: One small maintained PHP router candidate: official compatibility, release, dependency, and security metadata.
  - id: migrations-mariadb-ddl
    requested_classes: [documentation, open-web]
    question: Migration tooling shortlist and MariaDB DDL and transaction behavior.
  - id: mariadb-pdo
    requested_classes: [documentation]
    question: MariaDB production and local version plus PHP PDO MySQL DSN, options, and transactions.
  - id: phpunit-phpstan
    requested_classes: [documentation]
    question: PHPUnit and PHPStan supported PHP versions and canonical scripts.
  - id: htmx-bulma
    requested_classes: [documentation]
    question: HTMX and Bulma release and distribution guidance, vendoring/SRI/provenance, and response-header conventions.
  - id: dotenv-logging
    requested_classes: [documentation, open-web]
    question: dotenv and logging candidates versus native environment variables and error_log.
sources: []
validated_claims: []
contradictions: []
uncertainty:
  - All selected research questions remain unvalidated because source admission was denied.
freshness: unavailable
product_choices:
  status: pending
  entries: []
recovery:
  required: true
  retained_intent: >-
    Complete external research was selected before Proposal for all seven listed
    lanes and requested source classes.
  canonical_desired_content: >-
    Collect auditable, current, source-backed evidence for every selected lane,
    map validated claims to admitted source IDs, then update pre-proposal state.
  next_action: >-
    Re-enter the research phase with an exact
    gentle-ai.sdd-research-capability/v1 declaration granting the required
    documentation and/or open-web classes.
```

No external sources were accessed and no source-backed claims are recorded.
