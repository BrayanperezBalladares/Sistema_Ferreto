<?php

use App\Foundation\Renderer;

/** @var list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}> $products */
$products = $data['products'] ?? [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $errors */
$errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];
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
      <div class="empty-state-icon" aria-hidden="true">📦</div>
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
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>">
            <td>
              <div class="product-title"><?= Renderer::escape($p['nombre']) ?></div>
              <?php if ($p['descripcion'] !== null && $p['descripcion'] !== ''): ?>
                <div class="product-desc"><?= Renderer::escape($p['descripcion']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($p['categoria_nombre'] !== null && $p['categoria_nombre'] !== ''): ?>
                <span class="badge-category"><?= Renderer::escape($p['categoria_nombre']) ?></span>
              <?php else: ?>
                <span class="badge-unclassified">Sin clasificar</span>
              <?php endif; ?>
            </td>
            <td class="col-price"><?= Renderer::escape($p['precio_actual']) ?></td>
            <td>
              <?php if ($p['estado_activo'] === 1): ?>
                <span class="badge-active">Activo</span>
              <?php else: ?>
                <span class="badge-inactive">Inactivo</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display: inline-flex; gap: 8px; align-items: center;">
                <button
                  type="button"
                  class="btn-secondary"
                  style="padding: 4px 10px; font-size: 13px;"
                  data-modal-open="modal-price"
                  data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>"
                  data-product-name="<?= Renderer::escape($p['nombre']) ?>"
                  data-product-price="<?= Renderer::escape($p['precio_actual']) ?>"
                >
                  Actualizar precio
                </button>
                <?php if ($p['estado_activo'] === 1): ?>
                  <button
                    type="button"
                    class="btn-secondary"
                    style="padding: 4px 10px; font-size: 13px; color: var(--color-danger);"
                    data-modal-open="modal-deactivate"
                    data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>"
                    data-product-name="<?= Renderer::escape($p['nombre']) ?>"
                  >
                    Desactivar
                  </button>
                <?php else: ?>
                  <button
                    type="button"
                    class="btn-secondary"
                    style="padding: 4px 10px; font-size: 13px; color: var(--color-amber-dark);"
                    data-modal-open="modal-activate"
                    data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>"
                    data-product-name="<?= Renderer::escape($p['nombre']) ?>"
                  >
                    Activar
                  </button>
                <?php endif; ?>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
</div>
