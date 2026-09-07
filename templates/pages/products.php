<?php

use App\Foundation\Renderer;

/** @var list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}> $products */
$products = $data['products'] ?? [];
$query = isset($data['query']) && is_string($data['query']) ? $data['query'] : '';

ob_start();
?>
<section class="section" id="catalog-section">
  <div class="level">
    <div class="level-left">
      <div class="level-item">
        <h1 class="title">Product Catalog</h1>
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
