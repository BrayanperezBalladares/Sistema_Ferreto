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
<section class="section" id="catalog-section">
  <h1 class="title mb-5">Product Catalog</h1>

  <div class="columns mb-5">
    <div class="column is-one-third">
      <div class="box">
        <h2 class="subtitle is-6 has-text-weight-bold">New Category</h2>
        <form method="post" action="/categories">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="field">
            <label class="label is-small">Name</label>
            <div class="control">
              <input class="input is-small" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" required>
            </div>
          </div>
          <div class="field">
            <label class="label is-small">Description</label>
            <div class="control">
              <input class="input is-small" type="text" name="descripcion" value="<?= Renderer::escape($input['descripcion'] ?? '') ?>">
            </div>
          </div>
          <button class="button is-small is-primary is-fullwidth" type="submit">Create Category</button>
        </form>
      </div>
    </div>

    <div class="column is-two-thirds">
      <div class="box">
        <h2 class="subtitle is-6 has-text-weight-bold">Register Product</h2>
        <form method="post" action="/products">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <div class="columns is-multiline">
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Product Name</label>
                <div class="control">
                  <input class="input is-small" type="text" name="nombre" value="<?= Renderer::escape($input['nombre'] ?? '') ?>" required>
                </div>
              </div>
            </div>
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Price (e.g. 19.99)</label>
                <div class="control">
                  <input class="input is-small" type="text" name="precio_actual" placeholder="0.00" value="<?= Renderer::escape($input['precio_actual'] ?? '') ?>" required>
                </div>
              </div>
            </div>
            <div class="column is-half">
              <div class="field">
                <label class="label is-small">Category</label>
                <div class="control">
                  <div class="select is-small is-fullwidth">
                    <select name="id_categoria">
                      <option value="">-- No category (unclassified) --</option>
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
                <label class="label is-small">Description</label>
                <div class="control">
                  <input class="input is-small" type="text" name="descripcion" value="<?= Renderer::escape($input['descripcion'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
          <button class="button is-small is-primary is-fullwidth" type="submit">Register Product</button>
        </form>
      </div>
    </div>
  </div>

  <div class="field mb-5">
    <div class="control">
      <input
        class="input"
        type="search"
        name="q"
        placeholder="Search products..."
        value="<?= Renderer::escape($query) ?>"
        hx-get="/products"
        hx-trigger="keyup changed delay:300ms, search"
        hx-target="#product-table-container"
        hx-swap="outerHTML"
      >
    </div>
  </div>

  <?php require dirname(__DIR__) . '/fragments/product_table.php'; ?>
</section>
<?php
$content = (string) ob_get_clean();
require dirname(__DIR__) . '/layout.php';
