# EMS (Event Management System) - Project Study

## Project Overview
**EMS** is a comprehensive Event Management System built with PHP 8.2, MySQL 8.0, and Apache, deployed via Docker Compose. It provides tools for managing events, registrations, attendee check-in, and feedback collection.

**Repository:** arjindersingh/EMS  
**Tech Stack:** PHP 8.2 | MySQL 8.0 | Apache | Docker Compose | HTML/CSS/JS  
**Deployment:** Docker (local) & Vercel (production)

---

## Project Structure

### Root Level Files
- **index.php** - Main entry point; renders home page with event listings
- **register.php** - Public event registration form
- **home.php** - Homepage content; displays open events and schedules
- **feedback.php** - Feedback submission page
- **public_layout.php** - Shared HTML layout for public pages
- **special_register.php** - Alternative registration flow (context unclear)

### Directory Structure

#### `/admin/`
Administrative backend for event management and attendee operations:
- **index.php** - Admin dashboard; login & authentication
- **auth_funcs.php** - Admin user authentication & management functions
- **events.php** - Event CRUD operations
- **events_funcs.php** - Event helper functions (no side effects)
- **registration.php** - Admin registration management
- **registration_admin_funcs.php** - Registration helper functions
- **registration_approvals.php** - Approve/deny registrations
- **registration_approval_funcs.php** - Approval workflow functions
- **registration_bulk_upload.php** - Bulk import registrations from file
- **registration_bulk_upload_funcs.php** - Bulk upload helpers
- **event_checkin.php** - Check-in interface for event attendees
- **event_checkin_funcs.php** - Check-in logic & attendance tracking
- **event_itinerary.php** - Event schedule/timeline management
- **event_itinerary_funcs.php** - Itinerary helper functions
- **event_schedule.php** - Event schedule management
- **event_schedule_funcs.php** - Schedule helpers
- **event_pass.php** - Digital pass generation (QR codes)
- **attendance_view.php** - View attendance records
- **attendance_reports.php** - Generate attendance reports
- **attendee_list.php** - List all event attendees
- **feedback.php** - Feedback management (collect from attendees)
- **feedback_analysis.php** - Analyze feedback data
- **feedback_funcs.php** - Feedback helper functions
- **broadcast_messages.php** - Send messages to event attendees
- **playback.php** - Event replay/recording playback
- **playback_funcs.php** - Playback helper functions
- **settings.php** - Admin settings management
- **settings_funcs.php** - Settings helper functions
- **admin_users.php** - Admin user management
- **layout.php** - Admin page layout template
- **ajax/** - AJAX endpoints for dynamic operations
  - **registration_approvals_ajax.php** - AJAX handlers for approvals

#### `/api/`
RESTful API for external integrations (token-based auth):
- **bootstrap.php** - API configuration & authentication middleware
- **login.php** - Admin login endpoint
- **logout.php** - Admin logout endpoint
- **events.php** - Event API endpoints
- **checkin.php** - Check-in API endpoints
- **registrations.php** - Registration API endpoints

#### `/config/`
- **database.php** - Database connection & configuration
  - Reads `.env` for DB credentials
  - Provides `createDbConnection()` function
  - URL building utilities (`buildUrl()`, `getAppBasePath()`)
  - Connection retry logic (10 attempts with 1s delays)

#### `/assets/`
Static resources:
- **css/** - Stylesheets (likely in public_layout.php)
- **js/** - JavaScript files
  - `jsQR.min.js` - QR code scanning library
- **images/**
  - `slider/` - Homepage slider images
  - `QR/` - Generated QR codes for registrations
- **audio/playback/** - Event audio recordings (ephemeral in production)

#### `/mobile/`
Mobile-specific UI/logic (contents unexplored, likely native/responsive app)

#### `/scripts/`
- **create_tables.php** - Database initialization script

#### `/public_layout.php`
Shared public-facing HTML structure and styling

---

## Database Schema

### Core Tables

#### `events`
Event definition and metadata
- **Columns:** event_id (PK), event_title, event_code (unique), event_tagline, event_theme, 
  event_type (enum: Academic|Cultural|Sports|Seminar|Workshop|Conference|Meeting|Celebration|Competition|Other),
  event_status (enum: Open|Suspended|Delayed|Postponed|Closed),
  event_description, start_date, end_date, start_time, end_time,
  venue_name, venue_address, city, state, country,
  registration_required, registration_start_date, registration_end_date, registration_fee,
  created_by, updated_by, created_at, updated_at

#### `event_registrations`
Attendee registrations for events
- **Columns:** registration_id (PK), event_id (FK), salutation, name, designation, teacher_post, role,
  photograph_name, photograph_path, institution_name, affiliation_number, institution_level, experience,
  mobile, whatsapp_number, official_email, institution_address, city, district, state, country,
  declaration_accepted, qr_code_path, pass_code, approval_status (enum: pending|approved|denied),
  reviewed_at, reviewed_by, created_at, updated_at

#### `event_schedules`
Event schedule/agenda items
- **Columns:** schedule_id (PK), event_id (FK), schedule_start_date, schedule_end_date, schedule_description

#### `event_itineraries`
Detailed event itinerary/timeline
- **Columns:** itinerary_id (PK), event_id (FK), itinerary_date, start_time, end_time, itinerary_description

#### `admin_users`
Admin user accounts for access control
- **Columns:** admin_user_id (PK), username (unique), email (unique), name, password_hash, created_at, updated_at

#### `api_tokens`
Bearer token authentication for API endpoints
- **Columns:** token_id (PK), admin_user_id (FK), token_hash (SHA256 unique), device_label, 
  created_at, last_used_at, revoked_at

#### `event_attendance`
Check-in records for attendee presence tracking
- **Columns:** attendance_id (PK), event_id (FK), registration_id (FK), checked_in_at, checked_in_by

#### `settings`
Application-wide configuration key-value pairs
- **Columns:** setting_id (PK), setting_key, setting_value, updated_at

#### `registration_approvals`
Tracks approval workflow state for registrations
- **Columns:** approval_id (PK), registration_id (FK), approver_name, approval_date

#### `announcements` (checkin-related)
In-event announcements/notifications during check-in

---

## Key Features & Workflows

### 1. **Event Management**
- Create, edit, delete events
- Set event status (Open, Suspended, Delayed, Postponed, Closed)
- Define registration window & fees
- Manage event venue, dates, times
- Add event description, tagline, theme

### 2. **Registration System**
- Public event registration form
- Approval workflow (pending → approved/denied)
- Bulk upload registrations (CSV/Excel)
- QR code generation per registration
- Digital pass (pass_code + QR)
- Photo upload support

### 3. **Check-in Management**
- Scan QR codes for quick check-in
- Track attendance with timestamps
- View real-time attendance data
- Generate attendance reports

### 4. **Event Itineraries & Schedules**
- Define event agenda/timeline
- Add session details with times
- Display on public home page

### 5. **Feedback System**
- Collect attendee feedback
- Analyze feedback data
- Generate feedback reports

### 6. **Admin Panel**
- Dashboard with event overview
- User authentication (username/email login)
- Admin user management
- Settings configuration

### 7. **API Layer**
- Token-based Bearer authentication
- Endpoints for events, registrations, check-in
- JSON request/response format

---

## Environment Configuration

### `.env` Variables
```
DB_HOST=mysql              # Database host
DB_PORT=3306              # Database port
DB_DATABASE=ems_db        # Database name
DB_USERNAME=ems_user      # Database user
DB_PASSWORD=ems_password  # Database password
APP_BASE_URL=https://...  # Application URL
ADMIN_NAME=Administrator  # Default admin name
ADMIN_EMAIL=admin@...     # Default admin email
ADMIN_USERNAME=admin      # Default admin username
ADMIN_PASSWORD=...        # Default admin password
```

### Docker Compose Services
- **app** - PHP 8.2 Apache container (port 8000)
- **mysql** - MySQL 8.0 database (port 3306)
- Automatic database initialization on first run

---

## Deployment Information

### Local Development
```bash
docker compose up --build
# Application available at http://localhost:8000
```

### Vercel Production
- Uses `Dockerfile.vercel` for container-based Functions
- Requires external MySQL database (not local)
- Environment variables set in Vercel project settings
- Assets stored in `assets/` directory (ephemeral in production)
- QR codes, photos, and audio should use external object storage

**Key Limitation:** Vercel ephemeral filesystem means file uploads (photos, QR codes, audio) are not persistent across deployments. Production should implement cloud storage integration.

---

## Code Patterns & Conventions

### Database Functions
- All database operations use **PDO** with prepared statements
- Error handling via exception handling
- Table creation functions follow `ensureXxxTable(PDO $pdo)` pattern
- No side effects in `*_funcs.php` files

### Authentication
- Admin authentication: session-based (password hash verification)
- API authentication: Bearer token in Authorization header
- Token storage: SHA256 hash (actual token sent once to admin)

### URL Building
- `buildUrl($path)` function handles app base path correctly
- Accounts for subdirectory deployments
- Used consistently throughout for routing

### HTML Output
- All user-provided data escaped with `htmlspecialchars()`
- Consistent XSS prevention across forms and views

### Error Handling
- Database connection failures handled gracefully
- User feedback via alert divs (error/success classes)
- JSON error responses in API endpoints

---

## Current Issues & TODOs
From git status:
- Uncommitted changes in `admin/event_checkin.php` and `admin/event_pass.php`
- Untracked JS files in `assets/js/`
- QR image files added
- Audio file naming inconsistencies (`.mp3` → `.mp3.mp3`)

---

## Technology Highlights

### Security Considerations
1. **Password Security:** Uses PHP's `password_verify()` for authentication
2. **CSRF Protection:** Missing (consider adding token-based CSRF protection)
3. **SQL Injection:** Prepared statements used consistently
4. **XSS Prevention:** Output escaping implemented
5. **API Auth:** Token-based with hash storage

### Performance Notes
1. Database retry logic (10 attempts) ensures resilience
2. Indexed foreign keys on `event_id` for query optimization
3. File uploads stored locally (needs cloud storage for production)

### Maintenance Notes
1. Schema migrations are implicit (functions check & add columns)
2. No formal version control for database changes
3. Admin user created automatically on first admin access

---

## Development Ready
The project is well-structured with:
- ✅ Clear separation of concerns (admin, API, public)
- ✅ Consistent database patterns
- ✅ Docker-based local development setup
- ✅ Production deployment guide (Vercel)
- ✅ API layer for integrations
- ✅ Robust authentication system

**Ready for feature development, bug fixes, or performance improvements.**
