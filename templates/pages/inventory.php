<?php

use App\Foundation\Renderer;

/** @var list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}> $positions */
$positions = $data['positions'] ?? [];
/** @var list<array{id_producto: int, nombre: string, estado_activo: int}> $products */
$products = isset($data['products']) && is_array($data['products']) ? $data['products'] : [];
/** @var list<array{id_ubicacion: int, codigo: string, estado_activo: int}> $locations */
$locations = isset($data['locations']) && is_array($data['locations']) ? $data['locations'] : [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $input */
$input = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];
/** @var array<string, string> $errors */
$errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];

$activeNav = 'inventory';

ob_start();
?>
<section id="inventory-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Existencias por ubicación</h1>
      <p class="page-subtitle">Consulta las existencias registradas para cada producto y ubicación.</p>
    </div>
    <div class="page-actions">
      <button class="btn-primary" type="button" data-modal-open="modal-stock">
        <span aria-hidden="true">+</span> Registrar existencia
      </button>
    </div>
  </div>

  <div id="stock-table-container">
    <div class="ferreto-card">
      <?php if (empty($positions)): ?>
        <div class="empty-state-box">
          <div class="empty-state-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
              <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
          </div>
          <h3 class="empty-state-title">No hay existencias registradas</h3>
          <p class="empty-state-desc">Registra una existencia para relacionar un producto con una ubicación.</p>
        </div>
      <?php else: ?>
        <div class="table-container mb-0">
          <table class="ferreto-table ferreto-table-tabular">
            <thead>
              <tr>
                <th scope="col" style="width: 40%;">Producto</th>
                <th scope="col" style="width: 35%;">Ubicación</th>
                <th scope="col" style="width: 25%;" class="has-text-right">Cantidad</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($positions as $pos): ?>
                <tr>
                  <td class="cell-product"><span class="has-text-weight-semibold"><?= Renderer::escape($pos['producto_nombre']) ?></span></td>
                  <td class="cell-location"><span class="has-text-weight-medium"><?= Renderer::escape($pos['ubicacion_codigo']) ?></span></td>
                  <td class="has-text-right cell-quantity"><span><?= Renderer::escape($pos['cantidad']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal: Registrar existencia -->
  <div class="modal <?= !empty($errors) ? 'is-active' : '' ?>" id="modal-stock" role="dialog" aria-modal="true" aria-labelledby="modal-stock-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-stock-title">Registrar existencia</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" action="/inventory/stock">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <?php if (isset($errors['general'])): ?>
            <div class="notification is-danger is-light py-2 px-3 mb-3 is-size-7">
              <?= Renderer::escape($errors['general']) ?>
            </div>
          <?php endif; ?>
          <div class="field mb-3">
            <label class="label is-small">Producto <span class="has-text-danger">*</span></label>
            <div class="control">
              <div class="select is-small is-fullwidth <?= isset($errors['id_producto']) ? 'is-danger' : '' ?>">
                <select name="id_producto" required>
                  <option value="">Selecciona un producto...</option>
                  <?php foreach ($products as $prod): ?>
                    <option value="<?= (int) $prod['id_producto'] ?>" <?= ((string) ($input['id_producto'] ?? '')) === ((string) $prod['id_producto']) ? 'selected' : '' ?>>
                      <?= Renderer::escape($prod['nombre']) ?><?= $prod['estado_activo'] === 0 ? ' [Inactivo]' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php if (isset($errors['id_producto'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['id_producto']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field mb-3">
            <label class="label is-small">Ubicación <span class="has-text-danger">*</span></label>
            <div class="control">
              <div class="select is-small is-fullwidth <?= isset($errors['id_ubicacion']) ? 'is-danger' : '' ?>">
                <select name="id_ubicacion" required>
                  <option value="">Selecciona una ubicación...</option>
                  <?php foreach ($locations as $loc): ?>
                    <option value="<?= (int) $loc['id_ubicacion'] ?>" <?= ((string) ($input['id_ubicacion'] ?? '')) === ((string) $loc['id_ubicacion']) ? 'selected' : '' ?>>
                      <?= Renderer::escape($loc['codigo']) ?><?= $loc['estado_activo'] === 0 ? ' [Inactiva]' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php if (isset($errors['id_ubicacion'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['id_ubicacion']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label class="label is-small">Cantidad inicial <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['cantidad']) ? 'is-danger' : '' ?>" type="number" step="0.001" min="0" inputmode="decimal" name="cantidad" value="<?= Renderer::escape($input['cantidad'] ?? '') ?>" required>
            </div>
            <?php if (isset($errors['cantidad'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['cantidad']) ?></p>
            <?php endif; ?>
          </div>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Guardar existencia</button>
        </footer>
      </form>
    </div>
  </div>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
