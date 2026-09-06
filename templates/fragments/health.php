<?php

use App\Foundation\Renderer;

$result = $data['result'];
?>
<section id="health"><h1 class="title">Foundation health</h1>
  <div data-notification role="status"><?= Renderer::escape($data['message'] ?? '') ?></div>
  <form method="post" action="/health" hx-post="/health" hx-target="#health">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($data['csrf']) ?>">
    <label class="label" for="probe">Probe</label>
    <input class="input" id="probe" name="probe" value="<?= Renderer::escape($result->safeInput['probe'] ?? '') ?>">
    <?php if (isset($result->fieldErrors['probe'])): ?><p class="help is-danger"><?= Renderer::escape($result->fieldErrors['probe']) ?></p><?php endif ?>
    <button class="button is-primary" type="submit">Run check</button>
  </form>
</section>
