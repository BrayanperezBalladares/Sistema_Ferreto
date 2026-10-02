# Project Context — Sistema Ferreto

> **Audience**: AI Coding Agents & Engineering Team
> **Domain**: Hardware Retail & Wholesale Enterprise Management
> **Business Entity**: Ferreterías El Constructor

---

## 1. Business Domain Overview

**Sistema Ferreto** is the core operational enterprise software engineered for **Ferreterías El Constructor**, a medium-to-large hardware, building materials, and tools commercial enterprise.

The enterprise operates both retail walk-in customer sales and wholesale commercial accounts for contractors and builders. Its business operations require:
- High operational availability and low latency during checkout and inventory lookup.
- Strict consistency in stock quantities, pricing, and accounting totals.
- Strong access control separating operational responsibilities (cashiers, warehouse keepers, buyers, administrators).
- A durable, auditable record of all stock and catalog changes.

---

## 2. Roles & Actors

The system defines four authoritative roles within the operational domain:

| Role Identifier (`rol`) | Spanish UI Label | Responsibilities & Operational Scope |
|---|---|---|
| `administrador` | **Administrador** | Complete operational and configuration authority. Creates categories, registers products, updates prices, activates/deactivates items, manages locations, registers stock placements per location, performs observational physical counts, and creates/unlocks system accounts. *(Note: formal stock adjustments and reconciliation workflows are non-implemented future capabilities).* |
| `bodeguero` | **Bodeguero** | Warehouse and physical inventory operations. Reads catalog; creates and manages warehouse locations; registers stock locations and initial balances; records physical inventory count observations. Denied price changes, product creation, and account administration. |
| `cajero` | **Cajero** | Retail checkout and front-desk customer service. Reads catalog and prices for customer inquiries. Denied inventory modifications, count recording, product editing, and administration. *(In future phases: manages cash registers, processes sales, prints invoices).* |
| `compras` | **Compras** | Purchasing and supplier procurement. Reads product catalog and pricing. Denied inventory mutations and administrative functions. *(In future phases: creates purchase orders, manages vendor catalog, receives shipments).* |

---

## 3. Operational vs. Analytical Boundary

A foundational architectural principle of Sistema Ferreto is the strict separation between **Operational (OLTP)** and **Analytical (OLAP)** systems:

```
+-------------------------------------------------------------------------+
|                        SISTEMA FERRETO (OLTP)                           |
|  - Real-time transactional engine (MariaDB InnoDB)                      |
|  - Authoritative source of truth for stock, prices, accounts, and users |
|  - Strict normalization, foreign key constraints, and row locks         |
|  - Fast, bounded queries optimized for interactive web workflows        |
+-------------------------------------------------------------------------+
                                    |
                                    | (Future: Read Replica / ETL Stream)
                                    v
+-------------------------------------------------------------------------+
|                    FUTURE ANALYTICAL PLATFORM (OLAP)                    |
|  - Business Intelligence (BI) dashboards & trend reporting              |
|  - Historical time-series analytics (e.g. inventory turnover)           |
|  - Machine learning demand forecasting                                  |
|  - MUST NEVER run heavy reporting queries directly on the live OLTP DB  |
+-------------------------------------------------------------------------+
```

---

## 4. Requirements Taxonomy

To prevent agent hallucinations and speculative implementations, all requirements must be categorized into one of three classifications:

### A. IMPLEMENTED (Authoritative & Verified)
Features that exist in production code, verified by automated tests and documented in canonical OpenSpec specs (`openspec/specs/`):
- **Reproducible Runtime**: Zero-build asset pipeline, Bulma + HTMX vendored locally, PSR-4 autoloading, custom Console CLI.
- **Transactional Foundation**: Native PDO wrapper, explicit Transactions, deterministic migrations (`0001`–`0007`), technical tables (`schema_migrations`, `infrastructure_probe`).
- **Product Catalog (R1)**: Category management (`categoria`), product creation (`producto`), price updates (`DECIMAL(12,2)` in `precio_actual`), activation/deactivation, HTMX real-time search. (No SKU, barcode, or unique product name constraint).
- **Inventory & Locations (R1)**: Generic physical locations (`ubicacion`), product stock mapping (`inventario_stock` with `DECIMAL(12,3)`), observational physical counts (`conteo_inventario`).
- **Access Control Foundation**: Native sessions, bcrypt authentication, timing attack mitigation, 6-attempt lockout, inactivity timeouts (20m cashier / 30m others), `RouteAccessPolicy`, `AuthGuard`, `RoleGuard`, `ViewPermissions`, fail-closed UI suppression.

### B. FUTURE REQUIREMENT (Planned, Non-Implemented)
Features identified in the project vision or roadmap, but **NOT YET IMPLEMENTED**:
- Stock reconciliation and administrative stock adjustment/shrinkage workflows.
- SKU and barcode identifiers for products.
- Point of Sale (POS) checkout, sales orders, payment processing, invoice generation.
- Purchasing orders, supplier catalogs, shipment receiving.
- Multi-branch / multi-warehouse stock transfers.
- Comprehensive immutable audit logging table.
- Read-replica ETL data pipeline.

> [!CAUTION]
> **Agents must never assume a "future requirement" is partially implemented or scaffold code for it without an explicit, authorized OpenSpec change.**

### C. OPEN DECISION (Architectural Fork Pending Discovery)
Architectural considerations that have not yet been decided by the core maintainers:
- **Barcode Scanner Integration**: USB HID keyboard emulation vs. WebRTC camera scanner.
- **Fiscal Printer / Electronic Invoicing**: Direct protocol interface vs. background worker queue.
- **Session Backend at Scale**: File-based native sessions vs. Redis session store for clustered deployments.

---

## 5. Organizational Boundaries & Assumptions

1. **Single Store / Central Warehouse Initial Scope**: Current R1 schema assumes a single physical enterprise facility with multiple locations (aisles, shelves, racks). Multi-store support will be introduced via an explicit branch entity in a later phase.
2. **Synchronous Request-Response Flow**: The current web application runs synchronously inside PHP-FPM / CLI server. Background workers and asynchronous queues are currently out of scope.
3. **Internal Network Deployment**: The system is designed for internal network operations (LAN / VPN) within hardware store branches, accessible via modern desktop and tablet browsers.
