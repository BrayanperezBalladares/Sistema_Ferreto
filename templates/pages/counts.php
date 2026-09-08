<?php

use App\Foundation\Renderer;

/** @var list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}> $positions */
$positions = isset($data['positions']) && is_array($data['positions']) ? $data['positions'] : [];
/** @var array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}|null $selectedStock */
$selectedStock = isset($data['selectedStock']) && is_array($data['selectedStock']) ? $data['selectedStock'] : null;
/** @var list<array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}> $counts */
$counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
$activeNav = 'counts';

ob_start();
?>
<section id="counts-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Conteos físicos</h1>
      <p class="page-subtitle">Registra observaciones físicas del inventario sin modificar las existencias registradas en el sistema.</p>
    </div>
    <?php if ($selectedStock !== null): ?>
      <div class="page-actions">
        <button class="btn-primary" type="button" data-modal-open="modal-count"><span aria-hidden="true">+</span> Registrar conteo</button>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-surface p-4 mb-4" id="stock-selector-container">
    <form method="get" action="/inventory/counts" id="stock-selector-form">
      <div class="field">
        <label class="label is-small" for="stock-select">Seleccionar existencia para conteo</label>
        <div class="control">
          <div class="select is-small is-fullwidth">
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
    <div class="card-surface p-0">
      <div class="has-text-centered py-6 px-4">
        <p class="is-size-2 mb-2" aria-hidden="true">📋</p>
        <p class="has-text-weight-bold is-size-5 mb-1">Selecciona una existencia</p>
        <p class="has-text-grey is-size-6 mb-0">Selecciona una existencia para consultar su historial de conteos físicos y registrar nuevas observaciones.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="card-surface p-4 mb-4" id="selected-stock-summary">
      <div class="columns is-mobile is-multiline mb-0">
        <div class="column is-4"><p class="has-text-grey is-size-7 mb-1">Producto</p><p class="has-text-weight-bold is-size-6"><?= Renderer::escape($selectedStock['producto_nombre']) ?></p></div>
        <div class="column is-4"><p class="has-text-grey is-size-7 mb-1">Ubicación</p><p class="has-text-weight-semibold is-size-6"><?= Renderer::escape($selectedStock['ubicacion_codigo']) ?></p></div>
        <div class="column is-4 has-text-right-tablet"><p class="has-text-grey is-size-7 mb-1">Cantidad del sistema</p><p class="is-family-monospace has-text-weight-bold is-size-5"><?= Renderer::escape($selectedStock['cantidad']) ?></p></div>
      </div>
    </div>
    <div class="mb-4">
      <h2 class="is-size-6 has-text-weight-bold mb-2">Historial de observaciones</h2>
      <?php require dirname(__DIR__) . '/fragments/count_history.php'; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';