# SIAKAD - Sistem Informasi Akademik

Single-file PHP academic information system with SQLite database.

## Requirements

- PHP 8.3 or higher
- SQLite extension (included in standard PHP installations)
- Docker (optional, for containerized deployment)

## Quick Start

### Local Development

```bash
php -S localhost:8000 index.php
```

Open **http://localhost:8000** and login with:
- **Username:** `admin`
- **Password:** `admin123`

### Docker

```bash
docker compose up -d
```

App available at **http://localhost:8080**

## Features

### Admin Dashboard
- **Program Studi (Prodi)** -- Manage study programs (D3, D4, S1, S2)
- **Mahasiswa** -- Student management (NIM, personal data, program assignment)
- **Dosen** -- Lecturer management (NIDN, personal data, program assignment)
- **Mata Kuliah** -- Course catalog (course codes, credits, semester placement)
- **Tahun Akademik** -- Academic year/semester management with active period toggle
- **Kelas** -- Class offerings with lecturer assignment and schedules
- **KRS** -- View all course registrations
- **Nilai** -- Grade input and management
- **Presensi** -- Attendance tracking
- **KHS** -- View student transcripts
- **Broadcast** -- Send real-time notifications to all users or specific roles

### Dosen (Lecturer) Dashboard
- View assigned classes with enrollment counts
- View class schedules
- Input grades for enrolled students (A-E grading scale)
- Record daily attendance (hadir/sakit/izin/alpha)

### Mahasiswa (Student) Dashboard
- View personal information (NIM, program, cohort)
- Register for available classes (KRS)
- View weekly class schedule
- View semester transcripts (KHS) with GPA calculation

### Real-time Notifications (SSE)
- Server-Sent Events for push notifications without external dependencies
- Toast notifications with auto-dismiss (5 seconds)
- Unread count badge in navigation bar
- Notification types:
  - Nilai published (when dosen submits grades)
  - KRS enrollment confirmation
  - Presensi alpha alerts
  - Admin broadcast messages
- Auto-cleanup of notifications older than 30 days

## Tech Stack

- **Backend:** PHP 8.3 (built-in functions only, no frameworks)
- **Database:** SQLite with PDO (WAL mode)
- **Frontend:** Custom CSS (editorial minimalist design), vanilla JavaScript
- **Real-time:** Server-Sent Events (SSE) for push notifications
- **Authentication:** PHP sessions with password_hash/password_verify
- **Security:** CSRF tokens, prepared statements, input sanitization
- **Container:** Docker with non-root user, named volumes

## Project Structure

```
siakad/
├── index.php              # Application logic and routing
├── assets/
│   ├── css/
│   │   └── style.css      # Minimalist editorial stylesheet
│   └── js/
│       └── main.js        # Scroll reveal + SSE notification client
├── docker-compose.yml     # Container orchestration
├── Dockerfile             # Secure container build (non-root)
├── logs/                  # Application logs (gitignored)
├── siakad.db              # SQLite database (auto-created, gitignored)
├── .gitignore
└── README.md
```

## Database Schema

The system auto-creates 12 tables on first run:

| Table | Purpose |
|-------|---------|
| `users` | Authentication (admin, dosen, mahasiswa roles) |
| `prodi` | Study programs |
| `mahasiswa` | Students |
| `dosen` | Lecturers |
| `mata_kuliah` | Course catalog |
| `tahun_akademik` | Academic periods |
| `kelas` | Class offerings |
| `jadwal` | Class schedules (multiple slots per class) |
| `krs` | Course registrations |
| `nilai` | Grades |
| `presensi` | Attendance records |
| `notifications` | Real-time notification queue |

## Grading System

| Score | Grade | Points |
|-------|-------|--------|
| 80-100 | A | 4.0 |
| 70-79 | B | 3.0 |
| 60-69 | C | 2.0 |
| 50-59 | D | 1.0 |
| 0-49 | E | 0.0 |

GPA (IPK) = (Sum of Grade Points x Credits) / Sum of Credits

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?page=ping` | GET | Health probe (returns `pong`) |
| `?page=health` | GET | JSON health status with DB check |
| `?page=sse` | GET | SSE stream for real-time notifications |
| `?page=notif_count` | GET | JSON unread notification count |

## Security Notes

- Default admin password (`admin123`) should be changed in production
- Docker container runs as non-root `appuser` (UID 1000)
- All database queries use prepared statements
- CSRF tokens protect form submissions
- User input is sanitized via htmlspecialchars
- Sessions regenerated on login to prevent fixation

## License

Built as a demonstration project. Feel free to use and modify as needed.
