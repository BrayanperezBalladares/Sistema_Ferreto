<?php

use App\Foundation\Renderer;

/** @var list<array{id_stock: int, id_producto: int, producto_nombre: string, id_ubicacion: int, ubicacion_codigo: string, cantidad: string, created_at: string, updated_at: string}> $positions */
$positions = $data['positions'] ?? [];
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

  <div class="card-surface p-0" id="stock-table-container">
    <?php if (empty($positions)): ?>
      <div class="has-text-centered py-6 px-4">
        <p class="is-size-2 mb-2" aria-hidden="true">📊</p>
        <p class="has-text-weight-bold is-size-5 mb-1">No hay existencias registradas</p>
        <p class="has-text-grey is-size-6 mb-4">Registra una existencia para relacionar un producto con una ubicación.</p>
        <button class="btn-primary" type="button" data-modal-open="modal-stock">
          <span aria-hidden="true">+</span> Registrar existencia
        </button>
      </div>
    <?php else: ?>
      <div class="table-container mb-0">
        <table class="table is-fullwidth is-hoverable is-narrow operational-table mb-0">
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
                <td>
                  <span class="has-text-weight-semibold"><?= Renderer::escape($pos['producto_nombre']) ?></span>
                </td>
                <td>
                  <span class="has-text-weight-medium"><?= Renderer::escape($pos['ubicacion_codigo']) ?></span>
                </td>
                <td class="has-text-right">
                  <span class="is-family-monospace"><?= Renderer::escape($pos['cantidad']) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
