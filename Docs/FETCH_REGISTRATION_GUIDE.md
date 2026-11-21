# Fetch Registration from Google Sheets

## Overview

The **Fetch Registration** feature allows you to automatically import registration data from Google Sheets (typically from a Google Form) into the EMS database. It provides flexible import options with field mapping, duplicate detection, and multiple data fetching methods.

**URL:** `http://localhost:8000/admin/fetch_registration.php`

**Menu:** Pre → Fetch Registration

---

## Features

### 🔗 Step 1: Google Sheet Configuration
- Input and save Google Sheet URL
- Automatic extraction of sheet ID
- Support for multiple sheets (via gid parameter)
- Public sheet verification

### 🗂️ Step 2: Field Mapping
- Map registration table columns to Google Sheet columns
- Visual table showing all registration fields
- Preview first 5 rows from Google Sheet
- Set default values for unmapped fields
- Flexible mapping (skip fields if not needed)

### ⚙️ Step 3: Fetch Methods Configuration
- **Manual Fetch:** Click button to import data on demand
- **Automatic Fetch:** Import data at specified intervals (every N minutes)
- **Real-time Fetch:** Automatic import via webhook when new form entries arrive
- Enable/disable each method independently

### 📥 Step 4: Data Import
- Manual fetch button with confirmation dialog
- Duplicate detection (checks email, phone)
- Only new/unique registrations saved
- Success metrics (imported, duplicates, failed)

---

## How It Works

### Data Flow

```
Google Form
    ↓
Google Sheet
    ↓
CSV Export API
    ↓
PHP Script (fetch_registration.php)
    ↓
Field Mapping (columns matched)
    ↓
Duplicate Check (email/phone)
    ↓
Insert into registrations table
```

### Step-by-Step Process

1. **Configure Google Sheet URL**
   - Paste the Google Sheets link
   - Script extracts spreadsheet ID
   - Validates sheet accessibility

2. **Map Fields**
   - Select which Google Sheet column maps to which database field
   - Specify default values for unmatched fields
   - Preview data to verify mapping

3. **Configure Fetch Methods**
   - Enable/disable manual fetch
   - Set automatic fetch interval
   - Enable webhook for real-time updates

4. **Fetch Data**
   - Click "Fetch Data Now" button
   - Script fetches from Google Sheet
   - Checks for duplicates
   - Inserts new registrations
   - Shows import results

---

## Setup Guide

### 1. Create a Google Form

Create a form with fields matching your registration requirements:
- Name
- Email
- Phone
- Course/Category
- Etc.

**Share Settings:**
- Make sure form responses go to a Google Sheet
- Make the sheet publicly accessible OR share with appropriate users

### 2. Get the Sheet URL

The form creates a response sheet automatically. Get the URL from:
1. Open Google Form
2. Click "Responses" tab
3. Click green sheet icon
4. Copy the sheet URL

Example URL:
```
https://docs.google.com/spreadsheets/d/1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p/edit
```

### 3. Configure in EMS

1. Go to **Pre → Fetch Registration**
2. Select your event
3. Paste the sheet URL in Step 1
4. Click "Save Sheet URL"
5. System fetches column headers automatically

### 4. Map Fields

In Step 2, you'll see:
- **Left column:** Your registration table fields (name, email, phone, etc.)
- **Middle column:** Dropdown to select corresponding Google Sheet columns
- **Right column:** Default values (optional)

Example mapping:
```
name              → Name (from sheet)
email             → Email Address (from sheet)
phone             → Phone Number (from sheet)
registration_date → (auto-set to today)
```

### 5. Configure Fetch Methods

Choose how data will be imported:

**Option A: Manual**
- Enable "Manual Fetch"
- Click "Fetch Data Now" whenever ready
- Best for testing or small batches

**Option B: Automatic**
- Enable "Automatic Fetch"
- Set interval (e.g., every 30 minutes)
- System automatically fetches at that interval
- Requires cron job or scheduler

**Option C: Real-time**
- Enable "Real-time Fetch"
- Copy the webhook URL
- Add webhook to Google Form notification settings
- Data imported immediately when form submitted

### 6. Test Import

1. Submit a test entry to your Google Form
2. Go to Fetch Registration → Fetch Data Now
3. Check if data appeared in registrations table
4. Verify all fields mapped correctly

---

## Configuration Details

### Manual Fetch

```
When to use: Testing, small batches, ad-hoc imports
Steps:
1. Enable "Manual Fetch" toggle
2. Click "Fetch Data Now" button
3. Confirm the action
4. Results show: Success count, duplicates skipped, failed count
```

### Automatic Fetch

1. Configure the sheet URL and field mapping, enable **Automatic Fetch**, and
   set the interval (minimum 5 minutes). Save the fetch methods.
2. Schedule `scripts/automatic_registration_fetch.php` to run every minute.
   The worker checks all enabled events and observes each event's interval;
   the first run after enabling an event is eligible immediately. Failed
   attempts are retried at the configured interval.
3. Check the last attempt and any error on the Fetch Registration page. A
   successful run records its time and skips registrations already imported.
   To prevent repeated duplicates, automatic imports skip and report rows
   without a non-empty mapped email or mobile number (or its configured
   default). Manual and webhook imports retain their existing behavior.

On this WAMP installation, create a Windows Task Scheduler task:

- Trigger: daily, repeat every 1 minute for an indefinite duration.
- Action: start program `C:\wamp64\bin\php\php8.4.15\php.exe`
- Add arguments: `C:\wamp64\www\ems\scripts\automatic_registration_fetch.php`
- Start in: `C:\wamp64\www\ems`

Use the PHP executable and application path for your installation, and an
account with access to the database configuration and network. WAMP/MySQL and
the machine must be running. Test the task with **Run** and check its result
and the page's last-attempt status. The worker is CLI-only; it is not an HTTP
endpoint.

On Linux, an equivalent crontab entry is:

```cron
* * * * /usr/bin/php /path/to/ems/scripts/automatic_registration_fetch.php >> /path/to/ems-fetch.log 2>&1
```

Protect the log file and use the PHP executable and application path for the
server. Overlapping imports for the same event are prevented; a manual or
webhook request must be retried if another import is running.

### Real-time Fetch (Webhook)

```
When to use: Immediate import as users submit form
Steps:
1. Enable "Real-time Fetch" toggle
2. Copy the webhook URL displayed
3. In Google Form settings:
   → Responses → More options → Notify me
   → Custom email notifications
   → Use the webhook URL
   OR for better integration, use Zapier/Make:
   → Google Form submission → Webhook to EMS
4. Whenever form is submitted, data imported instantly
```

---

## Field Mapping Reference

### Registration Table Fields

Typical fields available for mapping:

| Field | Type | Description |
|-------|------|-------------|
| name | varchar | Participant's full name |
| email | varchar | Email address (unique) |
| phone | varchar | Phone number |
| course | varchar | Course/Program selected |
| category | varchar | Participant category |
| gender | varchar | Male/Female/Other |
| address | text | Street address |
| city | varchar | City |
| state | varchar | State/Province |
| country | varchar | Country |
| date_of_birth | date | DOB |
| college | varchar | College/Institute |
| roll_number | varchar | Registration number |
| group_name | varchar | Group identifier |
| notes | text | Additional notes |

### Mapping Rules

1. **Required Fields:** Typically email or phone for duplicate detection
2. **Optional Fields:** Can be left unmapped or use default values
3. **Date Fields:** Use ISO format (YYYY-MM-DD) from sheet
4. **Default Values:** Used if sheet column not mapped or empty

---

## Duplicate Detection

The system checks for duplicates using:

1. **Primary Check:** Email address
   - If email exists in registrations table, skip entry
   - Most reliable for form submissions

2. **Fallback Check:** Phone number
   - If phone exists in registrations table, skip entry
   - Used when email not available

3. **Result:** 
   - Duplicates are counted but not re-imported
   - No data is overwritten or updated

---

## Error Handling

| Error | Cause | Solution |
|-------|-------|----------|
| "Google Sheet URL is required" | No URL provided | Enter sheet URL and save |
| "Failed to fetch data from Google Sheet" | URL invalid or sheet not accessible | Verify URL, check sheet is public |
| "No field mapping configured" | No fields mapped | Map at least one field in Step 2 |
| Webhooks not working | Real-time not enabled | Enable real-time fetch toggle |
| Some data not importing | Field type mismatch | Check column types match database |

---

## Best Practices

### ✅ DO:
- Test with small batch first
- Verify field mapping with preview data
- Make sheet publicly accessible
- Use consistent naming in Google Form
- Enable duplicate detection
- Monitor import results
- Keep Google Form and EMS synchronized

### ❌ DON'T:
- Share webhook URL publicly
- Map wrong columns
- Delete sheet while importing
- Manually edit sheet during auto-import
- Enable all three fetch methods simultaneously
- Map multiple sheet columns to one database field

---

## Webhook Setup (Google Form)

### Step 1: Enable Real-time Fetch
Go to Fetch Registration page, enable "Real-time Fetch" toggle.
Copy the webhook URL shown:
```
http://localhost:8000/admin/google_sheets_webhook.php?event_id=1
```

### Step 2: Configure Webhook

**Option A: Using Zapier (Recommended)**
1. Create Zapier account
2. Create Zap: Google Forms → Webhooks
3. In Zapier:
   - Trigger: New form response
   - Action: POST to webhook URL
4. Test connection
5. Turn on Zap

**Option B: Using Make.com**
1. Create Make.com account
2. Create scenario: Google Forms → Webhooks
3. Map form fields to POST data
4. Paste webhook URL
5. Test and activate

**Option C: Manual via Google Script (Advanced)**
Create Apps Script trigger on form submit:
```javascript
function onSubmit(e) {
  UrlFetchApp.fetch('YOUR_WEBHOOK_URL', {
    method: 'post',
    payload: e.values
  });
}
```

---

## CSV Format Support

The system supports CSV export from Google Sheets with:
- UTF-8 encoding
- Comma or semicolon delimiters (auto-detected)
- Headers in first row (required)
- Empty cells handled as empty strings
- Quoted fields with commas supported

---

## Database Schema

### registrations Table (Required Columns)

```sql
CREATE TABLE IF NOT EXISTS registrations (
    registration_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(255),
    email VARCHAR(191) UNIQUE,
    phone VARCHAR(20),
    course VARCHAR(100),
    category VARCHAR(100),
    gender VARCHAR(50),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    country VARCHAR(100),
    date_of_birth DATE,
    college VARCHAR(255),
    roll_number VARCHAR(100),
    group_name VARCHAR(100),
    notes TEXT,
    registration_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    FOREIGN KEY (event_id) REFERENCES events(event_id)
);
```

### settings Table (Configuration Storage)

```sql
CREATE TABLE IF NOT EXISTS settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
);
```

---

## Troubleshooting

### Issue: "Invalid Google Sheet URL"
**Solutions:**
- Verify URL format (should contain `/spreadsheets/d/`)
- Check sheet ID is correct
- Ensure sheet is public

### Issue: No data imported
**Solutions:**
- Verify field mapping (Step 2)
- Check if entries are duplicates
- Confirm sheet has data in first row as headers
- Check for email/phone duplicates

### Issue: Webhook not working
**Solutions:**
- Verify webhook URL is correct
- Check firewall allows external requests
- Test with manual fetch first
- Check server logs for errors
- Use Zapier/Make for reliable delivery

### Issue: Some fields not importing
**Solutions:**
- Check data types match (date, number, text)
- Verify decimal places/format
- Use default values for missing data
- Map fields correctly in Step 2

---

## Performance Considerations

- **Data Size:** Supports 1000+ registrations per import
- **Sheet Size:** No practical limit
- **Frequency:** Can run every 5 minutes (300+ times/day)
- **Database Impact:** Minimal (duplicate check indexed)
- **Network:** Requires internet access to Google

---

## Security

✅ **Implemented:**
- Public sheet requirement (controlled access)
- Webhook URL includes event ID
- Duplicate prevention prevents data corruption
- CSV parsing is sanitized
- Database queries use prepared statements

⚠️ **Recommendations:**
- Keep webhook URL secret
- Use HTTPS for webhook
- Validate Google Sheet ownership
- Monitor import logs
- Backup database regularly

---

## API Reference

### Functions in google_sheets_funcs.php

```php
// URL Management
saveGoogleSheetURL($pdo, $sheetURL, $eventId)
getGoogleSheetURL($pdo, $eventId)
convertToCSVExportURL($sheetURL)

// Data Fetching
fetchGoogleSheetData($csvExportURL)
fetchAndProcessRegistrations($pdo, $eventId, $csvURL)

// Field Mapping
saveFieldMapping($pdo, $eventId, $fieldMapping)
getFieldMapping($pdo, $eventId)

// Fetch Methods
saveFetchMethod($pdo, $eventId, $method, $enabled, $intervalMinutes)
getFetchMethod($pdo, $eventId, $method)

// Duplicate Detection
isDuplicateRegistration($pdo, $eventId, $rowData, $fieldMapping)

// Data Insertion
insertRegistrationFromMapping($pdo, $eventId, $rowData, $fieldMapping)
getRegistrationTableFields($pdo)
```

---

## Files

- `admin/fetch_registration.php` - Main interface
- `admin/google_sheets_funcs.php` - Core functions
- `admin/google_sheets_webhook.php` - Webhook handler
- `admin/layout.php` - Menu integration

---

## Support & Examples

### Example 1: School Event Registration

**Google Form fields:**
- Full Name
- Email
- Phone
- Class/Grade
- School Name

**Mapping:**
- name → Full Name
- email → Email
- phone → Phone
- category → Class/Grade
- college → School Name

### Example 2: Conference Registration

**Google Form fields:**
- Name
- Email
- Company
- Designation
- Mobile
- Dietary Preference

**Mapping:**
- name → Name
- email → Email
- course → Designation
- notes → Dietary Preference
- phone → Mobile
- college → Company

---

**Status: ✅ Complete and Ready to Use**
