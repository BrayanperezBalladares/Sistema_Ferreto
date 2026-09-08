<?php

use App\Foundation\Renderer;

/** @var list<array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}> $counts */
$counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : [];
?>
<div class="card-surface p-0" id="count-history-container">
  <?php if (empty($counts)): ?>
    <div class="has-text-centered py-6 px-4">
      <p class="is-size-2 mb-2" aria-hidden="true">📋</p>
      <p class="has-text-grey is-size-6 mb-0">No hay conteos registrados para esta existencia.</p>
    </div>
  <?php else: ?>
    <div class="table-container mb-0">
      <table class="table is-fullwidth is-hoverable is-narrow operational-table mb-0">
        <thead>
          <tr>
            <th scope="col" style="width: 25%;">Fecha</th>
            <th scope="col" style="width: 20%;" class="has-text-right">Cantidad sistema</th>
            <th scope="col" style="width: 20%;" class="has-text-right">Cantidad contada</th>
            <th scope="col" style="width: 15%;" class="has-text-right">Diferencia</th>
            <th scope="col" style="width: 20%;">Notas</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($counts as $c): ?>
            <?php
            $diff = $c['diferencia'];
            $isNeg = str_starts_with($diff, '-');
            $isZero = $diff === '0' || $diff === '0.000' || ltrim($diff, '0.') === '';
            $diffClass = $isNeg ? 'has-text-danger' : ($isZero ? 'has-text-grey' : 'has-text-success');
            $diffText = $isNeg ? $diff : ($isZero ? '0.000' : '+' . $diff);
            ?>
            <tr>
              <td><span class="has-text-weight-medium is-family-monospace is-size-7"><?= Renderer::escape($c['created_at']) ?></span></td>
              <td class="has-text-right"><span class="is-family-monospace"><?= Renderer::escape($c['cantidad_sistema']) ?></span></td>
              <td class="has-text-right"><span class="is-family-monospace"><?= Renderer::escape($c['cantidad_contada']) ?></span></td>
              <td class="has-text-right"><span class="is-family-monospace has-text-weight-semibold <?= $diffClass ?>"><?= Renderer::escape($diffText) ?></span></td>
              <td><span class="is-size-7 <?= $c['notas'] !== null && $c['notas'] !== '' ? 'has-text-dark' : 'has-text-grey-light' ?>"><?= $c['notas'] !== null && $c['notas'] !== '' ? Renderer::escape($c['notas']) : '—' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>