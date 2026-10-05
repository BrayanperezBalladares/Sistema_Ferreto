<?php

use App\Foundation\Renderer;

/** @var list<array{id_sucursal: int, codigo: string, nombre: string, ciudad: string, direccion: ?string, telefono: ?string, estado_activo: int, created_at: string, updated_at: string}> $branches */
$branches = isset($data['branches']) && is_array($data['branches']) ? $data['branches'] : [];
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $input */
$input = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];
/** @var array<string, string> $errors */
$errors = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];

/** @var \App\Modules\Access\ViewPermissions|null $permissions */
$permissions = $data['permissions'] ?? null;
$can = $permissions instanceof \App\Modules\Access\ViewPermissions
    ? $permissions->can(...)
    : static fn (string $method, string $path): bool => false;

$activeNav = 'branches';

ob_start();
?>
<section id="branches-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Sucursales</h1>
      <p class="page-subtitle">Gestiona las sucursales comerciales de Ferreterías El Constructor.</p>
    </div>
    <?php if ($can('POST', '/branches')): ?>
    <div class="page-actions">
      <button class="btn-primary" type="button" data-modal-open="modal-branch">
        <span aria-hidden="true">+</span> Nueva sucursal
      </button>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors['general'] ?? $errors['error'] ?? null)): ?>
  <div class="login-error-alert mb-4" role="alert">
    <?= Renderer::escape($errors['general'] ?? $errors['error'] ?? '') ?>
  </div>
  <?php endif; ?>

  <div id="branch-table-container">
    <div class="ferreto-card">
      <?php if (empty($branches)): ?>
        <div class="empty-state-box">
          <div class="empty-state-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 21h18"></path>
              <path d="M5 21V7l8-4v18"></path>
              <path d="M19 21V11l-6-3"></path>
            </svg>
          </div>
          <h3 class="empty-state-title">No hay sucursales registradas</h3>
          <p class="empty-state-desc">Registra una sucursal para comenzar a organizar la red comercial.</p>
        </div>
      <?php else: ?>
        <div class="table-container mb-0">
          <table class="ferreto-table ferreto-table-tabular">
            <thead>
              <tr>
                <th scope="col" style="width: 15%;">Código</th>
                <th scope="col" style="width: 25%;">Nombre</th>
                <th scope="col" style="width: 18%;">Ciudad</th>
                <th scope="col" style="width: 20%;">Dirección</th>
                <th scope="col" style="width: 12%;">Teléfono</th>
                <th scope="col" style="width: 5%;" class="has-text-centered">Estado</th>
                <?php if ($can('POST', '/branches/toggle-active')): ?>
                <th scope="col" style="width: 5%;" class="has-text-centered">Acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($branches as $branch): ?>
                <tr>
                  <td><span class="has-text-weight-semibold"><?= Renderer::escape($branch['codigo']) ?></span></td>
                  <td><?= Renderer::escape($branch['nombre']) ?></td>
                  <td><?= Renderer::escape($branch['ciudad']) ?></td>
                  <td>
                    <?php if (!empty($branch['direccion'])): ?>
                      <?= Renderer::escape($branch['direccion']) ?>
                    <?php else: ?>
                      <span class="has-text-grey">Sin dirección</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($branch['telefono'])): ?>
                      <?= Renderer::escape($branch['telefono']) ?>
                    <?php else: ?>
                      <span class="has-text-grey">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="has-text-centered">
                    <?php if ((int) ($branch['estado_activo'] ?? 1) === 1): ?>
                      <span class="badge-status badge-active">Activo</span>
                    <?php else: ?>
                      <span class="badge-status badge-inactive">Inactivo</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($can('POST', '/branches/toggle-active')): ?>
                  <td class="has-text-centered">
                    <form method="post" action="/branches/toggle-active" style="display:inline;">
                      <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
                      <input type="hidden" name="id_sucursal" value="<?= (int) $branch['id_sucursal'] ?>">
                      <button class="btn-sm <?= (int) ($branch['estado_activo'] ?? 1) === 1 ? 'btn-sm-danger' : 'btn-sm-activate' ?>" type="submit">
                        <?= (int) ($branch['estado_activo'] ?? 1) === 1 ? 'Desactivar' : 'Activar' ?>
                      </button>
                    </form>
                  </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($can('POST', '/branches')): ?>
  <!-- Modal: Nueva sucursal -->
  <div class="modal <?= !empty($errors) ? 'is-active' : '' ?>" id="modal-branch" role="dialog" aria-modal="true" aria-labelledby="modal-branch-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-branch-title">Nueva sucursal</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" action="/branches">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field mb-3">
            <label class="label is-small">Código <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['codigo']) ? 'is-danger' : '' ?>" type="text" name="codigo" value="<?= Renderer::escape($input['codigo'] ?? '') ?>" placeholder="Ej: SUC-01" required>
            </div>
            <?php if (isset($errors['codigo'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['codigo']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field mb-3">
            <label class="label is-small">Nombre de la sucursal <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['nombre']) ? 'is-danger' : '' ?>" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" placeholder="Ej: Sucursal Central" required>
            </div>
            <?php if (isset($errors['nombre'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['nombre']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field mb-3">
            <label class="label is-small">Ciudad <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['ciudad']) ? 'is-danger' : '' ?>" type="text" name="ciudad" value="<?= Renderer::escape($input['ciudad'] ?? '') ?>" placeholder="Ej: Managua" required>
            </div>
            <?php if (isset($errors['ciudad'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['ciudad']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field mb-3">
            <label class="label is-small">Dirección</label>
            <div class="control">
              <input class="input is-small <?= isset($errors['direccion']) ? 'is-danger' : '' ?>" type="text" name="direccion" value="<?= Renderer::escape($input['direccion'] ?? '') ?>" placeholder="Ej: Km 5 Carretera Norte">
            </div>
            <?php if (isset($errors['direccion'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['direccion']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label class="label is-small">Teléfono</label>
            <div class="control">
              <input class="input is-small <?= isset($errors['telefono']) ? 'is-danger' : '' ?>" type="tel" name="telefono" value="<?= Renderer::escape($input['telefono'] ?? '') ?>" placeholder="Ej: +505 2222-3333">
            </div>
            <?php if (isset($errors['telefono'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['telefono']) ?></p>
            <?php endif; ?>
          </div>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Guardar sucursal</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
