# Sidebar Navigation Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the current top horizontal navbar menu links into a collapsible left sidebar, retaining the top bar as a slim strip for the hamburger toggle and user/notification info.

**Architecture:** A fixed `240px` sidebar for desktop containing role-based links. A top sticky header for search/toggle/notifications. Uses `localStorage` to persist collapsed state across full-page reloads, with an inline script at the top of `<body>` to prevent render flash. Collapsed/hidden by default on mobile.

**Tech Stack:** Pure PHP 8, Vanilla CSS, Vanilla JS.

---

### Task 1: Create Settings and Hamburger SVGs

**Files:**
- Create: `assets/icons/settings.svg`
- Create: `assets/icons/menu.svg`

- [ ] **Step 1: Write `assets/icons/settings.svg`**

Write the settings/gear SVG file.
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
  <circle cx="12" cy="12" r="3"/>
</svg>
```

- [ ] **Step 2: Write `assets/icons/menu.svg`**

Write the hamburger menu SVG file.
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <line x1="4" x2="20" y1="12" y2="12"/>
  <line x1="4" x2="20" y1="6" y2="6"/>
  <line x1="4" x2="20" y1="18" y2="18"/>
</svg>
```

- [ ] **Step 3: Commit**

```bash
rtk git add assets/icons/settings.svg assets/icons/menu.svg
rtk git commit -m "feat: add settings and menu SVG icons"
```

---

### Task 2: Update CSS to replace Horizontal Nav with Sidebar CSS

**Files:**
- Modify: `assets/css/style.css`

- [ ] **Step 1: Replace existing navigation CSS and layout CSS**

Read `assets/css/style.css` and replace lines 78 to 175 (from `/* ===== NAVIGATION ===== */` to the end of `/* ===== LAYOUT ===== */`) with:

```css
/* ===== LAYOUT & SIDEBAR ===== */
:root {
    --sidebar-width: 240px;
    --topbar-height: 56px;
    --transition-speed: 0.2s;
}

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Sidebar styles */
.site-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sidebar-width);
    height: 100vh;
    background: var(--color-surface);
    border-right: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    z-index: 100;
    transition: transform var(--transition-speed) ease;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    height: var(--topbar-height);
    padding: 0 1.5rem;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: var(--color-primary);
    color: #fff;
    font-family: var(--font-serif);
    font-weight: 600;
    font-size: 0.85rem;
    border-radius: var(--radius-sm);
}

.brand-text {
    font-family: var(--font-mono);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--color-text);
}

/* Navigation Links */
.sidebar-nav {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.82rem;
    color: var(--color-text-secondary);
    text-decoration: none;
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius-sm);
    transition: color 0.15s, background 0.15s;
}

.sidebar-nav a svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    stroke-width: 2px;
}

.sidebar-nav a:hover {
    color: var(--color-text);
    background: var(--color-canvas);
}

.sidebar-nav a.nav-active {
    color: var(--color-primary);
    background: var(--color-canvas);
    font-weight: 500;
}

/* Sidebar Footer */
.sidebar-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    background: var(--color-surface);
}

.user-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.user-badge {
    font-size: 0.7rem;
    font-family: var(--font-mono);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: var(--pastel-blue-bg);
    color: var(--pastel-blue-text);
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
}

.nav-logout {
    font-size: 0.82rem;
    color: var(--color-text-secondary);
    text-decoration: none;
    transition: color 0.15s;
    font-weight: 500;
}

.nav-logout:hover {
    color: var(--color-text);
}

/* Topbar styles */
.site-topbar {
    position: sticky;
    top: 0;
    height: var(--topbar-height);
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1.5rem;
    z-index: 99;
    margin-left: var(--sidebar-width);
    transition: margin-left var(--transition-speed) ease;
}

#sidebar-toggle {
    background: none;
    border: none;
    color: var(--color-text);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem;
    border-radius: var(--radius-sm);
    transition: background 0.15s;
}

#sidebar-toggle:hover {
    background: var(--color-canvas);
}

#sidebar-toggle svg {
    width: 20px;
    height: 20px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* Main Content Area */
.site-main {
    flex: 1;
    margin-left: var(--sidebar-width);
    transition: margin-left var(--transition-speed) ease;
    padding: 2rem;
    width: auto;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
}

/* Collapsed state (desktop) */
body.sidebar-collapsed .site-sidebar {
    transform: translateX(-100%);
}

body.sidebar-collapsed .site-topbar {
    margin-left: 0;
}

body.sidebar-collapsed .site-main {
    margin-left: 0;
}

/* Responsive design (mobile) */
@media (max-width: 768px) {
    .site-sidebar {
        transform: translateX(-100%);
    }
    
    .site-topbar {
        margin-left: 0;
    }
    
    .site-main {
        margin-left: 0;
        padding: 1.5rem 1rem;
    }
    
    body.sidebar-open .site-sidebar {
        transform: translateX(0);
    }
    
    /* Overlay background when sidebar is open on mobile */
    body.sidebar-open::after {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.4);
        z-index: 98;
        pointer-events: auto;
    }
}
```

- [ ] **Step 2: Commit**

```bash
rtk git add assets/css/style.css
rtk git commit -m "style: replace top navbar with sidebar layout CSS"
```

---

### Task 3: Implement Collapsible Sidebar JS in main.js

**Files:**
- Modify: `assets/js/main.js`

- [ ] **Step 1: Append sidebar toggle listener to main.js**

Read `assets/js/main.js` and add the toggle listener at the bottom of the `DOMContentLoaded` event listener:

```javascript
    // ===== SIDEBAR COLLAPSE TOGGLE =====
    var toggleBtn = document.getElementById('sidebar-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (window.innerWidth <= 768) {
                document.body.classList.toggle('sidebar-open');
                document.body.classList.remove('sidebar-collapsed');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                document.body.classList.remove('sidebar-open');
                localStorage.setItem('sidebar_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
            }
        });
    }

    // Close sidebar on mobile when clicking outside
    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')) {
            var sidebar = document.querySelector('.site-sidebar');
            if (sidebar && !sidebar.contains(e.target) && e.target !== toggleBtn && !toggleBtn.contains(e.target)) {
                document.body.classList.remove('sidebar-open');
            }
        }
    });
```

- [ ] **Step 2: Commit**

```bash
rtk git add assets/js/main.js
rtk git commit -m "feat: add sidebar collapsible toggle logic and close on mobile click outside"
```

---

### Task 4: Revamp PHP Navigation Layout

**Files:**
- Modify: `index.php:465-540`

- [ ] **Step 1: Replace layout() navigation HTML and template structure**

Read `index.php` lines 465 to 540. Modify the navigation rendering logic inside `layout()` to:
1. Load SVG icons dynamically from `assets/icons/`.
2. Produce `<aside class="site-sidebar">` and `<header class="site-topbar">`.
3. Add the inline state restoring script in the `<head>` of HTML.
4. Replace `<main class="container">` with `<main class="site-main"><div class="container">`.

Here is the code to be implemented:

```php
function layout(string $title, string $content): void {
    $u = current_user();
    $sidebar = '';
    $topbar = '';
    if ($u) {
        $items = [];
        if ($u['role'] === 'admin') {
            $items['dashboard'] = 'Dashboard';
            $items['prodi'] = 'Prodi';
            $items['mahasiswa'] = 'Mahasiswa';
            $items['dosen'] = 'Dosen';
            $items['mata_kuliah'] = 'Mata Kuliah';
            $items['tahun_akademik'] = 'T.A.';
            $items['kelas'] = 'Kelas';
            $items['krs'] = 'KRS';
            $items['nilai'] = 'Nilai';
            $items['presensi'] = 'Presensi';
            $items['khs'] = 'KHS';
            $items['broadcast'] = 'Broadcast';
            $items['settings'] = 'Pengaturan';
        } elseif ($u['role'] === 'dosen') {
            $items['dashboard'] = 'Dashboard';
            $items['presensi'] = 'Presensi';
            $items['nilai'] = 'Nilai';
        } elseif ($u['role'] === 'mahasiswa') {
            $items['dashboard'] = 'Dashboard';
            $items['krs'] = 'KRS';
            $items['khs'] = 'KHS';
        }
        $nav_l = '';
        foreach ($items as $p => $l) {
            $active = ($_GET['page'] ?? '') === $p ? ' class="nav-active"' : '';
            
            // Map settings to settings.svg, others to corresponding SVGs
            $icon_file = "assets/icons/{$p}.svg";
            $icon_svg = '';
            if (file_exists($icon_file)) {
                $icon_svg = file_get_contents($icon_file);
            }
            
            $nav_l .= "<a href=\"?page=$p\"$active>$icon_svg <span>$l</span></a>\n";
        }
        $role_label = ucfirst($u['role']);
        
        $menu_svg = '';
        if (file_exists('assets/icons/menu.svg')) {
            $menu_svg = file_get_contents('assets/icons/menu.svg');
        }

        $sidebar = <<<HTML
        <aside class="site-sidebar">
            <div class="sidebar-brand">
                <span class="brand-mark">S</span>
                <span class="brand-text">SIAKAD</span>
            </div>
            <nav class="sidebar-nav">
                $nav_l
            </nav>
            <div class="sidebar-footer">
                <div class="user-info">
                    <span class="user-badge">$role_label</span>
                    <a href="?page=logout" class="nav-logout">Keluar</a>
                </div>
                <div style="font-size:0.8rem; color:var(--color-text-secondary); text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">
                    {$u['name']}
                </div>
            </div>
        </aside>
HTML;

        $topbar = <<<HTML
        <header class="site-topbar">
            <button id="sidebar-toggle" aria-label="Toggle Sidebar">
                $menu_svg
            </button>
            <div class="topbar-right">
                <span class="notif-badge" id="notif-badge" style="display:none">0</span>
            </div>
        </header>
HTML;
    }
    $flash = '';
    foreach (flash_get() as $type => $msg) {
        $cls = $type === 'error' ? 'flash-error' : 'flash-success';
        $flash .= "<div class=\"flash $cls\">" . e($msg) . "</div>\n";
    }
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} - SIAKAD</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,600;1,6..72,400&family=Geist+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<script>
  // Restore sidebar state early to avoid visual flash/jump
  if (window.innerWidth > 768 && localStorage.getItem('sidebar_collapsed') === '1') {
    document.documentElement.classList.add('sidebar-collapsed');
  }
</script>
</head>
<body class="html-sidebar-class-placeholder">
<script>
  // Synchronize documentElement class to body
  if (document.documentElement.classList.contains('sidebar-collapsed')) {
    document.body.classList.add('sidebar-collapsed');
  }
</script>
$sidebar
$topbar
<main class="site-main">
    <div class="container">
        $flash
        $content
    </div>
</main>
<script src="assets/js/main.js"></script>
</body>
</html>
HTML;
}
```

Wait, in the script in `<head>` we add the class to `document.documentElement` so it's active immediately, and then at the start of `<body>` we copy it to `document.body` before rendering! This is extremely robust and ensures zero flash.

- [ ] **Step 2: Commit**

```bash
rtk git add index.php
rtk git commit -m "feat: revamp PHP layout to sidebar & topbar structure with inline anti-flash script"
```

---

### Task 5: Verify Layout and Run Automated Checks

**Files:**
- Test: manual visual verification or fuzz checks if any exist.

- [ ] **Step 1: Check with linting / syntactical verification**

Run PHP syntactical check:
`rtk php -l index.php`
Expected: No syntax errors in index.php

- [ ] **Step 2: Commit**

No commit needed unless fixes made.
