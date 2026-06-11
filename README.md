# SIAKAD - Sistem Informasi Akademik

Single-file PHP academic information system with SQLite database.

## Requirements

- PHP 8.3 or higher
- SQLite extension (included in standard PHP installations)
- Web browser

## Quick Start

1. Navigate to the project directory:
```bash
cd /home/ubuntu/Documents/projects/siakad
```

2. Start the PHP development server:
```bash
php -S localhost:8000 index.php
```

3. Open your browser and visit: **http://localhost:8000**

4. Login with default credentials:
   - **Username:** `admin`
   - **Password:** `admin123`

## Features

### Admin Dashboard
- **Program Studi (Prodi)** - Manage study programs (D3, D4, S1, S2)
- **Mahasiswa** - Student management (NIM, personal data, program assignment)
- **Dosen** - Lecturer management (NIDN, personal data, program assignment)
- **Mata Kuliah** - Course catalog (course codes, credits, semester placement)
- **Tahun Akademik** - Academic year/semester management with active period toggle
- **Kelas** - Class offerings with lecturer assignment and schedules
- **KRS (Kartu Rencana Studi)** - View all course registrations
- **Nilai** - Grade input and management
- **Presensi** - Attendance tracking
- **KHS (Kartu Hasil Studi)** - View student transcripts

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

## Tech Stack

- **Backend:** PHP 8.3 (built-in functions only, no frameworks)
- **Database:** SQLite with PDO
- **Frontend:** Server-rendered HTML with Pico.css v2 (via CDN)
- **Authentication:** PHP sessions with password_hash/password_verify
- **Security:** CSRF tokens, prepared statements, input sanitization

## Database Schema

The system auto-creates 11 tables on first run:
- `users` - Authentication (admin, dosen, mahasiswa roles)
- `prodi` - Study programs
- `mahasiswa` - Students
- `dosen` - Lecturers
- `mata_kuliah` - Course catalog
- `tahun_akademik` - Academic periods
- `kelas` - Class offerings
- `jadwal` - Class schedules (multiple slots per class)
- `krs` - Course registrations
- `nilai` - Grades (auto-computed letter grades from numeric scores)
- `presensi` - Attendance records

## Grading System

| Score | Grade | Grade Points |
|-------|-------|--------------|
| 80-100 | A | 4.0 |
| 70-79 | B | 3.0 |
| 60-69 | C | 2.0 |
| 50-59 | D | 1.0 |
| 0-49 | E | 0.0 |

GPA (IPK) is calculated as: (Σ Grade Points × Credits) / Σ Credits

## Project Structure

```
siakad/
├── index.php       # Complete application (982 lines)
├── siakad.db       # SQLite database (auto-created)
├── .gitignore      # Git exclusions
└── README.md       # This file
```

## Security Notes

- Default admin password (`admin123`) should be changed in production
- The system uses PHP sessions for authentication
- All database queries use prepared statements
- CSRF tokens protect form submissions
- User input is sanitized via htmlspecialchars

## Development

This is a single-file application built for educational purposes and small-scale deployments. For production use, consider:
- Changing default credentials
- Using HTTPS
- Adding backup mechanisms for the SQLite database
- Implementing password reset functionality
- Adding user account creation workflows

## License

Built as a demonstration project. Feel free to use and modify as needed.
