<?php

use App\Foundation\Renderer;

/** @var list<array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}> $locations */
$locations = $data['locations'] ?? [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';

$activeNav = 'locations';

ob_start();
?>
<section id="locations-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Ubicaciones</h1>
      <p class="page-subtitle">Gestiona los espacios físicos donde se mantiene el inventario.</p>
    </div>
    <div class="page-actions">
      <button class="btn-primary" type="button" data-modal-open="modal-location">
        <span aria-hidden="true">+</span> Nueva ubicación
      </button>
    </div>
  </div>

  <div class="card-surface p-0" id="location-table-container">
    <?php if (empty($locations)): ?>
      <div class="has-text-centered py-6 px-4">
        <p class="is-size-2 mb-2" aria-hidden="true">📍</p>
        <p class="has-text-weight-bold is-size-5 mb-1">No hay ubicaciones registradas</p>
        <p class="has-text-grey is-size-6 mb-4">Registra una ubicación para comenzar a organizar físicamente el inventario.</p>
        <button class="btn-primary" type="button" data-modal-open="modal-location">
          <span aria-hidden="true">+</span> Nueva ubicación
        </button>
      </div>
    <?php else: ?>
      <div class="table-container mb-0">
        <table class="table is-fullwidth is-hoverable is-narrow operational-table mb-0">
          <thead>
            <tr>
              <th scope="col" style="width: 30%;">Código</th>
              <th scope="col" style="width: 50%;">Descripción</th>
              <th scope="col" style="width: 20%;" class="has-text-centered">Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($locations as $loc): ?>
              <tr>
                <td>
                  <span class="has-text-weight-semibold"><?= Renderer::escape($loc['codigo']) ?></span>
                </td>
                <td>
                  <?php if (!empty($loc['descripcion'])): ?>
                    <span><?= Renderer::escape($loc['descripcion']) ?></span>
                  <?php else: ?>
                    <span class="has-text-grey">Sin descripción</span>
                  <?php endif; ?>
                </td>
                <td class="has-text-centered">
                  <span class="badge-status badge-active">Activo</span>
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
