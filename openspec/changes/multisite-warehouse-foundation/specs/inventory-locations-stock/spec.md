# inventory-locations-stock Specification

## Purpose

Define generic physical storage locations, associative multi-location decimal stock, and observational inventory count logging under R1, extended with warehouse ownership, nullable-safe transitional visibility, one-time atomic legacy mapping, parent immutability, referential deletion guards, and dynamic stock rollups.

## MODIFIED Requirements

### Requirement: Physical Storage Locations

The system MUST allow registering physical storage locations identified by an enterprise-wide unique code, an optional description, and a mandatory reference to an existing active warehouse (`almacen`). Locations MUST belong to exactly one warehouse. The location code MUST remain globally unique across all branches and warehouses. During the transitional ADAPT phase (prior to final schema contract enforcement), new location creation requests MUST validate and require an active warehouse reference at the application layer, preventing any new unassigned locations from being created. Legacy locations that have not yet been assigned to a warehouse MUST remain fully visible in queries through nullable-safe operations, displaying their warehouse and branch attribution as unassigned/pending mapping without fabricating placeholder labels.

(Previously: Locations MUST remain independent from store, branch, or warehouse management entities.)

#### Scenario: Register a new physical location

- GIVEN an existing active warehouse
- AND an enterprise-wide unique location code
- WHEN a location is created with the code, an optional description, and the warehouse reference
- THEN the location record MUST be created successfully with active status and associated with the warehouse

#### Scenario: Reject duplicate location code

- GIVEN an existing location code associated with any warehouse
- WHEN a location creation request specifies the same code for any warehouse
- THEN the system MUST reject the request with a uniqueness validation error
- AND no duplicate location record MUST be stored

#### Scenario: Reject location assignment to nonexistent or inactive warehouse

- GIVEN a location creation request referencing a nonexistent or inactive warehouse ID
- WHEN the request is submitted
- THEN the system MUST reject the request with a validation error
- AND no location record MUST be created

#### Scenario: Render unmapped legacy locations through nullable-safe queries

- GIVEN an existing legacy location with `id_almacen IS NULL`
- WHEN location or inventory queries are executed
- THEN the system MUST include the legacy location in the result set
- AND the warehouse and branch attribution MUST indicate pending mapping without fabricating artificial facility names

---

## ADDED Requirements

### Requirement: One-Time Atomic Transitional Location Mapping

The system MUST provide an administrative mechanism (`map-location`) to transitional assign legacy locations (`id_almacen IS NULL`) to legitimate active warehouses. The mapping operation MUST be strictly one-time and atomic: it MUST succeed only if the target location exists, the target warehouse exists and is active, and the location currently has `id_almacen IS NULL`. The operation MUST reject remapping an already-assigned location, repeated mappings to the same warehouse, or attempts to remap after contract enforcement. The operation MUST execute atomically such that concurrent mapping requests cannot produce duplicate or conflicting assignments. All existing location IDs, codes, descriptions, stock position IDs, stock quantities, and observational count records MUST be strictly preserved without mutation.

#### Scenario: Successfully map unassigned legacy location

- GIVEN an existing legacy location with `id_almacen IS NULL`
- AND an active warehouse W1
- WHEN an administrator executes the mapping command for that location to warehouse W1
- THEN the system MUST atomically update `ubicacion.id_almacen` to warehouse W1
- AND all existing stock positions and count records for that location MUST remain intact

#### Scenario: Reject remapping already-assigned location

- GIVEN an existing location that already has an assigned warehouse W1
- WHEN an administrator or command attempts to map the location to warehouse W2 or re-map it to warehouse W1
- THEN the system MUST reject the mapping request with an immutability validation error
- AND the location's warehouse assignment MUST remain W1

#### Scenario: Reject mapping to nonexistent or inactive warehouse

- GIVEN an existing unassigned legacy location
- WHEN a mapping request references an inactive or nonexistent warehouse
- THEN the system MUST reject the mapping request with a validation error
- AND the location MUST remain unassigned

---

### Requirement: Location Parent Immutability and Deletion Guard

Once a physical storage location has been assigned to a warehouse under the final contract, changing its parent warehouse (`id_almacen`) through normal application operations MUST NOT be supported. The system MUST prohibit modifying `id_almacen` via HTTP requests, UI forms, or standard commands to protect the historical attribution of recorded stock positions and observational physical counts. Furthermore, physical deletion of a location MUST remain prohibited whenever any stock position records exist (even with zero quantity) or whenever observational inventory counts reference that location.

#### Scenario: Reject location parent warehouse modification

- GIVEN an existing location L1 assigned to warehouse W1
- WHEN an update request attempts to change the warehouse assignment of L1 to warehouse W2
- THEN the system MUST reject the request with an immutability validation error
- AND the warehouse assignment of L1 MUST remain W1

#### Scenario: Reject location deletion with existing stock records

- GIVEN an existing location L1 referenced by one or more stock position records
- WHEN a deletion request for location L1 is submitted
- THEN the system MUST reject the deletion with a referential integrity error
- AND location L1 MUST NOT be deleted

---

### Requirement: Warehouse and Branch Stock Roll-Up Aggregation

The system MUST compute and provide aggregated stock quantities per warehouse and per branch by summing quantities across all member physical locations where stock positions exist. The system MUST NOT store redundant or denormalized warehouse or branch stock totals in the database, preserving strict Third Normal Form (3NF).

#### Scenario: Compute warehouse total stock for a product

- GIVEN product P with stock 10.000 in location L1 (belonging to warehouse W1)
- AND stock 5.500 in location L2 (belonging to warehouse W1)
- AND stock 3.000 in location L3 (belonging to warehouse W2)
- WHEN warehouse stock is queried for product P in warehouse W1
- THEN the system MUST return exactly 15.500

#### Scenario: Compute zero stock for warehouse without positions

- GIVEN product P with no stock positions in warehouse W1
- WHEN warehouse stock is queried for product P in warehouse W1
- THEN the system MUST return exactly 0.000

#### Scenario: Compute branch total stock for a product

- GIVEN product P with stock 15.500 across warehouses in branch B1
- AND stock 8.000 in branch B2
- WHEN branch stock is queried for product P in branch B1
- THEN the system MUST return exactly 15.500

---

### Requirement: Location Route Authorization Matrix

The system MUST enforce role-based access control over location management routes aligned with `RouteAccessPolicy` and structurally protected by `RoleGuard`. Authenticated requests to registered routes lacking an explicit role mapping return HTTP 403 Forbidden.

The normative route matrix for locations is:
- `GET /locations`: `administrador`, `bodeguero`
- `POST /locations`: `administrador`, `bodeguero`

#### Scenario: Authorized roles access location endpoints

- GIVEN an authenticated session with role "administrador" or "bodeguero"
- WHEN the user accesses `GET /locations` or submits a valid location creation to `POST /locations`
- THEN the system MUST authorize and process the request successfully

#### Scenario: Unauthorized roles denied location endpoints

- GIVEN an authenticated session with role "cajero" or "compras"
- WHEN the user attempts to access `GET /locations` or `POST /locations`
- THEN the system MUST deny the request with HTTP 403 Forbidden
