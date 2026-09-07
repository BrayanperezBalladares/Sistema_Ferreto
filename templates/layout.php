<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ferreterías El Constructor</title>
  <link rel="stylesheet" href="/assets/bulma.min.css">
  <link rel="stylesheet" href="/assets/ferreto.css">
  <script src="/assets/htmx.min.js" defer></script>
  <script src="/assets/app.js" defer></script>
</head>
<body class="ferreto-app">
  <div class="app-layout">
    <aside class="app-sidebar">
      <div class="sidebar-brand">
        <div class="brand-mark" aria-hidden="true">FC</div>
        <div class="brand-text">
          <span class="brand-name">El Constructor</span>
          <span class="brand-sub">Ferreterías</span>
        </div>
      </div>
      <nav class="sidebar-nav" aria-label="Navegación principal">
        <div class="nav-section-label">Catálogo</div>
        <a href="/products" class="nav-item is-active">
          <span class="nav-icon" aria-hidden="true">📦</span>
          <span class="nav-label">Productos</span>
        </a>
      </nav>
    </aside>
    <div class="app-main">
      <header class="app-topbar">
        <div class="topbar-context">
          <span class="topbar-crumb">Catálogo</span>
          <span class="topbar-sep" aria-hidden="true">/</span>
          <span class="topbar-current">Productos</span>
        </div>
      </header>
      <main class="app-workspace">
        <?= $content ?>
      </main>
    </div>
  </div>
</body>
</html>
