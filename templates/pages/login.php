<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar sesión — Ferreterías El Constructor</title>
  <link rel="stylesheet" href="/assets/bulma.min.css">
  <link rel="stylesheet" href="/assets/ferreto.css">
</head>
<body class="ferreto-app login-screen" style="margin: 0; padding: 0; background-color: var(--color-bg, #F8FAFC); min-height: 100vh; display: flex; align-items: center; justify-content: center;">
  <main class="login-container" style="width: 100%; max-width: 400px; padding: 16px; box-sizing: border-box;">
    <div class="card login-card" style="background: var(--color-surface, #FFFFFF); border: 1px solid var(--color-border, #E5E7EB); border-radius: var(--radius-md, 8px); box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05)); overflow: hidden;">
      
      <header class="login-brand" style="background: var(--color-nav, #111827); padding: 20px 24px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
        <div class="brand-mark" aria-hidden="true" style="width: 36px; height: 36px; background: var(--color-primary, #F59E0B); color: #111827; font-weight: 700; font-size: 14px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm, 6px); flex-shrink: 0; letter-spacing: 0.05em;">FC</div>
        <div class="brand-text" style="display: flex; flex-direction: column;">
          <span class="brand-name" style="font-size: 15px; font-weight: 700; color: var(--color-nav-text, #F9FAFB); line-height: 1.2;">El Constructor</span>
          <span class="brand-sub" style="font-size: 11px; color: var(--color-nav-muted, #94A3B8); text-transform: uppercase; letter-spacing: 0.06em;">Ferreterías</span>
        </div>
      </header>

      <div class="login-body" style="padding: 24px;">
        <h1 class="login-title" style="font-size: 20px; font-weight: 700; color: var(--color-text, #0F172A); margin: 0 0 4px 0; line-height: 1.2;">Iniciar sesión</h1>
        <p class="login-subtitle" style="font-size: 13px; color: var(--color-text-muted, #64748B); margin: 0 0 20px 0;">Acceso al sistema operativo interno</p>

        <?php if (!empty($error)): ?>
          <div class="login-error-alert" role="alert" style="background-color: var(--color-danger-subtle, #FEE2E2); color: var(--color-danger-text, #991B1B); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: var(--radius-sm, 6px); padding: 12px 14px; font-size: 13px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <span aria-hidden="true" style="font-weight: 700;">⚠</span>
            <span><?= \App\Foundation\Renderer::escape($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="/login" class="login-form">
          <input type="hidden" name="_csrf" value="<?= \App\Foundation\Renderer::escape($csrf ?? '') ?>">

          <div class="field" style="margin-bottom: 16px;">
            <label class="label" for="username" style="font-size: 13px; font-weight: 600; color: var(--color-text, #0F172A); margin-bottom: 6px; display: block;">
              Usuario <span style="color: var(--color-danger, #EF4444); font-size: 12px;" aria-hidden="true">*</span>
            </label>
            <div class="control">
              <input class="input" id="username" name="username" type="text" autocomplete="username" required value="<?= \App\Foundation\Renderer::escape($username ?? '') ?>" placeholder="ej. brayan" style="min-height: 40px; border-radius: var(--radius-sm, 6px); font-size: 14px; border: 1px solid var(--color-border, #E5E7EB); width: 100%; padding: 8px 12px; box-sizing: border-box; color: var(--color-text, #0F172A);">
            </div>
          </div>

          <div class="field" style="margin-bottom: 24px;">
            <label class="label" for="password" style="font-size: 13px; font-weight: 600; color: var(--color-text, #0F172A); margin-bottom: 6px; display: block;">
              Contraseña <span style="color: var(--color-danger, #EF4444); font-size: 12px;" aria-hidden="true">*</span>
            </label>
            <div class="control">
              <input class="input" id="password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••••••" style="min-height: 40px; border-radius: var(--radius-sm, 6px); font-size: 14px; border: 1px solid var(--color-border, #E5E7EB); width: 100%; padding: 8px 12px; box-sizing: border-box; color: var(--color-text, #0F172A);">
            </div>
          </div>

          <div class="field">
            <button type="submit" class="btn-primary" style="width: 100%; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; cursor: pointer; border-radius: var(--radius-sm, 6px); border: none; background-color: var(--color-primary, #F59E0B); color: #FFFFFF; transition: background-color 0.15s ease;">
              Ingresar al sistema
            </button>
          </div>
        </form>
      </div>

    </div>
  </main>
</body>
</html>
