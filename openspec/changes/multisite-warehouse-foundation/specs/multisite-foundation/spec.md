# Multisite Foundation Specification

## Purpose

Define commercial branch (`sucursal`) and warehouse storage facility (`almacen`) entity management, operational lifecycles, structural creation and reactivation guards, parent immutability, and route authorization policies across Ferreterías El Constructor.

## ADDED Requirements

### Requirement: Commercial Branch Management

The system MUST allow registering and managing commercial branches (`sucursales`) identified by an enterprise-wide unique alphanumeric code, a required branch name, a required city of operation, an optional street address, an optional contact telephone number, and an explicit active lifecycle status.

#### Scenario: Register a new commercial branch

- GIVEN a unique branch code "SUC-01", a non-empty name "Sucursal Central", and a city "Managua"
- WHEN the branch registration is submitted with optional address and phone
- THEN the system MUST create the branch record with active status

#### Scenario: Reject duplicate branch code

- GIVEN an existing branch with code "SUC-01"
- WHEN another branch creation request uses the code "SUC-01"
- THEN the system MUST reject the request with a uniqueness validation error
- AND no duplicate branch record MUST be stored

#### Scenario: Reject empty branch name or city

- GIVEN a branch creation request with a unique code but an empty name or missing city
- WHEN the request is submitted
- THEN the system MUST reject the request with a validation error
- AND no branch record MUST be created

#### Scenario: Toggle branch active status

- GIVEN an existing active branch
- WHEN an administrator toggles the branch status
- THEN the branch status MUST transition to inactive
- AND subsequent toggling MUST transition the branch back to active

---

### Requirement: Warehouse Storage Facility Management

The system MUST allow registering and managing warehouse storage facilities (`almacenes`) belonging to a specific commercial branch. Each warehouse MUST be identified by an enterprise-wide unique alphanumeric code, a required name, a mandatory reference to an active branch, an explicit active lifecycle status, and a functional storage type restricted to `bodega` (general storage warehouse), `mostrador` (sales counter / shopfloor picking area), `patio` (bulk outdoor / yard storage), and `merma` (damaged goods / quarantine area). Transit warehouse semantics are intentionally deferred to the future transfer capability.

#### Scenario: Register a new warehouse for an active branch

- GIVEN an existing active branch
- AND an enterprise-wide unique warehouse code "ALM-01", a name "Almacén Principal", and valid type "bodega"
- WHEN the warehouse creation is submitted
- THEN the system MUST create the warehouse record with active status linked to the branch

#### Scenario: Reject duplicate warehouse code

- GIVEN an existing warehouse with code "ALM-01"
- WHEN another warehouse creation request uses the code "ALM-01"
- THEN the system MUST reject the request with a uniqueness validation error
- AND no duplicate warehouse record MUST be stored

#### Scenario: Reject unsupported warehouse type

- GIVEN an existing active branch
- WHEN a warehouse creation specifies an unsupported type such as "transito" or "virtual"
- THEN the system MUST reject the request with a domain validation error
- AND no warehouse record MUST be created

#### Scenario: Reject warehouse creation for inactive branch

- GIVEN an existing branch whose status is inactive
- WHEN a warehouse creation is requested for that branch
- THEN the system MUST reject the request with a structural validation error
- AND no warehouse record MUST be created

#### Scenario: Toggle warehouse active status under active branch

- GIVEN an existing active warehouse whose parent branch is active
- WHEN an administrator toggles the warehouse status
- THEN the warehouse status MUST transition to inactive
- AND subsequent toggling MUST transition the warehouse back to active

---

### Requirement: Facility Lifecycle and Structural Creation Guards

The system MUST enforce independent lifecycle statuses for branches and warehouses without automatic cascading. Deactivating a branch MUST NOT automatically deactivate its member warehouses or locations. Deactivating a warehouse MUST NOT automatically deactivate its member locations. Structural checks ("active parent required") are creation and reactivation guards, not continuous constraints that would invalidate independent statuses after a parent is deactivated. Specifically:
1. Creating a warehouse requires that the parent branch is currently active.
2. Reactivating an inactive warehouse requires that the parent branch is currently active.
3. Creating a physical location requires that the parent warehouse is currently active.
4. Deactivating a parent branch or warehouse preserves all existing child statuses.
5. All historical stock positions and observational physical-count records under inactive facilities MUST remain fully visible and included in aggregate queries.

#### Scenario: Inactive branch prohibits new warehouse creation

- GIVEN an inactive commercial branch
- WHEN an administrator attempts to create a new warehouse under that branch
- THEN the system MUST reject the creation with a validation error
- AND no warehouse record MUST be created

#### Scenario: Inactive branch prohibits warehouse reactivation

- GIVEN an inactive commercial branch
- AND an inactive warehouse belonging to that branch
- WHEN an administrator attempts to reactivate the warehouse
- THEN the system MUST reject the reactivation with a structural validation error
- AND the warehouse status MUST remain inactive

#### Scenario: Inactive warehouse prohibits new location creation

- GIVEN an inactive warehouse storage facility
- WHEN a user attempts to create a new physical storage location under that warehouse
- THEN the system MUST reject the creation with a validation error
- AND no location record MUST be created

#### Scenario: Inactive branch preserves member warehouse status and stock visibility

- GIVEN an active branch containing an active warehouse with recorded stock positions
- WHEN the branch is deactivated
- THEN the warehouse status MUST remain active
- AND all existing stock positions within that warehouse MUST remain visible in catalog and stock roll-up queries

---

### Requirement: Warehouse Parent Immutability

Once a warehouse storage facility is created and associated with a parent commercial branch, changing its parent branch reference (`id_sucursal`) through normal application operations MUST NOT be supported. Parent relationships are immutable after creation.

#### Scenario: Reject modification of warehouse parent branch

- GIVEN an existing warehouse associated with branch B1
- WHEN an update request attempts to modify the warehouse's parent branch to branch B2
- THEN the system MUST reject the modification request
- AND the warehouse MUST remain associated with branch B1

---

### Requirement: Facility Route Authorization Matrix

The system MUST enforce strict role-based access control over all commercial branch and warehouse management routes, aligned with `RouteAccessPolicy` and structurally protected by `RoleGuard`. Authenticated requests to any registered, non-exempt route lacking an explicit role mapping MUST return HTTP 403 Forbidden. Branch-level object authorization is not implemented; user accounts are not scoped by branch in this capability.

The normative route matrix is:
- `GET /branches`: `administrador`
- `POST /branches`: `administrador`
- `POST /branches/toggle-active`: `administrador`
- `GET /warehouses`: `administrador`, `bodeguero`
- `POST /warehouses`: `administrador`
- `POST /warehouses/toggle-active`: `administrador`

#### Scenario: Administrator accesses branch and warehouse mutations

- GIVEN an authenticated session with role "administrador"
- WHEN the administrator submits requests to create branches, toggle branch status, create warehouses, or toggle warehouse status
- THEN the system MUST authorize and process the requests successfully

#### Scenario: Bodeguero accesses warehouse list but is denied mutations

- GIVEN an authenticated session with role "bodeguero"
- WHEN the user accesses `GET /warehouses`
- THEN the system MUST permit access and render the warehouse catalog
- WHEN the user submits a mutation request to `POST /warehouses` or `POST /branches`
- THEN the system MUST deny the request with HTTP 403 Forbidden

#### Scenario: Non-inventory roles denied all facility routes

- GIVEN an authenticated session with role "cajero" or "compras"
- WHEN the user attempts to access any branch or warehouse route (`/branches`, `/warehouses`)
- THEN the system MUST deny the request with HTTP 403 Forbidden

#### Scenario: Unmapped registered route returns fail-closed 403

- GIVEN an authenticated session with any valid role
- WHEN a request targets a registered protected route that lacks an explicit mapping in `RouteAccessPolicy`
- THEN the system MUST reject the request with HTTP 403 Forbidden without invoking the handler
