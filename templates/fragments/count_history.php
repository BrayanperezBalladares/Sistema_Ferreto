<?php

use App\Foundation\Renderer;

/** @var list<array{id_conteo: int, id_stock: int, cantidad_sistema: string, cantidad_contada: string, diferencia: string, notas: ?string, created_at: string}> $counts */
$counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : [];
?>
<div class="ferreto-card" id="count-history-container">
  <?php if (empty($counts)): ?>
    <div class="empty-state-box">
      <div class="empty-state-icon" aria-hidden="true">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
      </div>
      <h3 class="empty-state-title">No hay conteos registrados para esta existencia.</h3>
      <p class="empty-state-desc">Usa el botón "Registrar conteo" para añadir la primera observación física.</p>
    </div>
  <?php else: ?>
    <div class="table-container mb-0">
      <table class="ferreto-table count-history-table">
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
              <td class="cell-date">
                <span class="count-date"><?= Renderer::escape($c['created_at']) ?></span>
              </td>
              <td class="has-text-right cell-qty-sys">
                <span class="count-label-mobile">Sistema: </span>
                <span class="count-val"><?= Renderer::escape($c['cantidad_sistema']) ?></span>
              </td>
              <td class="has-text-right cell-qty-counted">
                <span class="count-label-mobile">Contado: </span>
                <span class="count-val has-text-weight-semibold"><?= Renderer::escape($c['cantidad_contada']) ?></span>
              </td>
              <td class="has-text-right cell-diff">
                <span class="count-label-mobile">Diferencia: </span>
                <span class="count-diff has-text-weight-semibold <?= $diffClass ?>"><?= Renderer::escape($diffText) ?></span>
              </td>
              <td class="cell-notes">
                <?php if ($c['notas'] !== null && $c['notas'] !== ''): ?>
                  <span class="count-notes"><?= Renderer::escape($c['notas']) ?></span>
                <?php else: ?>
                  <span class="count-notes-empty">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>