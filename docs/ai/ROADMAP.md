# Technical Roadmap — Sistema Ferreto

> **Audience**: Engineering Team & AI Agents
> **Status**: Informational / Non-Authoritative
> **Authority**: Canonical specifications live exclusively under `openspec/specs/`.

---

## 1. Roadmap Disclaimer

> [!IMPORTANT]
> The milestones and capabilities described in this document represent the planned architectural trajectory of Sistema Ferreto. **They do not constitute authorization for speculative implementation.**
> Before any item from this roadmap is developed, it must undergo the formal OpenSpec proposal and specification process.

---

## 2. Planned Functional Milestones

```
+-----------------------------------------------------------------------------+
| RELEASE 1 (R1) - COMPLETE & VERIFIED BASELINE                               |
| - Foundation runtime, Modular Monolith, Database isolation                  |
| - Product catalog, Category management, Real-time search                    |
| - Warehouse locations, Stock placement, Observational counts                |
| - Access control, Authentication, Inactivity timeouts, Role-aware UI        |
+-----------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------+
| MILESTONE 2: ADVANCED INVENTORY & STOCK ADJUSTMENTS                         |
| - Formal stock reconciliation workflow (reconciling conteo with stock)     |
| - Shrinkage, damage, and administrative write-off adjustments               |
| - Minimum, maximum, and reorder point stock thresholds                      |
| - Barcode scanning integration (EAN-13, Code 128)                           |
+-----------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------+
| MILESTONE 3: POINT OF SALE (POS) & RETAIL CHECKOUT                          |
| - Cashier shift management (apertura y cierre de caja)                      |
| - Sales ticket / order line-item calculation (subtotal, taxes, discount)    |
| - Payment methods: cash, card, credit transfer                              |
| - Receipt and invoice generation (fiscal printer / PDF)                     |
+-----------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------+
| MILESTONE 4: PROCUREMENT & SUPPLIER MANAGEMENT                              |
| - Supplier (proveedor) master entity and contact management                 |
| - Purchase Orders (órdenes de compra) lifecycle (draft, approved, sent)     |
| - Goods receipt matching against PO lines with partial delivery tracking    |
| - Cost history and margin tracking per product                              |
+-----------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------+
| MILESTONE 5: MULTI-BRANCH LOGISTICS & TRANSFERS                             |
| - Branch (sucursal) entity definition                                       |
| - Inter-branch stock transfer requests and authorizations                    |
| - In-transit stock tracking and receiving reconciliation                    |
+-----------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------+
| MILESTONE 6: AUDIT SUBSYSTEM & COMPLIANCE                                   |
| - Centralized, append-only immutable audit trail table (`audit_log`)        |
| - Automatic audit event capture for all state mutations via Kernel/Handlers |
| - Security audit log viewer for administrators                              |
+-----------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------+
| MILESTONE 7: ANALYTICAL & BI SUBSYSTEM                                      |
| - Read-replica database decoupling                                          |
| - Asynchronous ETL pipeline for historical metrics                          |
| - Executive management dashboards (revenue, inventory turnover, shrinkage)  |
+-----------------------------------------------------------------------------+
```

---

## 3. Technical Debt & Architectural Guidelines for Future Work

When implementing any future milestone, agents must uphold the core architectural invariants:
1. **Zero Framework Bloat**: Do not introduce Symfony, Laravel, or heavyweight ORMs. Maintain the lightweight native PHP + PDO modular monolith.
2. **Deterministic Validation**: Maintain PHPStan Level `max` and 100% passing test suites.
3. **Fail-Closed Security**: New routes must be explicitly registered in `RouteAccessPolicy` and verified with dedicated role guard integration tests.
4. **Precision Invariants**: Maintain string-based / BCMath decimal handling for all currency and inventory units.
