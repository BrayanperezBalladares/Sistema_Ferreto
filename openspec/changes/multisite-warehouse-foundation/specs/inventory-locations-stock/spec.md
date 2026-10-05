# inventory-locations-stock Specification

## Purpose
Define generic physical storage locations, associative multi-location decimal stock, and observational inventory count logging under R1, extended with multisite branch and warehouse hierarchy.

## MODIFIED Requirements

### Requirement: Physical Storage Locations

The system MUST allow registering physical storage locations identified by an enterprise-wide unique code, an optional description, and a mandatory reference to an existing active warehouse (`almacen`). Locations MUST belong to exactly one warehouse. The location code MUST remain globally unique across all branches and warehouses.

#### Scenario: Register a new physical location

- GIVEN an existing active warehouse
- AND an enterprise-wide unique location code
- WHEN a location is created with the code, an optional description, and the warehouse reference
- THEN the location record MUST be created successfully with active status and associated with the warehouse

#### Scenario: Reject duplicate location code

- GIVEN an existing location code associated with a warehouse
- WHEN a location creation request specifies the same code for any warehouse
- THEN the system MUST reject the request with a validation error

#### Scenario: Reject location assignment to nonexistent or inactive warehouse

- GIVEN a location creation request referencing a nonexistent or inactive warehouse ID
- WHEN the request is submitted
- THEN the system MUST reject the request with a validation error
- AND no location record MUST be created

---

## ADDED Requirements

### Requirement: Commercial Branch Management

The system MUST allow registering and managing commercial branches (`sucursales`) identified by an enterprise-wide unique code, a required name, optional physical address and phone number, and a lifecycle active status.

#### Scenario: Register a new commercial branch

- GIVEN a unique branch code and a non-empty branch name
- WHEN the branch registration is submitted with optional address and phone
- THEN the system MUST create the branch record with active status

#### Scenario: Reject duplicate branch code

- GIVEN an existing branch with code "SUC-01"
- WHEN another branch creation request uses the code "SUC-01"
- THEN the system MUST reject the request with a validation error

#### Scenario: Deactivate branch

- GIVEN an existing active branch
- WHEN an administrator deactivates the branch
- THEN the branch status MUST transition to inactive

---

### Requirement: Warehouse Storage Facility Management

The system MUST allow registering and managing warehouse storage facilities (`almacenes`) belonging to a specific commercial branch. Each warehouse MUST be identified by a unique code, a required name, a mandatory branch reference, and a functional type restricted to `bodega` (general warehouse), `mostrador` (sales counter/shopfloor), `patio` (outdoor/bulk yard), and `merma` (damaged/quarantine area).

#### Scenario: Register a new warehouse for an active branch

- GIVEN an existing active branch
- AND a unique warehouse code, non-empty name, and valid type "bodega"
- WHEN the warehouse creation is submitted
- THEN the system MUST create the warehouse record with active status linked to the branch

#### Scenario: Reject duplicate warehouse code

- GIVEN an existing warehouse with code "ALM-01"
- WHEN another warehouse is created with code "ALM-01"
- THEN the system MUST reject the request with a validation error

#### Scenario: Reject invalid warehouse type

- GIVEN an existing active branch
- WHEN a warehouse creation specifies an unsupported type such as "transito" or "virtual"
- THEN the system MUST reject the request with a validation error

#### Scenario: Reject warehouse creation for inactive branch

- GIVEN an inactive branch
- WHEN a warehouse creation is requested for that branch
- THEN the system MUST reject the request with a validation error

---

### Requirement: Warehouse Stock Roll-Up Aggregation

The system MUST compute and provide aggregated stock quantities per warehouse and per branch by summing quantities across all member physical locations where stock positions exist. The system MUST NOT store redundant or denormalized warehouse/branch stock totals in the database.

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

---

### Requirement: Location Deletion and Reparenting Guard

The system MUST preserve transactional and audit integrity by preventing deletion of physical locations that hold active stock or historical inventory counts. The system MUST prohibit reparenting a physical location to a different warehouse once stock positions or inventory counts have been recorded against it.

#### Scenario: Reject location deletion with existing stock positions

- GIVEN an existing location L1 associated with one or more stock records
- WHEN a deletion request for location L1 is submitted
- THEN the system MUST reject the deletion with an integrity error

#### Scenario: Reject location reparenting when stock or count history exists

- GIVEN location L1 in warehouse W1 with historical stock positions or count records
- WHEN an update request attempts to change the warehouse of L1 to warehouse W2
- THEN the system MUST reject the reparenting request with a validation error
- AND the warehouse association of L1 MUST remain W1
