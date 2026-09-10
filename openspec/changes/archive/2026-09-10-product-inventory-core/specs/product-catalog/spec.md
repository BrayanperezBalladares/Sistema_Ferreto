# Product Catalog Specification

## Purpose

Define the centralized product catalog, optional category classification, current selling price, product deactivation, and server-rendered catalog search under R1.

## ADDED Requirements

### Requirement: Product Registration and Category Association

The system MUST allow registering a product with a name, optional description, current selling price, and an optional category reference. The category association MUST be optional (`id_categoria` nullable). The system MUST NOT require SKU or barcode identifiers.

#### Scenario: Register a product with a category

- GIVEN a valid category exists
- WHEN a product is registered with a name, valid price, and the category ID
- THEN the product record MUST be created with the specified category association
- AND its active status MUST default to active

#### Scenario: Register an unclassified product without a category

- GIVEN no category is selected
- WHEN a product is registered with a name and valid price
- THEN the product record MUST be created with a null category reference

#### Scenario: Reject product registration with negative price

- GIVEN a product registration request
- WHEN the provided price is negative
- THEN the system MUST reject the registration with a validation error
- AND no product record MUST be created

---

### Requirement: Current Selling Price Management

The system MUST maintain a single current selling price for each product. The price MUST NOT be negative. The system MUST NOT maintain historical pricing records or temporal price tiers.

#### Scenario: Update product selling price

- GIVEN an existing product
- WHEN its selling price is updated to a non-negative decimal value
- THEN the product's current selling price MUST reflect the new value

#### Scenario: Reject negative selling price update

- GIVEN an existing product
- WHEN an update attempts to set a negative selling price
- THEN the system MUST reject the update
- AND the existing selling price MUST remain unchanged

---

### Requirement: Product Lifecycle and Reactivation

The system MUST maintain a reversible lifecycle for product records (`ACTIVE` <-> `INACTIVE`). A newly registered product begins in the `ACTIVE` state. An active product may be deactivated, and an inactive product may subsequently be activated again. Both transitions MUST operate on the existing database row, preserving the original `id_producto`, attributes, and all existing inventory, count, and historical references. Physical deletion (`DELETE`) remains unsupported and strictly prohibited. Reactivation MUST NOT create a duplicate or replacement product record.

#### Scenario: Deactivate active product

- GIVEN an existing active product
- WHEN deactivation is requested
- THEN its active status MUST be marked inactive (`estado_activo = 0`)
- AND the existing product record MUST remain in the database with unchanged `id_producto`
- AND existing stock or count records referencing the product MUST remain intact

#### Scenario: Reactivate inactive product

- GIVEN an existing inactive product
- WHEN activation is requested
- THEN its active status MUST be marked active (`estado_activo = 1`)
- AND the product record MUST maintain its existing `id_producto`
- AND no duplicate or replacement product record is created

#### Scenario: Preserve references across lifecycle changes

- GIVEN a product with existing stock positions or observational count references
- WHEN the product is deactivated and subsequently activated again
- THEN all referenced product identities and relational associations MUST remain intact throughout both transitions

#### Scenario: Physical deletion remains unsupported

- GIVEN an existing product in either active or inactive state
- WHEN lifecycle state transitions occur
- THEN the system MUST NOT execute physical `DELETE` statements or remove the database row

---

### Requirement: Category Management

The system MUST allow creating product categories with a name and optional description. Category names MUST be unique.

#### Scenario: Create a category with a unique name

- GIVEN a category name that does not exist
- WHEN a category is created with that name
- THEN the category record MUST be saved successfully

#### Scenario: Reject duplicate category name

- GIVEN an existing category name
- WHEN a new category is created with the same name
- THEN the system MUST reject the request with a validation error

---

### Requirement: Server-Rendered Catalog Search

The system MUST provide a server-rendered catalog interface that supports asynchronous searching by product name and displays product name, category name (or unclassified indicator), current price, and status.

#### Scenario: Search catalog with keyword match

- GIVEN active products exist matching the search term "Martillo"
- WHEN a search request is submitted for "Martillo"
- THEN the system MUST return a server-rendered HTML fragment containing only matching products

#### Scenario: Search catalog with no matching results

- GIVEN no products match the search term "Desconocido"
- WHEN a search request is submitted for "Desconocido"
- THEN the system MUST return an HTML fragment indicating no matching products were found
