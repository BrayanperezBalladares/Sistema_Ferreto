<?php

use App\Foundation\Renderer;

/** @var list<array{id_producto: int, id_categoria: ?int, categoria_nombre: ?string, nombre: string, descripcion: ?string, precio_actual: string, estado_activo: int, created_at: string, updated_at: string}> $products */
$products = $data['products'] ?? [];
?>
<div id="product-table-container">
<?php if ($products === []): ?>
  <div class="notification is-info is-light" data-test="empty-catalog">
    <p>No products found.</p>
  </div>
<?php else: ?>
  <div class="table-container">
    <table class="table is-striped is-hoverable is-fullwidth">
      <thead>
        <tr>
          <th>Product</th>
          <th>Category</th>
          <th>Price</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr data-product-id="<?= Renderer::escape((string) $p['id_producto']) ?>">
            <td class="has-text-weight-semibold"><?= Renderer::escape($p['nombre']) ?></td>
            <td>
              <?php if ($p['categoria_nombre'] !== null && $p['categoria_nombre'] !== ''): ?>
                <span class="tag is-info is-light"><?= Renderer::escape($p['categoria_nombre']) ?></span>
              <?php else: ?>
                <span class="tag is-light">Unclassified</span>
              <?php endif; ?>
            </td>
            <td>$<?= Renderer::escape($p['precio_actual']) ?></td>
            <td>
              <?php if ($p['estado_activo'] === 1): ?>
                <span class="tag is-success">Active</span>
              <?php else: ?>
                <span class="tag is-danger is-light">Inactive</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
