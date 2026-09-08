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
<?php
$activeNav = $activeNav ?? 'products';
?>
      <nav class="sidebar-nav" aria-label="Navegación principal">
        <div class="nav-section-label">Catálogo</div>
        <a href="/products" class="nav-item <?= $activeNav === 'products' ? 'is-active' : '' ?>">
          <span class="nav-icon" aria-hidden="true">📦</span>
          <span class="nav-label">Productos</span>
        </a>
        <a href="/locations" class="nav-item <?= $activeNav === 'locations' ? 'is-active' : '' ?>">
          <span class="nav-icon" aria-hidden="true">📍</span>
          <span class="nav-label">Ubicaciones</span>
        </a>
        <div class="nav-section-label">Inventario</div>
        <a href="/inventory" class="nav-item <?= $activeNav === 'inventory' ? 'is-active' : '' ?>">
          <span class="nav-icon" aria-hidden="true">📊</span>
          <span class="nav-label">Existencias por ubicación</span>
        </a>
      </nav>
    </aside>
    <div class="app-main">
      <header class="app-topbar">
        <div class="topbar-context">
          <?php if ($activeNav === 'inventory'): ?>
            <span class="topbar-crumb">Inventario</span>
            <span class="topbar-sep" aria-hidden="true">/</span>
            <span class="topbar-current">Existencias por ubicación</span>
          <?php elseif ($activeNav === 'locations'): ?>
            <span class="topbar-crumb">Catálogo</span>
            <span class="topbar-sep" aria-hidden="true">/</span>
            <span class="topbar-current">Ubicaciones</span>
          <?php else: ?>
            <span class="topbar-crumb">Catálogo</span>
            <span class="topbar-sep" aria-hidden="true">/</span>
            <span class="topbar-current">Productos</span>
          <?php endif; ?>
        </div>
      </header>
      <main class="app-workspace">
        <?= $content ?>
      </main>
    </div>
  </div>
</body>
</html>
