# Landing Page Design Spec

**Date:** 2026-06-12
**Status:** Approved

## Purpose

Add a public-facing, school-branded landing page to SIAKAD. Each institution deploying SIAKAD customizes the landing page with their own logo, images, colors, and text — managed via file-based defaults and an admin panel.

## Audience

Students, lecturers, and staff of the institution. This is NOT a product marketing page — it's a branded front door to the academic system.

## Sections (Fixed Structure)

### 1. Header
- School logo (image)
- School name + "Sistem Informasi Akademik" subtitle
- Login button (accent-colored)

### 2. Hero
- Full-width background image with color overlay
- Title text (e.g., "Selamat Datang di Portal Akademik")
- Subtitle text (e.g., "Universitas X — Portal akademik untuk mahasiswa dan dosen")

### 3. Quick Info Cards (3 fixed cards)
- Each card: icon (from predefined SVG set) + title + description
- Defaults: KRS Online, Nilai & Transkrip, Jadwal Kuliah
- Admin can change title, description, and icon selection

### 4. Footer
- School logo + name
- Address (street, city)
- Contact info (email, phone)
- Copyright text

## Customization System

### Storage Strategy
- **Config data** (text, colors, icon selections): Key-value `settings` table in SQLite
- **Images** (logo, hero): Filesystem in `uploads/` directory
- **Resolution order**: Check `uploads/` first → fall back to `assets/defaults/`

### Settings Table Schema
```sql
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
```

### Setting Keys
| Key | Type | Default Value |
|-----|------|---------------|
| `school_name` | text | "Sistem Informasi Akademik" |
| `accent_color` | hex | "#2c7a4b" |
| `hero_title` | text | "Selamat Datang di Portal Akademik" |
| `hero_subtitle` | text | "Portal akademik untuk mahasiswa dan dosen" |
| `card_1_title` | text | "KRS Online" |
| `card_1_desc` | text | "Pengisian Kartu Rencana Studi secara online" |
| `card_1_icon` | icon key | "krs" |
| `card_2_title` | text | "Nilai & Transkrip" |
| `card_2_desc` | text | "Lihat nilai dan transkrip akademik" |
| `card_2_icon` | icon key | "nilai" |
| `card_3_title` | text | "Jadwal Kuliah" |
| `card_3_desc` | text | "Akses jadwal perkuliahan mingguan" |
| `card_3_icon` | icon key | "jadwal" |
| `footer_address` | text | "Jl. Pendidikan No. 123, Kota, Indonesia" |
| `footer_email` | text | "info@example.ac.id" |
| `footer_phone` | text | "(000) 000-0000" |
| `footer_copyright` | text | "© 2026 {school_name}. SIAKAD." (dynamically interpolates `school_name` setting at render) |

### Admin Panel
- New page at `?page=settings` (admin role only)
- Added as menu item in admin nav: "Pengaturan Landing Page"
- Form fields: text inputs, color picker, icon dropdown, file upload for logo/hero
- Upload handling: validate file type (png/jpg/svg for logo, png/jpg for hero), max size limit, save to `uploads/`

## Routing Changes

| URL | Behavior |
|-----|----------|
| `/` (unauthenticated) | Show landing page |
| `/` (authenticated) | Redirect to role-based dashboard |
| `?page=login` | Existing login page (unchanged) |
| `?page=settings` | Admin settings page (new) |

## File Structure

```
siakad/
├── index.php                # modified: add landing route, settings page, settings handlers
├── assets/
│   ├── css/
│   │   └── landing.css      # standalone landing page stylesheet
│   ├── icons/               # predefined SVG icon set (10-15 icons)
│   │   ├── krs.svg
│   │   ├── nilai.svg
│   │   ├── jadwal.svg
│   │   ├── calendar.svg
│   │   ├── book.svg
│   │   ├── graduation.svg
│   │   ├── clipboard.svg
│   │   ├── users.svg
│   │   ├── chart.svg
│   │   ├── clock.svg
│   │   ├── building.svg
│   │   └── certificate.svg
│   └── defaults/
│       ├── logo.png         # generic academic logo (graduation cap)
│       └── hero.jpg         # neutral academic background image
├── uploads/                 # gitignored, Docker volume mount point
│   ├── logo.png             # admin-uploaded school logo
│   └── hero.jpg             # admin-uploaded hero image
```

## Styling

- **Landing page**: standalone `landing.css` — does NOT use Pico.css
- **Rest of app**: unchanged, continues using Pico.css
- **Accent color**: applied via CSS custom property (`--accent-color`) set inline from DB value
- **Responsive**: mobile-first, cards stack vertically on small screens

## Theming Approach

```html
<html style="--accent-color: #2c7a4b;">
```

The `landing.css` uses `var(--accent-color)` for:
- Login button background
- Card top borders
- Hero overlay tint
- Footer accent elements

## Fallback Behavior

Fresh install with zero configuration:
- Logo: `assets/defaults/logo.png` (generic graduation cap)
- Hero: `assets/defaults/hero.jpg` (neutral academic illustration)
- All text: default values from settings table (auto-seeded on first run)
- Accent color: `#2c7a4b` (green)

Result: a complete, professional-looking landing page out of the box.

## Security Considerations

- File uploads: validate MIME type, limit file size (2MB max), sanitize filename
- Settings form: CSRF token (existing pattern in the app)
- Image serving: serve from filesystem directly (Apache/PHP built-in server), no DB streaming
- Input sanitization: `htmlspecialchars()` all text output on landing page

## Out of Scope (Future)

- Variable number of cards
- Reorderable sections
- Announcements section
- Dark mode toggle on landing page
- Multiple image uploads (gallery)
- Custom CSS injection by admin
