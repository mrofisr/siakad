# Sidebar Navigation Revamp — Design Spec

**Date:** 2026-06-12  
**Status:** Approved

---

## Goal

Move the current top horizontal navbar menu links into a collapsible left sidebar. The top bar is retained as a slim strip for the hamburger toggle and user/notification info.

---

## Layout Structure

### Expanded (default on desktop)

```
+------------------+--------------------------------+
|   SIDEBAR        |   TOP BAR                      |
|   240px wide     |   (hamburger + notif badge)    |
|                  +--------------------------------+
|   [S] SIAKAD     |                                |
|   ----------     |   <main content>               |
|   Dashboard      |                                |
|   Prodi          |                                |
|   Mahasiswa      |                                |
|   ...            |                                |
|                  |                                |
|   [user] logout  |                                |
+------------------+--------------------------------+
```

### Collapsed

```
+---+--------------------------------------------+
| ☰ |   TOP BAR (notif badge + user badge)       |
+---+--------------------------------------------+
|   |                                            |
|   |   <main content — full width>              |
|   |                                            |
+---+--------------------------------------------+
```

Sidebar slides off-screen via `transform: translateX(-240px)`. Main content expands to full width. Hamburger button remains visible at top-left.

---

## Components

### `<aside class="site-sidebar">`
- Fixed position, full viewport height, `var(--sidebar-width)` wide (240px)
- Top section: brand mark `[S]` + `SIAKAD` text
- Middle section: role-based nav links — each with an SVG icon + label text
- Bottom section: user badge (role) + logout link
- Smooth CSS transition on `transform`

### `<header class="site-topbar">`
- Slim bar spanning only the content area (not over the sidebar)
- Left: hamburger toggle `<button id="sidebar-toggle">`
- Right: notification badge `#notif-badge`
- Replaces current `<nav class="site-nav">`

### `<main class="site-main">`
- `margin-left: var(--sidebar-width)` when expanded
- `margin-left: 0` when `.sidebar-collapsed` is on `<body>`
- CSS transition matches sidebar animation duration

### Toggle mechanism
- Hamburger button click → JS toggles `.sidebar-collapsed` on `<body>`
- State written to `localStorage` key `sidebar_collapsed`
- Inline `<script>` in `<head>` reads `localStorage` and applies class before first paint (eliminates flash)
- Click handler lives in `assets/js/main.js`

---

## Nav Links per Role

| Role | Links |
|---|---|
| Admin | Dashboard, Prodi, Mahasiswa, Dosen, Mata Kuliah, T.A., Kelas, KRS, Nilai, Presensi, KHS, Broadcast, Pengaturan |
| Dosen | Dashboard, Presensi, Nilai |
| Mahasiswa | Dashboard, KRS, KHS |

Each link uses an existing SVG icon from `assets/icons/`. Active link gets `.nav-active` class (same logic as current: `$_GET['page'] === $page`).

---

## CSS Changes (`assets/css/style.css`)

- Add CSS custom property `--sidebar-width: 240px`
- Remove all `.site-nav`, `.nav-inner`, `.nav-brand`, `.nav-links`, `.nav-user`, `.nav-logout`, `.nav-active` styles
- Add:
  - `.site-sidebar` — fixed, full-height, width, background, border-right, transition
  - `.site-sidebar .sidebar-brand` — brand logo + text at top
  - `.site-sidebar .sidebar-nav` — flex column of links
  - `.site-sidebar .sidebar-nav a` — link styles with icon + label
  - `.site-sidebar .sidebar-nav a.nav-active` — active state
  - `.site-sidebar .sidebar-footer` — user badge + logout at bottom
  - `.site-topbar` — sticky top bar for content area
  - `.site-main` — margin-left, transition
  - `body.sidebar-collapsed .site-sidebar` — transform off-screen
  - `body.sidebar-collapsed .site-main` — margin-left: 0
  - `body.sidebar-collapsed .site-topbar` — margin-left: 0
  - `@media (max-width: 768px)` — sidebar collapsed by default on mobile

---

## PHP Changes (`index.php`)

All changes are confined to the `layout()` function (lines 463–541):

1. Add inline `<script>` in `<head>` to restore sidebar state from localStorage before render
2. Replace `<nav class="site-nav">...</nav>` with:
   - `<aside class="site-sidebar">` containing brand, role-based nav links with icons, and user/logout footer
   - `<header class="site-topbar">` containing hamburger button and notification badge
3. Update `<main>` tag to use class `site-main`
4. No changes to routing, handlers, DB, or any other part of the file

---

## JS Changes (`assets/js/main.js`)

- Add click handler for `#sidebar-toggle` button
- Toggles `.sidebar-collapsed` on `document.body`
- Reads/writes `localStorage.getItem('sidebar_collapsed')`

---

## Out of Scope

- Landing page nav (separate layout, not touched)
- Any routing or handler changes
- Icon changes (use existing `assets/icons/` SVGs)
- Theme/color token changes
