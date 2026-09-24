<?php

use App\Foundation\Renderer;

/** @var list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}> $products */
$products = $data['products'] ?? [];
/** @var list<array{id_categoria: int, nombre: string, descripcion: ?string, created_at: string, updated_at: string}> $categories */
$categories = $data['categories'] ?? [];
$query = isset($data['query']) && is_string($data['query']) ? $data['query'] : '';
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $input */
$input = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];

/** @var \App\Modules\Access\ViewPermissions|null $permissions */
$permissions = $data['permissions'] ?? null;
$can = $permissions instanceof \App\Modules\Access\ViewPermissions
    ? $permissions->can(...)
    : static fn (string $method, string $path): bool => false;

ob_start();
?>
<section id="catalog-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Productos</h1>
      <p class="page-subtitle">Gestión de artículos, precios de venta y disponibilidad.</p>
    </div>
    <?php if ($can('POST', '/categories') || $can('POST', '/products')): ?>
    <div class="page-actions">
      <?php if ($can('POST', '/categories')): ?>
      <button class="btn-secondary" type="button" data-modal-open="modal-category">Nueva categoría</button>
      <?php endif; ?>
      <?php if ($can('POST', '/products')): ?>
      <button class="btn-primary" type="button" data-modal-open="modal-product">
        <span aria-hidden="true">+</span> Registrar producto
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="search-toolbar">
    <input
      class="search-input"
      type="search"
      name="q"
      placeholder="Buscar por nombre del producto…"
      value="<?= Renderer::escape($query) ?>"
      hx-get="/products"
      hx-trigger="keyup changed delay:300ms, search"
      hx-target="#product-table-container"
      hx-swap="outerHTML"
      aria-label="Buscar por nombre del producto"
    >
  </div>

  <?php require dirname(__DIR__) . '/fragments/product_table.php'; ?>

  <?php if ($can('POST', '/categories')): ?>
  <!-- Modal: Nueva categoría -->
  <div class="modal" id="modal-category" role="dialog" aria-modal="true" aria-labelledby="modal-category-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-category-title">Nueva categoría</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" action="/categories">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field mb-3">
            <label class="label is-small">Nombre <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" required>
            </div>
          </div>
          <div class="field">
            <label class="label is-small">Descripción</label>
            <div class="control">
              <textarea class="textarea is-small" name="descripcion" rows="2"><?= Renderer::escape($input['descripcion'] ?? '') ?></textarea>
            </div>
          </div>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Guardar categoría</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($can('POST', '/products')): ?>
  <!-- Modal: Registrar producto -->
  <div class="modal" id="modal-product" role="dialog" aria-modal="true" aria-labelledby="modal-product-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-product-title">Registrar producto</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" action="/products">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field mb-3">
            <label class="label is-small">Nombre del producto <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" required>
            </div>
          </div>
          <div class="columns is-multiline mb-0">
            <div class="column is-12-mobile is-half-tablet py-1">
              <div class="field">
                <label class="label is-small">Precio actual <span class="has-text-danger">*</span></label>
                <div class="control">
                  <input class="input is-small" type="text" inputmode="decimal" name="precio_actual" placeholder="0.00" value="<?= Renderer::escape($input['precio_actual'] ?? '') ?>" required>
                </div>
              </div>
            </div>
            <div class="column is-12-mobile is-half-tablet py-1">
              <div class="field">
                <label class="label is-small">Categoría</label>
                <div class="control">
                  <div class="select is-small is-fullwidth">
                    <select name="id_categoria">
                      <option value="">-- Sin categoría --</option>
                      <?php foreach ($categories as $cat): ?>
                        <option value="<?= Renderer::escape((string) $cat['id_categoria']) ?>">
                          <?= Renderer::escape($cat['nombre']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="field">
            <label class="label is-small">Descripción</label>
            <div class="control">
              <textarea class="textarea is-small" name="descripcion" rows="2"><?= Renderer::escape($input['descripcion'] ?? '') ?></textarea>
            </div>
          </div>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Registrar producto</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($can('POST', '/products/1/price')): ?>
  <!-- Modal: Actualizar precio -->
  <div class="modal" id="modal-price" role="dialog" aria-modal="true" aria-labelledby="modal-price-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-price-title">Actualizar precio</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" id="form-update-price" action="">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="mb-3">
            <span class="is-size-7 has-text-grey">Producto:</span>
            <div class="has-text-weight-semibold" id="modal-price-product-name">—</div>
          </div>
          <div class="mb-3">
            <span class="is-size-7 has-text-grey">Precio actual:</span>
            <div class="is-size-6" id="modal-price-current-value">—</div>
          </div>
          <div class="field">
            <label class="label is-small">Nuevo precio <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small" type="text" inputmode="decimal" name="precio_actual" id="modal-price-input" placeholder="0.00" required>
            </div>
          </div>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Actualizar precio</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($can('POST', '/products/1/deactivate')): ?>
  <!-- Modal: Desactivar producto -->
  <div class="modal" id="modal-deactivate" role="dialog" aria-modal="true" aria-labelledby="modal-deactivate-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-deactivate-title">Desactivar producto</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" id="form-deactivate-product" action="">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <p class="mb-2">¿Estás seguro de que deseas desactivar el producto <strong id="modal-deactivate-product-name"></strong>?</p>
          <p class="is-size-7 has-text-grey">El producto permanecerá en el sistema con sus registros históricos e inventario, pero quedará marcado como inactivo.</p>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-danger" type="submit">Desactivar</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($can('POST', '/products/1/activate')): ?>
  <!-- Modal: Activar producto -->
  <div class="modal" id="modal-activate" role="dialog" aria-modal="true" aria-labelledby="modal-activate-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-activate-title">Activar producto</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" id="form-activate-product" action="">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <p class="mb-2">¿Estás seguro de que deseas activar el producto <strong id="modal-activate-product-name"></strong>?</p>
          <p class="is-size-7 has-text-grey">Este producto volverá a estar activo en el catálogo. Se conservará su información y sus referencias existentes.</p>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Activar</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
