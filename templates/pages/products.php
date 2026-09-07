<?php

use App\Foundation\Renderer;

/** @var list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}> $products */
$products = $data['products'] ?? [];
/** @var list<array{id_categoria: int, nombre: string, descripcion: ?string, created_at: string, updated_at: string}> $categories */
$categories = $data['categories'] ?? [];
$query = isset($data['query']) && is_string($data['query']) ? $data['query'] : '';
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
/** @var array<string, string> $input */
$input = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];

ob_start();
?>
<section id="catalog-section">
  <div class="page-header">
    <div>
      <h1 class="page-title">Productos</h1>
      <p class="page-subtitle">Gestión de artículos, precios de venta y disponibilidad.</p>
    </div>
  </div>

  <div class="columns mb-5">
    <div class="column is-one-third">
      <div class="ferreto-card p-4">
        <h2 class="title is-6 mb-3 has-text-weight-bold">Nueva categoría</h2>
        <form method="post" action="/categories">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field">
            <label class="label is-small">Nombre</label>
            <div class="control">
              <input class="input is-small" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" required>
            </div>
          </div>
          <div class="field">
            <label class="label is-small">Descripción</label>
            <div class="control">
              <input class="input is-small" type="text" name="descripcion" value="<?= Renderer::escape($input['descripcion'] ?? '') ?>">
            </div>
          </div>
          <button class="btn-primary is-fullwidth" style="width: 100%; justify-content: center;" type="submit">Guardar categoría</button>
        </form>
      </div>
    </div>

    <div class="column is-two-thirds">
      <div class="ferreto-card p-4">
        <h2 class="title is-6 mb-3 has-text-weight-bold">Registrar producto</h2>
        <form method="post" action="/products">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="columns is-multiline">
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Nombre del producto</label>
                <div class="control">
                  <input class="input is-small" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" required>
                </div>
              </div>
            </div>
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Precio actual</label>
                <div class="control">
                  <input class="input is-small" type="text" name="precio_actual" placeholder="0.00" value="<?= Renderer::escape($input['precio_actual'] ?? '') ?>" required>
                </div>
              </div>
            </div>
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Categoría</label>
                <div class="control">
                  <div class="select is-small is-fullwidth">
                    <select name="id_categoria">
                      <option value="">-- Sin categoría --</option>
                      <?php foreach ($categories as $cat): ?>
                        <option value="<?= Renderer::escape((string) $cat['id_categoria']) ?>">
                          <?= Renderer::escape($cat['nombre']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
            </div>
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Descripción</label>
                <div class="control">
                  <input class="input is-small" type="text" name="descripcion" value="<?= Renderer::escape($input['descripcion'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
          <button class="btn-primary is-fullwidth" style="width: 100%; justify-content: center;" type="submit">Registrar producto</button>
        </form>
      </div>
    </div>
  </div>

  <div class="search-bar-card">
    <input
      class="search-input"
      type="search"
      name="q"
      placeholder="Buscar por nombre del producto…"
      value="<?= Renderer::escape($query) ?>"
      hx-get="/products"
      hx-trigger="keyup changed delay:300ms, search"
      hx-target="#product-table-container"
      hx-swap="outerHTML"
      aria-label="Buscar por nombre del producto"
    >
  </div>

  <?php require dirname(__DIR__) . '/fragments/product_table.php'; ?>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
