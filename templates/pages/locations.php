<?php

use App\Foundation\Renderer;

/** @var list<array{id_ubicacion: int, codigo: string, descripcion: ?string, estado_activo: int, created_at: string, updated_at: string}> $locations */
$locations = $data['locations'] ?? [];
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

$activeNav = 'locations';

ob_start();
?>
<section id="locations-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Ubicaciones</h1>
      <p class="page-subtitle">Gestiona los espacios físicos donde se mantiene el inventario.</p>
    </div>
    <?php if ($can('POST', '/locations')): ?>
    <div class="page-actions">
      <button class="btn-primary" type="button" data-modal-open="modal-location">
        <span aria-hidden="true">+</span> Nueva ubicación
      </button>
    </div>
    <?php endif; ?>
  </div>

  <div id="location-table-container">
    <div class="ferreto-card">
      <?php if (empty($locations)): ?>
        <div class="empty-state-box">
          <div class="empty-state-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
          </div>
          <h3 class="empty-state-title">No hay ubicaciones registradas</h3>
          <p class="empty-state-desc">Registra una ubicación para comenzar a organizar físicamente el inventario.</p>
        </div>
      <?php else: ?>
        <div class="table-container mb-0">
          <table class="ferreto-table ferreto-table-tabular">
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
                  <td class="cell-location-code"><span class="has-text-weight-semibold"><?= Renderer::escape($loc['codigo']) ?></span></td>
                  <td class="cell-location-desc">
                    <?php if (!empty($loc['descripcion'])): ?>
                      <span><?= Renderer::escape($loc['descripcion']) ?></span>
                    <?php else: ?>
                      <span class="has-text-grey">Sin descripción</span>
                    <?php endif; ?>
                  </td>
                  <td class="has-text-centered cell-location-status"><?php if ((int) ($loc['estado_activo'] ?? 1) === 1): ?><span class="badge-status badge-active">Activo</span><?php else: ?><span class="badge-status badge-inactive">Inactivo</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($can('POST', '/locations')): ?>
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
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Guardar ubicación</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
