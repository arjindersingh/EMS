# Quick Start: Fetch Registration from Google Sheets

## 🎯 5-Minute Setup

### 1. Create Google Form
Create a form with fields you want to import (name, email, phone, etc.)
Form automatically links to a Google Sheet with responses.

### 2. Get Sheet URL
- Open the Google Form
- Click "Responses" tab
- Click the Google Sheets icon (📊)
- Copy the sheet URL

### 3. Add to EMS
**Navigate to:** Pre → Fetch Registration

1. Select your event
2. **Step 1:** Paste sheet URL, click "Save Sheet URL"
3. **Step 2:** Map columns (select which sheet column = which database field)
4. **Step 3:** Enable "Manual Fetch"
5. **Step 4:** Click "Fetch Data Now"

That's it! ✅

---

## 📋 Three Fetch Methods

### Option 1: Manual Fetch
```
✓ When: You manually click button
✓ Best for: Testing, small batches
✓ Setup: Enable toggle, click "Fetch Data Now"
✓ Frequency: On-demand
```

### Option 2: Automatic Fetch
```
✓ When: Every N minutes automatically
✓ Best for: Regular scheduled imports
✓ Setup: Enable toggle, set interval
✓ Frequency: Every 5, 15, 30, 60+ minutes
✓ Requires: Server scheduler/cron job
```

### Option 3: Real-time Fetch
```
✓ When: Data imported immediately after form submission
✓ Best for: Live events, immediate confirmation needed
✓ Setup: Enable toggle, use webhook URL
✓ Frequency: Instantaneous
✓ Requires: Zapier/Make or Google Apps Script
```

---

## 🗂️ Field Mapping Example

### Your Registration Table Has:
```
- name
- email
- phone
- course
- college
```

### Your Google Sheet Has (from Form):
```
Column A: Full Name
Column B: Email Address
Column C: Mobile Number
Column D: Course Name
Column E: Institution
```

### Mapping:
| Database Field | Google Sheet Column |
|---|---|
| name | Full Name |
| email | Email Address |
| phone | Mobile Number |
| course | Course Name |
| college | Institution |

**Result:** When you "Fetch Data", system matches and imports!

---

## ✅ Verification Checklist

- [ ] Google Form created with fields
- [ ] Form responses saved to Google Sheet
- [ ] Sheet URL copied correctly
- [ ] Sheet is public or shared with EMS
- [ ] Fields mapped correctly
- [ ] Test entry submitted to form
- [ ] "Fetch Data Now" clicked
- [ ] Entry appears in registration list
- [ ] Email/phone shows (for duplicate detection)

---

## 🚨 Common Issues

| Problem | Solution |
|---------|----------|
| "Failed to fetch from Google Sheet" | Make sheet public, check URL format |
| No data imported | Check field mapping, verify sheet has headers in row 1 |
| Duplicates showing | Same email/phone already in database (expected) |
| Webhook not working | Enable real-time fetch first, test with manual fetch |
| Some fields blank | Map those columns or set default values |

---

## 🔗 Webhook URL (for Real-time)

After enabling real-time fetch, use this URL in Zapier/Make:

```
http://localhost:8000/admin/google_sheets_webhook.php?event_id=YOUR_EVENT_ID
```

**How to use:**
1. Create Zapier account
2. New Zap: Google Forms → Webhooks
3. Select form
4. Action: POST to above URL
5. Test & activate

---

## 📊 What Gets Imported

✅ **Imported:**
- All mapped fields
- Data from Google Sheet rows
- Automatic timestamp

❌ **NOT Imported:**
- Duplicate emails/phones (skipped)
- Unmapped columns (ignored)
- Empty rows (skipped)
- Timestamp column (auto-generated)

---

## 💾 Data Safety

✅ **Duplicates Prevented**
- Checks email first
- Falls back to phone
- Existing entries not updated

✅ **Errors Logged**
- All imports logged
- Failed entries show count
- CSV parsing errors caught

✅ **Database Safe**
- Prepared statements (SQL injection prevention)
- Transaction-like handling
- Rollback on critical errors

---

## 🎓 Use Cases

### School Event
- Google Form with Name, Email, Phone, Class
- Import into registrations table
- Check-in uses this data

### Conference
- Form with Name, Email, Company, Designation
- Auto-import to registrations
- Badges printed from database

### Workshop
- Form with Name, Email, Skill Level
- Batch import before event
- Attendance tracking per skill level

### Competition
- Form with Name, Team, Category
- Real-time import as forms submitted
- Live leaderboard updates

---

## ⚙️ Advanced Configuration

### Automatic Fetch with Cron

Add to server crontab (every 30 minutes):
```bash
*/30 * * * * curl 'http://localhost:8000/admin/fetch_registration.php?event_id=1&action=auto' > /dev/null 2>&1
```

### Multiple Events

Each event has separate configuration:
- Sheet 1 → Event 1
- Sheet 2 → Event 2
- Independent fetch settings

### Custom Default Values

Set default value for unmapped field:
- Event ID (auto)
- Registration date (auto)
- Status (e.g., "Pending Approval")
- Category (e.g., "General")

---

## 🔐 Security Notes

✅ **Safe practices:**
- Keep webhook URL secret
- Only import from trusted sources
- Verify form submissions legitimate
- Monitor import logs regularly
- Backup database before large imports

---

## 📞 Support

**Troubleshooting:**
1. Check sheet is public
2. Verify field mapping with preview
3. Test manual fetch first
4. Check server logs for errors
5. Verify database table exists

**Files involved:**
- `admin/fetch_registration.php` - Main page
- `admin/google_sheets_funcs.php` - Core functions
- `admin/google_sheets_webhook.php` - Webhook receiver

---

## 📈 Import Metrics

System shows after each fetch:

```
✅ Success: 15 registrations imported
⏭️  Duplicates: 3 entries skipped (already registered)
❌ Failed: 0 entries with errors
```

---

## 🚀 Best Practices

1. **Test First**
   - Submit 1-2 test forms
   - Fetch manually
   - Verify data

2. **Map Carefully**
   - Use preview to confirm columns
   - Set sensible defaults
   - Don't skip critical fields

3. **Monitor Imports**
   - Check success count
   - Review import frequency
   - Monitor database size

4. **Keep Forms Consistent**
   - Use same field names
   - Keep format consistent
   - Don't rename columns mid-event

5. **Backup Regularly**
   - Backup database before large imports
   - Keep form responses as backup
   - Document mapping for reference

---

## 📚 Full Documentation

See `FETCH_REGISTRATION_GUIDE.md` for:
- Detailed setup instructions
- Webhook configuration
- Database schema
- API reference
- Troubleshooting guide
- Security best practices

---

**Status:** ✅ Ready to use! Start with your event and Google Sheet.
