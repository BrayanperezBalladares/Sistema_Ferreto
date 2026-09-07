# Ferreterías El Constructor — Canonical UI/UX Design System

> **Visual Source of Truth**
> Document Version: 1.0.0
> Status: Canonical Reference
> Target Application: Internal Operational Business System

---

## 1. Source of Truth & Authority Hierarchy

> [!IMPORTANT]
> **Authority Precedence:**
> Functional behavior is governed by **OpenSpec**.
> Technical architecture is governed by the approved **SDD design artifacts**.
> Visual presentation and UX consistency are governed by this **DESIGN.md**.
>
> When documents conflict:
> 1. Functional OpenSpec specifications win for behavior.
> 2. SDD architecture wins for implementation constraints.
> 3. DESIGN.md governs presentation only.
>
> DESIGN.md must never be used to invent unsupported functionality.

### Current Implementation Status Note
The Product & Inventory Catalog UI currently exists functionally but has not yet undergone visual refinement to this canonical design system. The provisional dark/unstyled layout must **NOT** be treated as a visual authority. All visual refinement work must align strictly with the rules defined herein on the dedicated refinement branch: `feature/product-inventory-ui-refinement`.

---

## 2. Visual Direction & Philosophy

### Industrial Enterprise Premium
The application serves as the core internal administrative and operational backbone of **Ferreterías El Constructor**. It handles high-volume catalog management, inventory tracking, physical storage allocations, and audit counts.

### Core Visual Attributes
- **Professional & Mature:** Calm, restrained, purpose-built aesthetic avoiding consumer flashiness.
- **Operational & Trustworthy:** Dense, legible information architecture designed for sustained all-day use.
- **Industrial Elegance:** Grounded in deep charcoal/graphite structures paired with a warm, restrained amber accent reminiscent of industrial precision tools and architectural steelwork.
- **Light Primary Workspace:** Data reading and manipulation happen on clean, high-contrast light surfaces to eliminate eye fatigue.

### Anti-Patterns (What We Are NOT)
- **NOT a Futuristic / Sci-Fi / Gamer UI:** No neon borders, dark-mode-only tables, glow effects, or decorative gradients.
- **NOT a Generic AI Dashboard:** No floating glassmorphic panels, gradient badges, purple accents, or oversized cards with meaningless graphs.
- **NOT an E-Commerce Marketing Site:** No hero carousels, marketing banners, oversized pill buttons, or decorative illustrations.
- **NOT a Consumer Mobile App:** No bottom navigation bars, full-card-per-row layouts, or excessive padding.
- **NOT a Legacy 90s ERP:** No cramped gray bevels, unstyled raw inputs, or unformatted data dumps.

---

## 3. Canonical Application Shell

The desktop workspace follows a persistent, high-efficiency two-column split shell:

```
┌──────────────────┬────────────────────────────────────────────────────────────────────────┐
│                  │ Topbar (Light, quiet, subtle bottom border)                            │
│                  ├────────────────────────────────────────────────────────────────────────┤
│  Sidebar         │                                                                        │
│  Charcoal        │ Page Header                                            Primary Action  │
│  (#111827)       │ Title & Operational Context Subtitle                                   │
│                  │                                                                        │
│  Branding        ├────────────────────────────────────────────────────────────────────────┤
│  Navigation      │ Filter / Search Bar (Live HTMX search, category/status filters)        │
│  Active Amber    ├────────────────────────────────────────────────────────────────────────┤
│  Indicator       │                                                                        │
│                  │ Primary Operational Content / High-Density Data Table                  │
│  Status / User   │ (Clean white surface, tabular data, aligned actions)                   │
│                  │                                                                        │
└──────────────────┴────────────────────────────────────────────────────────────────────────┘
```

### Component Roles

#### 1. Sidebar (Persistent Charcoal Shell)
- **Background:** Deep enterprise charcoal (`--color-nav: #111827`).
- **Identity:** Prominent company branding at top: "Ferreterías El Constructor" with a subtle industrial mark.
- **Structure:** Divided into clear functional sections (e.g., Catálogo, Almacén, Operaciones).
- **Active Navigation Item:** Subtly lighter background (`#1F2937`) with a solid amber left border accent (`--color-primary: #F59E0B`) and crisp white typography.
- **Inactive Navigation Items:** Slate gray typography (`#94A3B8`), highlighting to soft white on hover without loud backgrounds.
- **Icons:** Monochrome, stroke-aligned 20px SVG icons accompanying text labels.

#### 2. Topbar (Light & Visually Quiet)
- **Background:** Clean light surface (`--color-surface: #FFFFFF`).
- **Border:** Fine bottom separator (`1px solid --color-border: #E5E7EB`).
- **Elevation:** Zero or minimal shadow (`0 1px 2px rgba(0, 0, 0, 0.05)`).
- **Purpose:** Contextual breadcrumbs, current branch/store indicator, and user status. Must never compete with the page header or table actions.

#### 3. Main Workspace
- **Background:** Light neutral canvas (`--color-bg: #F8FAFC`).
- **Padding:** 24px on desktop.
- **Content Flow:** Clear top-to-bottom hierarchy: Page Header → Filters Bar → Data Table / Forms.

---

## 4. Design Tokens & Color System

All visual rules derive from semantic design tokens:

```css
:root {
  /* Brand & Shell */
  --color-nav: #111827;            /* Deep enterprise charcoal (Sidebar) */
  --color-nav-surface: #1F2937;    /* Active navigation item background */
  --color-nav-muted: #94A3B8;      /* Inactive navigation link text */
  --color-nav-text: #F9FAFB;       /* Active navigation link text */

  /* Neutrals & Surfaces */
  --color-bg: #F8FAFC;             /* Application workspace background */
  --color-surface: #FFFFFF;        /* Primary card, table, and modal background */
  --color-surface-subtle: #F1F5F9; /* Table header, filter bar, secondary surface */
  --color-border: #E5E7EB;         /* Standard borders, card dividers, table lines */
  --color-border-subtle: #F3F4F6;  /* Inner cell dividers */

  /* Typography */
  --color-text: #0F172A;           /* Primary text, headings, table contents */
  --color-text-muted: #64748B;     /* Secondary descriptions, subtitles, table headers */
  --color-text-subtle: #94A3B8;    /* Placeholders, disabled text */

  /* Accents & States */
  --color-primary: #F59E0B;        /* Restrained amber (Primary CTA, active indicator) */
  --color-primary-hover: #D97706;  /* Darker amber for hover states */
  --color-primary-subtle: #FEF3C7; /* Pale amber for badges and warnings */
  --color-primary-text: #78350F;   /* High-contrast amber text on subtle backgrounds */

  /* Semantic Feedback */
  --color-success: #10B981;        /* Active status, positive feedback */
  --color-success-subtle: #D1FAE5; /* Pale green badge background */
  --color-success-text: #065F46;   /* Dark green text on badge */

  --color-danger: #EF4444;         /* Destructive actions, validation errors */
  --color-danger-hover: #DC2626;   /* Darker red for button hover */
  --color-danger-subtle: #FEE2E2;  /* Pale red alert/badge background */
  --color-danger-text: #991B1B;    /* Dark red text on badge */

  --color-inactive: #64748B;       /* Inactive status badge text */
  --color-inactive-subtle: #F1F5F9;/* Inactive status badge background */

  /* Geometry & Spacing */
  --radius-sm: 6px;                /* Buttons, inputs, badges */
  --radius-md: 8px;                /* Cards, panels, tables, modals */
  --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
}
```

### Conceptual Color Balance Rules
- **85% Workspace:** Light neutrals (`#F8FAFC`, `#FFFFFF`, `#E5E7EB`, `#0F172A`).
- **10% Navigation Shell:** Deep charcoal (`#111827`).
- **5% Intentional Accents:**
  - **Amber (`#F59E0B`):** Reserved strictly for primary action buttons, active navigation markers, and unclassified warnings. Never used as a full page background or decorative bar.
  - **Green (`#10B981`):** Reserved exclusively for active status and verified successes.
  - **Red (`#EF4444`):** Reserved exclusively for destructive deactivation actions and field errors.

---

## 5. Typography

### Font Stack
```css
font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
```
*No complex frontend build chain or runtime font loader is required; uses system fallbacks cleanly.*

### Type Scale & Hierarchy

| Role | Size | Weight | Line Height | Color | Usage |
|---|---|---|---|---|---|
| **Page Title** | 28px (1.75rem) | 700 (Bold) | 1.2 | `--color-text` | Main title in page header |
| **Section Heading** | 18px (1.125rem) | 600 (Semibold) | 1.3 | `--color-text` | Card titles, modal headers |
| **Body / Standard** | 14px (0.875rem) | 400 (Regular) | 1.5 | `--color-text` | General content, descriptions |
| **Table Header** | 12px (0.75rem) | 600 (Semibold) | 1.2 | `--color-text-muted` | Uppercase table column headers |
| **Table Data** | 14px (0.875rem) | 400 / 500 | 1.4 | `--color-text` | Primary rows, numbers, codes |
| **Form Labels** | 13px (0.8125rem) | 600 (Semibold) | 1.2 | `--color-text` | Inputs, select labels |
| **Helper / Caption** | 12px (0.75rem) | 400 (Regular) | 1.4 | `--color-text-muted` | Input hints, secondary cell meta |
| **Badges / Tags** | 12px (0.75rem) | 600 (Semibold) | 1.0 | Contextual | Status chips |

---

## 6. Spacing, Sizing & Density Scale

The system enforces a strict 4px/8px modular rhythm:

| Token | Size | Application |
|---|---|---|
| `--space-1` | 4px | Inline icon gaps, badge vertical padding |
| `--space-2` | 8px | Button padding, input inner padding, compact gaps |
| `--space-3` | 12px | Form field vertical spacing, cell padding (vertical) |
| `--space-4` | 16px | Card padding, standard element margins |
| `--space-6` | 24px | Workspace page padding, modal inner body |
| `--space-8` | 32px | Major section separations |

### Operational Density Principles
- **Medium-High Density:** Operational users process hundreds of rows. Avoid airy spacing that forces excessive scrolling.
- **Table Row Height:** 44px–48px per row.
- **Control Heights:** 36px standard for buttons, inputs, and selects (30px for small inline buttons).

---

## 7. Shape, Surface & Elevation

### Borders & Corners
- **Small Controls (Buttons, Inputs, Selects, Badges):** `border-radius: 6px;`
- **Containers (Cards, Data Tables, Modals, Panels):** `border-radius: 8px;`
- **Never use pill-shaped containers (`border-radius: 9999px`) for buttons or table rows.**

### Elevation & Borders
- All cards, tables, and topbars must feature an explicit `1px solid var(--color-border)`.
- Use `--shadow-sm` on floating elements.
- Shadows exist only to indicate z-index layering (e.g. modals), never for decorative flair.

---

## 8. Button System

Buttons indicate clear intent and visual hierarchy.

```
┌─────────────────────────┐   ┌─────────────────────────┐   ┌─────────────────────────┐
│ Primary: Amber          │   │ Secondary: Light/Border │   │ Danger: Red             │
│ [ + Registrar producto ] │   │ [ Cancelar ]            │   │ [ Desactivar ]          │
└─────────────────────────┘   └─────────────────────────┘   └─────────────────────────┘
```

### 1. Primary Action (`.btn-primary`)
- **Background:** `--color-primary: #F59E0B` (Hover: `#D97706`).
- **Text:** White (`#FFFFFF`) or high-contrast dark charcoal (`#111827`).
- **Weight:** 600 (Semibold).
- **Usage:** Exactly one primary button per screen or modal (e.g., "Registrar producto", "Guardar categoría").

### 2. Secondary Action (`.btn-secondary`)
- **Background:** Light surface (`#FFFFFF`), hover: `#F8FAFC`.
- **Border:** `1px solid --color-border: #E5E7EB`.
- **Text:** `--color-text: #0F172A`.
- **Usage:** "Cancelar", "Limpiar filtros", "Cerrar".

### 3. Danger Action (`.btn-danger`)
- **Background:** `--color-danger: #EF4444` (Hover: `#DC2626`).
- **Text:** White (`#FFFFFF`).
- **Usage:** Reserved strictly for destructive/soft-deleting confirmations (e.g., "Desactivar"). Never use red for standard secondary actions.

---

## 9. Form System & Validation Rules

Forms must be clear, error-tolerant, and straightforward.

### Field Layout
- **Labels:** Positioned directly **above** inputs (`margin-bottom: 4px; font-weight: 600;`).
- **Required Indicator:** Subtle red asterisk `*` applied only when a field is mandatory.
- **Helper Text:** Muted 12px text placed below the input.
- **Focus Ring:** Clean 2px amber accent (`outline: 2px solid rgba(245, 158, 11, 0.4); border-color: #F59E0B;`).

### Field-Specific Validation Feedback
- Errors must be rendered directly below the affected input in `--color-danger-text` (`#991B1B`) with a subtle red input border.
- **Never expose technical database column names** (`precio_actual`, `id_categoria`).
- **Never expose English backend exception text.**
- **User-Facing Copy Examples:**
  - *Correct:* "El precio debe ser un número positivo con hasta 2 decimales."
  - *Incorrect:* "precio_actual: Price must be a valid non-negative decimal string."
  - *Correct:* "El nombre del producto es obligatorio."
  - *Incorrect:* "Field 'nombre' cannot be null."

---

## 10. Data Table System

Data tables are the operational heart of the application.

```
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│ PRODUCTO               CATEGORÍA       PRECIO ACTUAL        ESTADO        ACCIONES             │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ Martillo Galponero     Herramientas           $24.50       [ Activo ]     Actualizar | Desact. │
│ 16oz acero forjado                                                                              │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ Clavos de Acero 2"     [Sin clasificar]        $4.00       [ Activo ]     Actualizar | Desact. │
│ Caja 100 unidades                                                                               │
├─────────────────────────────────────────────────────────────────────────────────────────────────┤
│ Disco de Corte 4-1/2"  Herramientas            $3.20      [ Inactivo ]    Actualizar           │
└─────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Table Specifications
- **Container:** White card with `1px solid --color-border` and `border-radius: 8px`.
- **Header Row:** Background `--color-surface-subtle: #F1F5F9`, text uppercase 12px `--color-text-muted`, bottom border `1px solid --color-border`.
- **Cell Alignment:**
  - Text, names, categories: **Left-aligned**.
  - Quantities, prices, financial values: **Right-aligned** (tabular numeric layout).
  - Status badges, actions: **Left-aligned** or **Right-aligned** consistently.
- **Row Hover:** Subtle highlight (`#F8FAFC`) to assist horizontal eye tracking across wide columns.
- **Product Cell:** Product name rendered bold in `--color-text`; optional description rendered in 12px muted text underneath.
- **Empty State:** When zero records match, display a clean bordered panel with an informative operational message (see Section 15).

---

## 11. Canonical Catalog Screen Specifications

The approved catalog screen (`/products`) unites the shell, controls, and data grid:

### 1. Page Header
- **Title:** `Catálogo de productos`
- **Subtitle:** `Gestión de artículos, precios de venta y disponibilidad.`
- **Primary CTA:** Button `[ + Registrar producto ]` (triggers modal).

### 2. Search & Filter Bar
- A unified card directly above the table containing:
  - **Live Search Input:** Placeholder `Buscar por nombre de producto...` with HTMX debounced triggers (`hx-get="/products"`, `hx-trigger="keyup changed delay:300ms, search"`).
  - **Filter Controls:** Category select and active status select where behavior is supported.
  - **Active Swapping:** Targets `#product-table-container` using `outerHTML` swaps.

### 3. Product Table Columns
1. **Producto:** Name (bold) + Description (muted, optional).
2. **Categoría:** Category name or amber badge `Sin clasificar` for unclassified items.
3. **Precio actual:** Formatted numeric decimal price (e.g. `15.50` or `$15.50`), right-aligned.
4. **Estado:** Badge (`Activo` / `Inactivo`).
5. **Acciones:**
   - Active products: `Actualizar precio` | `Desactivar`
   - Inactive products: `Actualizar precio` | `Activar`

> [!CAUTION]
> **Strict Operational Boundary:**
> Do **NOT** render unapproved actions such as "Editar todo", "Eliminar", "Restaurar", "Recuperar", or "Ver historial".
> Only the approved domain operations (`Actualizar precio`, `Desactivar`, `Activar`) may appear.

---

## 12. Modal & Interaction Workflows

### 1. Category Creation Modal / Panel
- **Trigger:** Button `Nueva categoría`.
- **Fields:**
  - `Nombre de la categoría` (obligatorio, text input).
  - `Descripción` (opcional, textarea).
- **Actions:** `[ Guardar categoría ]` (Primary) | `[ Cancelar ]` (Secondary).
- **Validation:** Duplicate name rejected with inline feedback: *"Ya existe una categoría con ese nombre."*

### 2. Product Registration Modal / Panel
- **Trigger:** Button `Registrar producto`.
- **Fields:**
  - `Nombre del producto` (obligatorio, text input).
  - `Categoría` (opcional, dropdown select including option `-- Sin categoría --`).
  - `Precio actual` (obligatorio, decimal input, default `0.00`).
  - `Descripción` (opcional, textarea).
- **Invariants:**
  - Newly created products are **Active** by domain rule. **Do NOT render a status selector.**
  - **Do NOT render SKU, barcode, supplier, or warehouse inputs.**
- **Actions:** `[ Registrar producto ]` (Primary) | `[ Cancelar ]` (Secondary).

### 3. Price Update Modal / Drawer
- **Trigger:** Action link/button `Actualizar precio` in row.
- **Context:**
  - Display Product Name as **Read-Only** text.
  - Display Current Price as **Read-Only** reference.
- **Input:**
  - `Nuevo precio` (decimal input, max 2 decimal places, non-negative).
- **Actions:** `[ Guardar precio ]` (Primary) | `[ Cancelar ]` (Secondary).

### 4. Product Deactivation Confirmation Dialog
- **Trigger:** Action button `Desactivar` on an active product.
- **Dialog Context:**
  - Header: `Desactivar producto`
  - Confirmation Body:
    > *¿Estás seguro de que deseas desactivar el producto **[Nombre del Producto]**?*
    > *El producto permanecerá en el sistema con sus registros históricos e inventario, pero quedará marcado como inactivo.*
- **Actions:** `[ Confirmar desactivación ]` / `[ Desactivar ]` (Danger Red) | `[ Cancelar ]` (Secondary Light).

### 5. Product Activation Confirmation Dialog
- **Trigger:** Action button `Activar` on an inactive product.
- **Dialog Context:**
  - Header: `Activar producto`
  - Confirmation Body:
    > *¿Estás seguro de que deseas activar el producto **[Nombre del Producto]**?*
    > *Este producto volverá a estar activo en el catálogo. Se conservará su información y sus referencias existentes.*
- **Actions:** `[ Activar ]` (Primary Amber or Success) | `[ Cancelar ]` (Secondary Light).
- **Constraints:**
  - Activation is non-destructive and MUST NOT use danger/red styling.
  - Operates strictly on the existing product record (`id_producto`).
  - Canonical term is strictly `Activar` (never use "Restaurar", "Recuperar", or "Reactivar" in user copy).

---

## 13. Status Badges & Chips

Status indicators must be compact and legible:

```
[ ● Activo ]        Background: #D1FAE5    Text: #065F46    Border: #A7F3D0
[ ○ Inactivo ]      Background: #F1F5F9    Text: #475569    Border: #E2E8F0
[ ⚠ Sin clasificar ] Background: #FEF3C7    Text: #92400E    Border: #FDE68A
```

- Padding: `2px 8px;`
- Font Size: `12px; font-weight: 600;`
- Border Radius: `4px;`
- **Never render oversized rounded pills or neon glows.**

---

## 14. Notifications & Operational Feedback

1. **Success Banners:**
   - Background: `--color-success-subtle: #D1FAE5`.
   - Text: `--color-success-text: #065F46`.
   - Icon: Checkmark.
   - Message: Concise operational confirmation (e.g., *"Producto registrado correctamente."*).

2. **Error Alerts:**
   - Background: `--color-danger-subtle: #FEE2E2`.
   - Text: `--color-danger-text: #991B1B`.
   - Icon: Alert triangle.
   - Message: Descriptive human-readable Spanish explanation.

3. **HTMX Loading State:**
   - Subtle spinner or opacity transition (`opacity: 0.6`) on the target container during asynchronous swaps.
   - Never show technical debug messages (`HTMX 422`, `POST /products`, `PHP 8.5`).

---

## 15. Empty States

Empty states must guide the operator without visual clutter:

```
┌─────────────────────────────────────────────────────────────┐
│                            [ ▤ ]                            │
│                  No se encontraron productos                │
│   Ajusta los filtros de búsqueda o registra un producto.    │
│                                                             │
│                   [ + Registrar producto ]                  │
└─────────────────────────────────────────────────────────────┘
```

- Clean border, light surface (`#FFFFFF`).
- Centered icon + clear title + actionable suggestion.
- Avoid cartoon illustrations or whimsical marketing artwork.

---

## 16. Spanish Language & UI Copy Dictionary

All user-facing interface copy is strictly in **Spanish**:

| English Concept | Canonical UI Copy | Context |
|---|---|---|
| Product Catalog | **Catálogo de productos** | Main screen title |
| Products | **Productos** | Navigation link |
| Categories | **Categorías** | Navigation link |
| Locations | **Ubicaciones** | Navigation link (Phase 5) |
| Stock Overview | **Existencias por ubicación** | Navigation link (Phase 5) |
| Inventory Counts | **Conteos físicos** | Navigation link (Phase 5) |
| New Product | **Registrar producto** | Primary button & modal title |
| New Category | **Nueva categoría** | Action button & modal title |
| Search products | **Buscar por nombre de producto...** | Search input placeholder |
| Active | **Activo** | Status badge |
| Inactive | **Inactivo** | Status badge |
| Unclassified | **Sin clasificar** | Missing category indicator |
| Update Price | **Actualizar precio** | Row action |
| Activate | **Activar** | Row action & modal title |
| Deactivate | **Desactivar** | Row action & modal title |
| Cancel | **Cancelar** | Secondary button |
| Save | **Guardar cambios** | Primary modal button |
| No products found | **No se encontraron productos** | Empty state title |
| Price required | **El precio es obligatorio.** | Validation error |
| Invalid price format | **El precio debe tener hasta 2 decimales.** | Validation error |

---

## 17. Accessibility (WCAG 2.1 AA)

1. **Color Contrast:**
   - Text vs. Background: Minimum `4.5:1` contrast ratio.
   - UI Controls & Borders: Minimum `3:1` contrast ratio.
2. **Keyboard Navigation:**
   - Logical tab index across search filters, table action links, and form inputs.
   - `Escape` key closes open modals without submitting data.
3. **Form Association:**
   - Every input has an explicit `<label for="...">` matching input `id`.
   - Validation errors use `aria-describedby` linking input to error message.
4. **Screen Reader Semantics:**
   - Tables feature clean `<thead>`, `<tbody>`, `<th scope="col">`.
   - Modals use `role="dialog"`, `aria-modal="true"`, and `aria-labelledby`.
5. **No Color-Alone Status:** Badges combine distinctive text labels (`Activo`, `Inactivo`) with color, never color circles alone.

---

## 18. Responsive Adaptation Strategy

Desktop is the primary target for store administrators and inventory operators.

- **Desktop ($\ge 1024px$):** Persistent charcoal sidebar (240px width), horizontal filter bar, dense multi-column tables.
- **Tablet ($768px - 1023px$):** Collapsible sidebar toggle, filter bar wraps into two rows, data table maintains full columns with horizontal scrolling if necessary.
- **Mobile ($< 768px$):** Sidebar becomes an off-canvas drawer. Tables preserve essential columns (Name, Price, Status) with horizontal overflow. Form fields stack in a single column. Operational tables are never converted into decorative card grids.

---

## 19. Technical Implementation Constraints

The design system is architected for maximum performance and zero frontend build complexity:

- **Server-Rendered PHP:** HTML rendered via PHP templates (`templates/pages/`, `templates/fragments/`).
- **Bulma CSS Base:** Bulma provides foundational responsive columns, grid, and layout mechanics.
- **Design System CSS Layer:** Scoped CSS variables and component classes (`public/assets/app.css` or dedicated stylesheet) override Bulma defaults to achieve the Enterprise Charcoal + Restrained Amber look.
- **HTMX Interactions:** Fast partial HTML swaps (`outerHTML`, `innerHTML`) without page refreshes.
- **Zero Build Step:** No Webpack, Vite, Tailwind CLI, or npm runtime dependencies.
- **Minimal JavaScript:** Lightweight vanilla JS (`public/assets/app.js`) handles modal open/close transitions and HTMX event listeners.

---

## 20. Rules for AI Agents (Mandatory Checklist)

Before creating or modifying any template, HTML fragment, CSS rule, or user-facing script, all AI agents must follow this strict protocol:

1. **Read DESIGN.md First:** Treat this document as the visual source of truth.
2. **Respect OpenSpec Precedence:** Never add visual fields, buttons, or links for features not yet specified in OpenSpec.
3. **Preserve SSR + Bulma + HTMX Architecture:** Never propose React, Vue, Tailwind, or frontend build toolchains.
4. **Strict Spanish Copy:** All labels, placeholders, errors, headers, and tooltips must use natural professional Spanish.
5. **Enforce Color Balance:** Amber is for primary actions and active states only. Never color random cards or secondary buttons amber.
6. **No Speculative CRUD:** Do not add "Editar todo", "Eliminar", or "Reactivar" buttons to data tables.
7. **Maintain Dense Data Layouts:** Keep table row heights compact (44px–48px) and card padding restrained.
8. **Preserve Field Validation Quality:** Associate errors with inputs; never show internal SQL column names or backend English strings.
9. **Ensure Clean Accessibility:** Preserve semantic HTML (`<table>`, `<button>`, `<label>`), explicit focus rings, and WCAG AA contrast.
