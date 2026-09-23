<?php

use App\Foundation\Renderer;

/** @var list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}> $positions */
$positions = isset($data['positions']) && is_array($data['positions']) ? $data['positions'] : [];
/** @var array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}|null $selectedStock */
$selectedStock = isset($data['selectedStock']) && is_array($data['selectedStock']) ? $data['selectedStock'] : null;
/** @var list<array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}> $counts */
$counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $input */
$input = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];
/** @var array<string, string> $errors */
$errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];

/** @var \App\Modules\Access\ViewPermissions|null $permissions */
$permissions = $data['permissions'] ?? null;
$can = $permissions instanceof \App\Modules\Access\ViewPermissions
    ? $permissions->can(...)
    : static fn (string $method, string $path): bool => true;

$activeNav = 'counts';

ob_start();
?>
<section id="counts-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Conteos físicos</h1>
      <p class="page-subtitle">Registra observaciones físicas del inventario sin modificar las existencias registradas en el sistema.</p>
    </div>
    <?php if ($selectedStock !== null && $can('POST', '/inventory/counts')): ?>
      <div class="page-actions">
        <button class="btn-primary" type="button" data-modal-open="modal-count"><span aria-hidden="true">+</span> Registrar conteo</button>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors['id_stock']) || !empty($errors['general'])): ?>
    <div class="notification is-danger is-light mb-4" role="alert">
      <?= Renderer::escape($errors['id_stock'] ?? $errors['general'] ?? '') ?>
    </div>
  <?php endif; ?>

  <div class="counts-toolbar mb-4" id="stock-selector-container">
    <form method="get" action="/inventory/counts" id="stock-selector-form">
      <div class="field stock-selector-field">
        <label class="label is-small" for="stock-select">Seleccionar existencia para conteo</label>
        <div class="control">
          <div class="select is-small">
            <select name="stock" id="stock-select" onchange="this.form.submit()">
              <option value="">Selecciona una existencia...</option>
              <?php foreach ($positions as $pos): ?>
                <option value="<?= (int) $pos['id_stock'] ?>" <?= $selectedStock !== null && $selectedStock['id_stock'] === $pos['id_stock'] ? 'selected' : '' ?>>
                  <?= Renderer::escape($pos['producto_nombre']) ?> — <?= Renderer::escape($pos['ubicacion_codigo']) ?> (Actual: <?= Renderer::escape($pos['cantidad']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </form>
  </div>

  <?php if ($selectedStock === null): ?>
    <div class="ferreto-card">
      <div class="empty-state-box">
        <div class="empty-state-icon" aria-hidden="true">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
        </div>
        <h3 class="empty-state-title">Selecciona una existencia</h3>
        <p class="empty-state-desc">Selecciona una existencia para consultar su historial de conteos físicos y registrar nuevas observaciones.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="ferreto-card stock-summary mb-4" id="selected-stock-summary">
      <div class="stock-summary-header">
        <span class="stock-summary-badge">Resumen de existencia</span>
      </div>
      <div class="columns is-multiline mb-0">
        <div class="column is-12-mobile is-4-tablet">
          <div class="stock-summary-item">
            <span class="stock-summary-label">Producto</span>
            <span class="stock-summary-value product-title"><?= Renderer::escape($selectedStock['producto_nombre']) ?></span>
          </div>
        </div>
        <div class="column is-12-mobile is-4-tablet">
          <div class="stock-summary-item">
            <span class="stock-summary-label">Ubicación</span>
            <span class="stock-summary-value location-code"><?= Renderer::escape($selectedStock['ubicacion_codigo']) ?></span>
          </div>
        </div>
        <div class="column is-12-mobile is-4-tablet stock-summary-quantity">
          <div class="stock-summary-item">
            <span class="stock-summary-label">Cantidad del sistema</span>
            <span class="stock-summary-value quantity-value"><?= Renderer::escape($selectedStock['cantidad']) ?></span>
          </div>
        </div>
      </div>
    </div>
    <div class="count-history-section mb-4">
      <div class="section-header mb-3">
        <h2 class="section-title">Historial de observaciones</h2>
      </div>
      <?php require dirname(__DIR__) . '/fragments/count_history.php'; ?>
    </div>

    <?php if ($selectedStock !== null && $can('POST', '/inventory/counts')): ?>
    <div class="modal <?= !empty($errors) ? 'is-active' : '' ?>" id="modal-count" role="dialog" aria-modal="true" aria-labelledby="modal-count-title">
      <div class="modal-background" data-modal-close></div>
      <div class="modal-card">
        <header class="modal-card-head">
          <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-count-title">Registrar conteo</p>
          <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
        </header>
        <form method="post" action="/inventory/counts">
          <section class="modal-card-body">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
            <input type="hidden" name="id_stock" value="<?= (int) $selectedStock['id_stock'] ?>">

            <div class="notification is-info is-light py-2 px-3 mb-3 is-size-7">
              Registrar un conteo no modifica la cantidad registrada en existencias.
            </div>

            <?php if (isset($errors['general'])): ?>
              <div class="notification is-danger is-light py-2 px-3 mb-3 is-size-7"><?= Renderer::escape($errors['general']) ?></div>
            <?php endif; ?>

            <div class="field mb-3">
              <label class="label is-small">Producto</label>
              <div class="control"><input class="input is-small is-static has-text-weight-bold" type="text" value="<?= Renderer::escape($selectedStock['producto_nombre']) ?>" readonly></div>
            </div>

            <div class="field mb-3">
              <label class="label is-small">Ubicación</label>
              <div class="control"><input class="input is-small is-static has-text-weight-semibold" type="text" value="<?= Renderer::escape($selectedStock['ubicacion_codigo']) ?>" readonly></div>
            </div>

            <div class="field mb-3">
              <label class="label is-small">Cantidad del sistema</label>
              <div class="control"><input class="input is-small is-static is-family-monospace" type="text" value="<?= Renderer::escape($selectedStock['cantidad']) ?>" readonly></div>
            </div>

            <div class="field mb-3">
              <label class="label is-small" for="cantidad_contada">Cantidad contada <span class="has-text-danger">*</span></label>
              <div class="control">
                <input class="input is-small <?= isset($errors['cantidad_contada']) ? 'is-danger' : '' ?>" type="number" step="0.001" min="0" inputmode="decimal" id="cantidad_contada" name="cantidad_contada" value="<?= Renderer::escape($input['cantidad_contada'] ?? '') ?>" required autofocus>
              </div>
              <?php if (isset($errors['cantidad_contada'])): ?>
                <p class="help is-danger"><?= Renderer::escape($errors['cantidad_contada']) ?></p>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label is-small" for="notas">Notas</label>
              <div class="control">
                <textarea class="textarea is-small" id="notas" name="notas" rows="2" placeholder="Observaciones opcionales sobre el conteo"><?= Renderer::escape($input['notas'] ?? '') ?></textarea>
              </div>
            </div>
          </section>
          <footer class="modal-card-foot modal-actions">
            <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
            <button class="btn-primary" type="submit">Registrar conteo</button>
          </footer>
        </form>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';