# Inventory Locations and Stock Specification

## Purpose

Define generic physical storage locations, associative multi-location decimal stock, and observational inventory count logging under R1.

## ADDED Requirements

### Requirement: Physical Storage Locations

The system MUST allow registering physical storage locations identified by a unique code and optional description. Locations MUST remain independent from store, branch, or warehouse management entities.

#### Scenario: Register a new physical location

- GIVEN a unique location code
- WHEN a location is created with the code and an optional description
- THEN the location record MUST be created successfully with active status

#### Scenario: Reject duplicate location code

- GIVEN an existing location code
- WHEN a location creation request uses the same code
- THEN the system MUST reject the request with a validation error

---

### Requirement: Associative Multi-Location Stock

The system MUST track stock as an associative position between a product and a physical location. A product-location position MUST be unique. Stock quantities MUST be decimal values and MUST NOT be negative.

#### Scenario: Establish a valid stock position

- GIVEN an existing product and an existing location
- WHEN a stock position is established with a non-negative decimal quantity
- THEN the stock position record MUST store the quantity with three decimal places

#### Scenario: Reject duplicate stock position for same product and location

- GIVEN a stock position already exists for a product and location
- WHEN another stock position is created for the same product and location
- THEN the system MUST reject the creation to prevent duplicate stock records

#### Scenario: Reject negative stock quantity

- GIVEN an existing product and location
- WHEN a stock position creation specifies a negative quantity
- THEN the system MUST reject the request with a validation error
- AND no stock position MUST be created

---

### Requirement: Observational Inventory Count Recording

The system MUST record periodic physical inventory counts referencing an existing stock position. A count record MUST capture the recorded system quantity snapshot, the physically counted quantity, the calculated variance, and optional notes. A count record MUST be immutable once saved.

#### Scenario: Record an observational count with discrepancy

- GIVEN an existing stock position with a recorded quantity of 10.000
- WHEN a count is submitted with a physical quantity of 8.500 and optional notes
- THEN the system MUST save an immutable count record referencing the stock position
- AND the record MUST record system quantity 10.000, counted quantity 8.500, and variance -1.500

#### Scenario: Record an observational count with zero discrepancy

- GIVEN an existing stock position with a recorded quantity of 5.000
- WHEN a count is submitted with a physical quantity of 5.000
- THEN the count record MUST record system quantity 5.000, counted quantity 5.000, and variance 0.000

#### Scenario: Reject negative physical count input

- GIVEN an existing stock position
- WHEN a count is submitted with a negative counted quantity
- THEN the system MUST reject the count submission with a validation error

---

### Requirement: Non-Mutating Count Isolation

Recording an inventory count MUST NOT alter or adjust the recorded stock quantity of the associated stock position. The system MUST NOT provide an automated stock adjustment workflow in this change.

#### Scenario: Verify stock quantity remains unchanged after count recording

- GIVEN an existing stock position with quantity 15.000
- WHEN an observational count is recorded with a counted quantity of 12.000
- THEN the count record MUST be stored with variance -3.000
- AND the recorded stock quantity on the stock position MUST remain exactly 15.000
