<?php

use App\Foundation\Renderer;

/** @var list<array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}> $locations */
$locations = $data['locations'] ?? [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $input */
$input = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];
/** @var array<string, string> $errors */
$errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];

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

  <!-- Modal: Nueva ubicación -->
  <div class="modal <?= !empty($errors) ? 'is-active' : '' ?>" id="modal-location" role="dialog" aria-modal="true" aria-labelledby="modal-location-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-location-title">Nueva ubicación</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" action="/locations">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field mb-3">
            <label class="label is-small">Código <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['codigo']) ? 'is-danger' : '' ?>" type="text" name="codigo" value="<?= Renderer::escape($input['codigo'] ?? '') ?>" required>
            </div>
            <?php if (isset($errors['codigo'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['codigo']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label class="label is-small">Descripción</label>
            <div class="control">
              <textarea class="textarea is-small" name="descripcion" rows="2"><?= Renderer::escape($input['descripcion'] ?? '') ?></textarea>
            </div>
          </div>
        </section>
        <footer class="modal-card-foot" style="justify-content: flex-end; gap: 8px;">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Guardar ubicación</button>
        </footer>
      </form>
    </div>
  </div>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
