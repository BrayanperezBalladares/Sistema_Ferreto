<?php

use App\Foundation\Renderer;

/** @var list<array{id_almacen: int, id_sucursal: int, codigo: string, nombre: string, tipo: string, estado_activo: int, created_at: string, updated_at: string, sucursal_codigo: string, sucursal_nombre: string}> $warehouses */
$warehouses = isset($data['warehouses']) && is_array($data['warehouses']) ? $data['warehouses'] : [];
/** @var list<array{id_sucursal: int, codigo: string, nombre: string, ciudad: string, direccion: ?string, telefono: ?string, estado_activo: int, created_at: string, updated_at: string}> $branches */
$branches = isset($data['branches']) && is_array($data['branches']) ? $data['branches'] : [];
/** @var list<array{id_sucursal: int, codigo: string, nombre: string, ciudad: string, direccion: ?string, telefono: ?string, estado_activo: int, created_at: string, updated_at: string}> $activeBranches */
$activeBranches = isset($data['activeBranches']) && is_array($data['activeBranches']) ? $data['activeBranches'] : [];
$selectedSucursal = isset($data['selectedSucursal']) && is_numeric($data['selectedSucursal']) ? (int) $data['selectedSucursal'] : null;
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

$activeNav = 'warehouses';

ob_start();
?>
<section id="warehouses-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Almacenes</h1>
      <p class="page-subtitle">Gestiona los almacenes y áreas de almacenamiento por sucursal.</p>
    </div>
    <?php if ($can('POST', '/warehouses')): ?>
    <div class="page-actions">
      <button class="btn-primary" type="button" data-modal-open="modal-warehouse">
        <span aria-hidden="true">+</span> Nuevo almacén
      </button>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors['general'] ?? $errors['almacen'] ?? $errors['error'] ?? null)): ?>
  <div class="login-error-alert mb-4" role="alert">
    <?= Renderer::escape($errors['general'] ?? $errors['almacen'] ?? $errors['error'] ?? '') ?>
  </div>
  <?php endif; ?>

  <div class="search-toolbar mb-4">
    <form method="get" action="/warehouses" class="is-flex is-align-items-center gap-2 is-flex-wrap-wrap">
      <div class="select is-small">
        <select name="sucursal" onchange="this.form.submit()" aria-label="Filtrar por sucursal">
          <option value="">-- Todas las sucursales --</option>
          <?php foreach ($branches as $br): ?>
            <option value="<?= (int) $br['id_sucursal'] ?>" <?= $selectedSucursal === (int) $br['id_sucursal'] ? 'selected' : '' ?>>
              <?= Renderer::escape($br['nombre']) ?> (<?= Renderer::escape($br['codigo']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <noscript><button class="btn-secondary btn-sm" type="submit">Filtrar</button></noscript>
      <?php if ($selectedSucursal !== null): ?>
        <a href="/warehouses" class="btn-secondary btn-sm">Limpiar filtro</a>
      <?php endif; ?>
    </form>
  </div>

  <div id="warehouse-table-container">
    <div class="ferreto-card">
      <?php if (empty($warehouses)): ?>
        <div class="empty-state-box">
          <div class="empty-state-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 8v13H3V8"></path>
              <path d="M1 3h22v5H1z"></path>
              <path d="M10 12h4"></path>
            </svg>
          </div>
          <h3 class="empty-state-title">No hay almacenes registrados</h3>
          <p class="empty-state-desc">Registra un almacén para organizar los espacios de almacenamiento.</p>
        </div>
      <?php else: ?>
        <div class="table-container mb-0">
          <table class="ferreto-table ferreto-table-tabular">
            <thead>
              <tr>
                <th scope="col" style="width: 22%;">Sucursal</th>
                <th scope="col" style="width: 14%;">Código</th>
                <th scope="col" style="width: 22%;">Nombre</th>
                <th scope="col" style="width: 12%;">Tipo</th>
                <th scope="col" style="width: 10%;" class="has-text-centered">Ubicaciones</th>
                <th scope="col" style="width: 10%;" class="has-text-centered">Estado</th>
                <?php if ($can('POST', '/warehouses/toggle-active')): ?>
                <th scope="col" style="width: 10%;" class="has-text-centered">Acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($warehouses as $wh): ?>
                <tr>
                  <td><?= Renderer::escape($wh['sucursal_nombre']) ?></td>
                  <td><span class="has-text-weight-semibold"><?= Renderer::escape($wh['codigo']) ?></span></td>
                  <td><?= Renderer::escape($wh['nombre']) ?></td>
                  <td>
                    <span class="badge-category"><?= Renderer::escape(ucfirst((string) $wh['tipo'])) ?></span>
                  </td>
                  <td class="has-text-centered">
                    <?= (int) ($wh['total_ubicaciones'] ?? 0) ?>
                  </td>
                  <td class="has-text-centered">
                    <?php if ((int) ($wh['estado_activo'] ?? 1) === 1): ?>
                      <span class="badge-status badge-active">Activo</span>
                    <?php else: ?>
                      <span class="badge-status badge-inactive">Inactivo</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($can('POST', '/warehouses/toggle-active')): ?>
                  <td class="has-text-centered">
                    <form method="post" action="/warehouses/toggle-active" style="display:inline;">
                      <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
                      <input type="hidden" name="id_almacen" value="<?= (int) $wh['id_almacen'] ?>">
                      <button class="btn-sm btn-action-toggle <?= (int) ($wh['estado_activo'] ?? 1) === 1 ? 'btn-sm-danger' : 'btn-sm-activate' ?>" type="submit">
                        <?= (int) ($wh['estado_activo'] ?? 1) === 1 ? 'Desactivar' : 'Activar' ?>
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

  <?php if ($can('POST', '/warehouses')): ?>
  <!-- Modal: Nuevo almacén -->
  <div class="modal <?= !empty($errors) ? 'is-active' : '' ?>" id="modal-warehouse" role="dialog" aria-modal="true" aria-labelledby="modal-warehouse-title">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
      <header class="modal-card-head">
        <p class="modal-card-title is-size-6 has-text-weight-bold" id="modal-warehouse-title">Nuevo almacén</p>
        <button class="delete" type="button" aria-label="Cerrar" data-modal-close></button>
      </header>
      <form method="post" action="/warehouses">
        <section class="modal-card-body">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field mb-3">
            <label class="label is-small">Sucursal <span class="has-text-danger">*</span></label>
            <div class="control">
              <div class="select is-small is-fullwidth <?= isset($errors['id_sucursal']) ? 'is-danger' : '' ?>">
                <select name="id_sucursal" required>
                  <option value="">-- Seleccionar sucursal --</option>
                  <?php foreach ($activeBranches as $br): ?>
                    <option value="<?= (int) $br['id_sucursal'] ?>" <?= (string) ($input['id_sucursal'] ?? '') === (string) $br['id_sucursal'] ? 'selected' : '' ?>>
                      <?= Renderer::escape($br['nombre']) ?> (<?= Renderer::escape($br['codigo']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php if (isset($errors['id_sucursal'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['id_sucursal']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field mb-3">
            <label class="label is-small">Código <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['codigo']) ? 'is-danger' : '' ?>" type="text" name="codigo" value="<?= Renderer::escape($input['codigo'] ?? '') ?>" placeholder="Ej: ALM-01" required>
            </div>
            <?php if (isset($errors['codigo'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['codigo']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field mb-3">
            <label class="label is-small">Nombre del almacén <span class="has-text-danger">*</span></label>
            <div class="control">
              <input class="input is-small <?= isset($errors['nombre']) ? 'is-danger' : '' ?>" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" placeholder="Ej: Almacén Principal" required>
            </div>
            <?php if (isset($errors['nombre'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['nombre']) ?></p>
            <?php endif; ?>
          </div>
          <div class="field">
            <label class="label is-small">Tipo de almacén <span class="has-text-danger">*</span></label>
            <div class="control">
              <div class="select is-small is-fullwidth <?= isset($errors['tipo']) ? 'is-danger' : '' ?>">
                <select name="tipo" required>
                  <option value="bodega" <?= ($input['tipo'] ?? 'bodega') === 'bodega' ? 'selected' : '' ?>>Bodega (Almacenamiento general)</option>
                  <option value="mostrador" <?= ($input['tipo'] ?? '') === 'mostrador' ? 'selected' : '' ?>>Mostrador (Venta / Despacho)</option>
                  <option value="patio" <?= ($input['tipo'] ?? '') === 'patio' ? 'selected' : '' ?>>Patio (Materiales a granel)</option>
                  <option value="merma" <?= ($input['tipo'] ?? '') === 'merma' ? 'selected' : '' ?>>Merma (Cuarentena / Dañado)</option>
                </select>
              </div>
            </div>
            <?php if (isset($errors['tipo'])): ?>
              <p class="help is-danger"><?= Renderer::escape($errors['tipo']) ?></p>
            <?php endif; ?>
          </div>
        </section>
        <footer class="modal-card-foot modal-actions">
          <button class="btn-secondary" type="button" data-modal-close>Cancelar</button>
          <button class="btn-primary" type="submit">Guardar almacén</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
