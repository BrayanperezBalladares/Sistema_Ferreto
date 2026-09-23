<?php

use App\Foundation\Renderer;

/** @var list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}> $products */
$products = $data['products'] ?? [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $errors */
$errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];

/** @var \App\Modules\Access\ViewPermissions|null $permissions */
$permissions = $data['permissions'] ?? null;
$can = $permissions instanceof \App\Modules\Access\ViewPermissions
    ? $permissions->can(...)
    : static fn (string $method, string $path): bool => true;

$canUpdatePrice = $can('POST', '/products/1/price');
$canDeactivate = $can('POST', '/products/1/deactivate');
$canActivate = $can('POST', '/products/1/activate');
$hasRowActions = $canUpdatePrice || $canDeactivate || $canActivate;
?>
<div id="product-table-container">
<?php if ($errors !== []): ?>
  <div class="notification is-danger is-light mb-4" data-test="validation-errors">
    <?php foreach ($errors as $msg): ?>
      <p><?= Renderer::escape($msg) ?></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($products === []): ?>
  <div class="ferreto-card">
    <div class="empty-state-box" data-test="empty-catalog">
      <div class="empty-state-icon" aria-hidden="true">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
          <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
          <line x1="12" y1="22.08" x2="12" y2="12"></line>
        </svg>
      </div>
      <h3 class="empty-state-title">No se encontraron productos</h3>
      <p class="empty-state-desc">Ajusta la búsqueda o registra un nuevo producto.</p>
    </div>
  </div>
<?php else: ?>
  <div class="ferreto-card">
    <div class="table-container mb-0">
      <table class="ferreto-table">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Categoría</th>
          <th class="col-price">Precio actual</th>
          <th>Estado</th>
          <?php if ($hasRowActions): ?>
          <th>Acciones</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>">
            <td class="cell-product">
              <div class="product-title"><?= Renderer::escape($p['nombre']) ?></div>
              <?php if ($p['descripcion'] !== null && $p['descripcion'] !== ''): ?>
                <div class="product-desc"><?= Renderer::escape($p['descripcion']) ?></div>
              <?php endif; ?>
            </td>
            <td class="cell-category">
              <?php if ($p['categoria_nombre'] !== null && $p['categoria_nombre'] !== ''): ?>
                <span class="badge-category"><?= Renderer::escape($p['categoria_nombre']) ?></span>
              <?php else: ?>
                <span class="badge-unclassified">Sin clasificar</span>
              <?php endif; ?>
            </td>
            <td class="col-price cell-price"><?= Renderer::escape($p['precio_actual']) ?></td>
            <td class="cell-status">
              <?php if ($p['estado_activo'] === 1): ?>
                <span class="badge-active">Activo</span>
              <?php else: ?>
                <span class="badge-inactive">Inactivo</span>
              <?php endif; ?>
            </td>
            <?php if ($hasRowActions): ?>
            <td class="cell-actions">
              <div class="table-actions">
                <?php if ($canUpdatePrice): ?>
                <button
                  type="button"
                  class="btn-secondary btn-sm"
                  data-modal-open="modal-price"
                  data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>"
                  data-product-name="<?= Renderer::escape($p['nombre']) ?>"
                  data-product-price="<?= Renderer::escape($p['precio_actual']) ?>"
                >
                  Actualizar precio
                </button>
                <?php endif; ?>
                <?php if ($p['estado_activo'] === 1): ?>
                  <?php if ($canDeactivate): ?>
                  <button
                    type="button"
                    class="btn-secondary btn-sm btn-sm-danger"
                    data-modal-open="modal-deactivate"
                    data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>"
                    data-product-name="<?= Renderer::escape($p['nombre']) ?>"
                  >
                    Desactivar
                  </button>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if ($canActivate): ?>
                  <button
                    type="button"
                    class="btn-secondary btn-sm btn-sm-activate"
                    data-modal-open="modal-activate"
                    data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>"
                    data-product-name="<?= Renderer::escape($p['nombre']) ?>"
                  >
                    Activar
                  </button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
</div>
