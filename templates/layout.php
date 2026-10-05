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
  <a href="#main-content" class="skip-link">Saltar al contenido principal</a>
  <div class="app-layout">
    <aside class="app-sidebar" id="app-sidebar">
      <div class="sidebar-brand">
        <div class="brand-mark" aria-hidden="true">FC</div>
        <div class="brand-text">
          <span class="brand-name">El Constructor</span>
          <span class="brand-sub">Ferreterías</span>
        </div>
        <button type="button" class="drawer-close" aria-label="Cerrar menú de navegación" data-drawer-close>
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
<?php
use App\Foundation\Renderer;

$activeNav = $activeNav ?? 'products';
$user = $data['user'] ?? null;
$csrf = $data['csrf'] ?? '';
$roleLabels = [
    'administrador' => 'Administrador',
    'bodeguero' => 'Bodeguero',
    'cajero' => 'Cajero',
    'compras' => 'Compras',
];
$roleLabel = $user !== null ? ($roleLabels[$user['rol']] ?? ucfirst((string) $user['rol'])) : '';

/** @var \App\Modules\Access\ViewPermissions|null $permissions */
$permissions = $data['permissions'] ?? null;
$can = $permissions instanceof \App\Modules\Access\ViewPermissions
    ? $permissions->can(...)
    : static fn (string $method, string $path): bool => false;
?>
      <nav class="sidebar-nav" aria-label="Navegación principal">
        <?php if ($can('GET', '/products') || $can('GET', '/branches') || $can('GET', '/warehouses') || $can('GET', '/locations')): ?>
        <div class="nav-section-label">Catálogo</div>
        <?php endif; ?>
        <?php if ($can('GET', '/products')): ?>
        <a href="/products" class="nav-item <?= $activeNav === 'products' ? 'is-active' : '' ?>"<?= $activeNav === 'products' ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
          <span class="nav-label">Productos</span>
        </a>
        <?php endif; ?>
        <?php if ($can('GET', '/branches')): ?>
        <a href="/branches" class="nav-item <?= $activeNav === 'branches' ? 'is-active' : '' ?>"<?= $activeNav === 'branches' ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-3"></path></svg></span>
          <span class="nav-label">Sucursales</span>
        </a>
        <?php endif; ?>
        <?php if ($can('GET', '/warehouses')): ?>
        <a href="/warehouses" class="nav-item <?= $activeNav === 'warehouses' ? 'is-active' : '' ?>"<?= $activeNav === 'warehouses' ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path></svg></span>
          <span class="nav-label">Almacenes</span>
        </a>
        <?php endif; ?>
        <?php if ($can('GET', '/locations')): ?>
        <a href="/locations" class="nav-item <?= $activeNav === 'locations' ? 'is-active' : '' ?>"<?= $activeNav === 'locations' ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></span>
          <span class="nav-label">Ubicaciones</span>
        </a>
        <?php endif; ?>
        <?php if ($can('GET', '/inventory') || $can('GET', '/inventory/counts')): ?>
        <div class="nav-section-label">Inventario</div>
        <?php endif; ?>
        <?php if ($can('GET', '/inventory')): ?>
        <a href="/inventory" class="nav-item <?= $activeNav === 'inventory' ? 'is-active' : '' ?>"<?= $activeNav === 'inventory' ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg></span>
          <span class="nav-label">Existencias por ubicación</span>
        </a>
        <?php endif; ?>
        <?php if ($can('GET', '/inventory/counts')): ?>
        <a href="/inventory/counts" class="nav-item <?= $activeNav === 'counts' ? 'is-active' : '' ?>"<?= $activeNav === 'counts' ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><polyline points="9 14 11 16 15 11"></polyline></svg></span>
          <span class="nav-label">Conteos físicos</span>
        </a>
        <?php endif; ?>
      </nav>
      <?php if ($user !== null): ?>
      <div class="sidebar-user">
        <div class="sidebar-user-meta">
          <span class="user-name"><?= Renderer::escape($user['username']) ?></span>
          <span class="badge-role"><?= Renderer::escape($roleLabel) ?></span>
        </div>
        <form method="post" action="/logout" class="logout-form">
          <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
          <button type="submit" class="btn-logout" aria-label="Cerrar sesión">
            <span class="logout-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg></span>
            <span class="logout-text">Cerrar sesión</span>
          </button>
        </form>
      </div>
      <?php endif; ?>
    </aside>
    <div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true" data-drawer-close></div>
    <div class="app-main">
      <header class="app-topbar">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Abrir menú de navegación" aria-expanded="false" aria-controls="app-sidebar">
          <span class="nav-toggle-bar" aria-hidden="true"></span>
          <span class="nav-toggle-bar" aria-hidden="true"></span>
          <span class="nav-toggle-bar" aria-hidden="true"></span>
        </button>
        <div class="topbar-context">
          <?php if ($activeNav === 'counts'): ?>
            <span class="topbar-crumb">Inventario</span> <span class="topbar-sep" aria-hidden="true">/</span> <span class="topbar-current">Conteos físicos</span>
          <?php elseif ($activeNav === 'inventory'): ?>
            <span class="topbar-crumb">Inventario</span>
            <span class="topbar-sep" aria-hidden="true">/</span>
            <span class="topbar-current">Existencias por ubicación</span>
          <?php elseif ($activeNav === 'branches'): ?>
            <span class="topbar-crumb">Catálogo</span>
            <span class="topbar-sep" aria-hidden="true">/</span>
            <span class="topbar-current">Sucursales</span>
          <?php elseif ($activeNav === 'warehouses'): ?>
            <span class="topbar-crumb">Catálogo</span>
            <span class="topbar-sep" aria-hidden="true">/</span>
            <span class="topbar-current">Almacenes</span>
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
        <?php if ($user !== null): ?>
        <div class="topbar-user">
          <div class="user-meta">
            <span class="user-name"><?= Renderer::escape($user['username']) ?></span>
            <span class="badge-role"><?= Renderer::escape($roleLabel) ?></span>
          </div>
          <form method="post" action="/logout" class="logout-form">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
            <button type="submit" class="btn-logout" aria-label="Cerrar sesión">
              <span class="logout-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg></span>
              <span class="logout-text">Salir</span>
            </button>
          </form>
        </div>
        <?php endif; ?>
      </header>
      <main class="app-workspace" id="main-content">
        <?= $content ?>
      </main>
    </div>
  </div>
</body>
</html>
