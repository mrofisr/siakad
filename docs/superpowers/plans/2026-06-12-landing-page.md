# Implementation Plan: Landing Page Feature

**Date:** 2026-06-12  
**Status:** Approved

---

## 1. Database Migration

**File:** `database/migrate.php`

**Tasks:**
- Add `settings` table creation in `migrate()` function
- Seed default settings values
- Handle idempotent execution (CHECK IF NOT EXISTS pattern)

**Implementation:**
```php
// In migrate.php
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

// Seed defaults:
// school_name, accent_color, hero_title, hero_subtitle, 
// card_1_title/desc/icon, card_2_title/desc/icon, card_3_title/desc/icon,
// footer_address, footer_email, footer_phone, footer_copyright
```

---

## 2. Settings Utility Functions

**File:** `includes/settings.php` (new file)

**Functions:**
- `get_setting($key)` — retrieve single setting value
- `get_all_settings()` — retrieve all settings as associative array
- `update_setting($key, $value)` — update or insert setting

**Notes:**
- Use existing database connection pattern
- Return defaults if setting not found (optional)

---

## 3. Asset Directory Structure

**Directories to create:**
- `assets/css/`
- `assets/icons/`
- `assets/defaults/`
- `uploads/`

**Commands:**
```bash
mkdir -p assets/css assets/icons assets/defaults uploads
chmod 755 assets uploads
chmod 644 assets/*
```

---

## 4. Landing Page Stylesheet

**File:** `assets/css/landing.css`

**Requirements:**
- Standalone CSS (no dependency on Pico.css)
- Mobile-first responsive design
- CSS custom property for accent color: `--accent-color`
- Sections: header, hero, cards, footer
- Card styling with icon area, title, description
- Hero overlay with tint effect

**Responsive breakpoints:**
- Mobile: 320px–767px (stacked cards)
- Tablet: 768px–1023px (horizontal cards)
- Desktop: 1024px+ (full width)

---

## 5. SVG Icon Set

**Files:** `assets/icons/`

**Required icons (13 total):**
1. `krs.svg`
2. `nilai.svg`
3. `jadwal.svg`
4. `calendar.svg`
5. `book.svg`
6. `graduation.svg`
7. `clipboard.svg`
8. `users.svg`
9. `chart.svg`
10. `clock.svg`
11. `building.svg`
12. `certificate.svg`
13. `logo-placeholder.svg` (fallback)

**Requirements:**
- Consistent 24x24px viewBox
- Stroke-based design (1.5px stroke, no fill)
- Matching color palette
- Plain SVG (no external dependencies)

---

## 6. Upload & Settings Handlers

**File:** `handlers/settings_handler.php` (new file)

**Functions:**
- `handle_file_upload($file, $type)` — validate and save uploaded files
  - Types: `logo`, `hero`
  - Max size: 2MB
  - Valid MIMEs: `image/png`, `image/jpeg`, `image/svg+xml`
- `save_settings($_POST)` — update settings from form data
- CSRF validation (use existing token pattern)
- Redirect on success with flash message

---

## 7. Settings Page Renderer

**File:** `templates/settings.php` (new file)

**Features:**
- Admin-only page (`?page=settings`)
- Form with all settings fields:
  - Text inputs (school_name, titles, descriptions, footer info)
  - Color picker (accent_color)
  - Dropdown for icon selection (card icons)
  - File upload inputs (logo, hero)
  - Preview sections
- Submit button with CSRF token
- Success/error messages

**Menu integration:**
- Add "Pengaturan Landing Page" to admin navigation

---

## 8. Landing Page Renderer

**File:** `templates/landing.php` (new file)

**Structure:**
```php
<?php include 'templates/header.php'; ?>
<style>:root { --accent-color: <?= $accent_color; ?>; }</style>
<link rel="stylesheet" href="assets/css/landing.css">
<body>
  <header>...</header>
  <main>
    <section class="hero">...</section>
    <section class="cards">...</section>
  </main>
  <footer>...</footer>
</body>
```

**Dynamic content:**
- All text from settings table
- Images from `uploads/` with fallback to `assets/defaults/`
- Icon rendering: `assets/icons/{icon_key}.svg`

**Security:**
- `htmlspecialchars()` for all text output
- Filtered icon keys (whitelist)

---

## 9. Routing Changes

**File:** `index.php`

**Changes:**
- Unauthenticated users at `/` → serve landing page
- Authenticated users at `/` → redirect to role dashboard
- Add `?page=landing` route (public)
- Add `?page=settings` route (admin only)
- Add settings save handler endpoint

**Pseudocode:**
```php
if (!authenticated()) {
    if ($page === 'settings') redirect to login
    if ($page === 'landing' || $page === '') show_landing_page()
} else {
    if ($page === '') redirect_to_dashboard()
    if ($page === 'settings') show_admin_settings()
}
```

---

## 10. .gitignore Update

**File:** `.gitignore`

**Add:**
```
uploads/
!uploads/.gitkeep
```

**Purpose:** Ignore all uploads, allow .gitkeep for directory tracking

---

## Implementation Sequence

1. **Database migration** → run migration, verify settings table
2. **Settings utilities** → test functions in isolation
3. **Asset directories** → create folders, add default assets
4. **CSS stylesheet** → implement responsive layout
5. **SVG icons** → create 13 icons with consistent design
6. **Upload handler** → implement validation and saving
7. **Settings page** → build admin panel
8. **Landing page** → build public page with dynamic rendering
9. **Routing** → integrate all routes
10. **Testing** → verify fallbacks, uploads, theming
11. **.gitignore** → finalize repository configuration

---

## Verification Checklist

- [ ] Settings table created and seeded
- [ ] All utility functions tested
- [ ] Landing page renders with defaults
- [ ] Admin settings page accessible (admin only)
- [ ] File uploads work (logo, hero)
- [ ] Settings update persists
- [ ] Accent color applies correctly
- [ ] Fallback images used when no uploads
- [ ] Responsive design on mobile/tablet/desktop
- [ ] CSRF protection active
- [ ] XSS protection (`htmlspecialchars`)
- [ ] `.gitignore` excludes `uploads/`

---

## Notes

- Follow existing code patterns (database, templates, handlers)
- Use existing CSRF token pattern
- Maintain consistent file naming convention
- Keep CSS standalone (no Pico.css dependency)
- Test with fresh install (zero configuration)
- Ensure idempotent migrations
