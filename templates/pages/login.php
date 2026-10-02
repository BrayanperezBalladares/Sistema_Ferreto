<?php

use App\Foundation\Renderer;

$csrf     = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
$username = isset($data['username']) && is_string($data['username']) ? $data['username'] : '';
$error    = isset($data['error']) && is_string($data['error']) ? $data['error'] : null;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar sesión — Ferreterías El Constructor</title>
  <link rel="stylesheet" href="/assets/bulma.min.css">
  <link rel="stylesheet" href="/assets/ferreto.css">
</head>
<body class="ferreto-app login-screen">
  <main class="login-container">
    <div class="card login-card">

      <header class="login-brand">
        <div class="brand-mark" aria-hidden="true">FC</div>
        <div class="brand-text">
          <span class="brand-name">El Constructor</span>
          <span class="brand-sub">Ferreterías</span>
        </div>
      </header>

      <div class="login-body">
        <h1 class="login-title">Iniciar sesión</h1>
        <p class="login-subtitle">Acceso al sistema operativo interno</p>

        <?php if (!empty($error)): ?>
          <div class="login-error-alert" role="alert">
            <span aria-hidden="true">⚠</span>
            <span><?= Renderer::escape($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="/login" class="login-form">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">

          <div class="field">
            <label class="label login-label" for="username">
              Usuario <span class="login-required" aria-hidden="true">*</span>
            </label>
            <div class="control">
              <input class="input login-input" id="username" name="username" type="text" autocomplete="username" required value="<?= Renderer::escape($username) ?>">
            </div>
          </div>

          <div class="field field-password">
            <label class="label login-label" for="password">
              Contraseña <span class="login-required" aria-hidden="true">*</span>
            </label>
            <div class="control">
              <input class="input login-input" id="password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••••••">
            </div>
          </div>

          <div class="field">
            <button type="submit" class="btn-primary login-submit">
              Ingresar al sistema
            </button>
          </div>
        </form>
      </div>

    </div>
  </main>
</body>
</html>
