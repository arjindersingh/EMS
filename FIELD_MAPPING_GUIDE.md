# 🎨 Enhanced Field Mapping - User Guide

## What's New

The Step 2: Map Fields section now includes interactive, user-friendly features to make field mapping easier and faster.

---

## 📋 Field Mapping Interface

### Main Table Features

The field mapping table now displays:

| Column | Description |
|--------|-------------|
| **⋮** | Drag handle for reordering fields |
| **Registration Table Field** | Database column name (with * for required fields) |
| **Google Sheet Column** | Dropdown to select the corresponding sheet column |
| **Default Value** | Optional default value if sheet column is empty |
| **Action** | Clear button (✕) to remove mapping for that field |

### Visual Indicators

- ✓ **Green checkmark** - Shows confirmed mapping
- **⚠️ Red asterisk** - Indicates required fields (email, phone, name, registration_id)
- **Auto-matched indicator** - Shows fields that were automatically matched

---

## 🚀 Quick Action Buttons

### 1. 🔗 Auto-Match Fields
**What it does:** Automatically matches database fields to Google Sheet columns based on similarity

**How it works:**
- Compares field names (case-insensitive)
- Looks for exact matches or partial matches
- Only maps unmapped fields
- Gives you a count of matched fields

**When to use:**
- When Google Sheet column names are similar to database fields
- As a starting point before manual refinement
- For quick setup

**Example:**
```
Database Field: full_name
Sheet Column: Full Name of the Child
Result: ✓ Auto-matched
```

---

### 2. 🗑️ Clear All
**What it does:** Removes all field mappings at once

**How it works:**
- Clears all dropdown selections
- Empties all default value inputs
- Requires confirmation to prevent accidental deletion

**When to use:**
- Starting over with a new mapping strategy
- Switching to a different Google Sheet

---

### 3. 📋 Show Sample Mapping
**What it does:** Displays a recommended mapping for a typical school event

**Example output:**
```
Sample Mapping for a typical School Event:

name → Full Name of the Child
email → Email Address
phone → Mobile number of Parent
gender → Gender of the Child
course → Full Name of the Child
```

**When to use:**
- Getting ideas for how to map your fields
- Understanding common field naming conventions

---

## 📊 Mapping Status

Real-time status indicator showing:
- **Count:** How many fields are mapped vs. total fields
- **Status:** Visual feedback with indicator
- **Suggestions:** Helpful hints

### Status Examples

```
✓ 8 of 15 fields mapped ✓ Good to go!
```
```
⚠️ 2 of 15 fields mapped ⚠️ Consider mapping more fields
```
```
⚠️ 0 of 15 fields mapped ⚠️ No fields mapped
```

---

## 🔄 How to Map Fields

### Step-by-Step Process

**Step 1: View Your Google Sheet Columns**
- The preview table at the bottom shows your actual Google Sheet data
- Review column names and sample data

**Step 2: Select Matching Columns**
- For each database field, click the dropdown
- Choose the corresponding Google Sheet column
- Green checkmark will appear: "✓ Mapped to: [Column Name]"

**Step 3: (Optional) Set Default Values**
- If a database field isn't in the Google Sheet, set a default
- Example: Set "Campus" to "Green Model Town" if not in sheet

**Step 4: Try Auto-Match (Optional)**
- Click "🔗 Auto-Match Fields" to automatically map similar names
- Review and adjust as needed

**Step 5: Check Status**
- Watch the "Mapping Status" indicator
- Aim for at least the essential fields mapped

**Step 6: Save**
- Click "💾 Save Field Mapping"
- Mapping configuration is saved to database

---

## 📚 Common Mapping Scenarios

### School Event Registration

**Database Fields → Google Sheet Columns**
```
name → Full Name of the Child
email → Email Address
phone → Mobile number of Parent / Guardian
gender → Gender of the Child
course → Grade / Class
category → Category (if in sheet)
address → Address
state → State
country → Country
college → School Name
```

### Conference Registration

**Database Fields → Google Sheet Columns**
```
name → Participant Name
email → Email Address
phone → Contact Number
course → Designation / Title
college → Company / Organization
category → Registration Type
address → Address
```

### Health Event Registration

**Database Fields → Google Sheet Columns**
```
name → Patient Name
email → Email Address
phone → Phone Number
date_of_birth → Date of Birth
gender → Gender
address → Street Address
city → City
state → State
country → Country
```

---

## 🎯 Best Practices

### 1. Essential Fields First
Always map these fields if available:
- ✓ **email** - Primary duplicate detection
- ✓ **phone** - Fallback duplicate detection
- ✓ **name** - Required for most registrations

### 2. Use Default Values
Set sensible defaults for optional fields:
```
campus → "Green Model Town"
course → "General"
category → "Student"
```

### 3. Test Before Production
- Save your mapping
- Click "Fetch Data Now" to import a few records
- Verify data is in correct columns
- Adjust mapping if needed

### 4. Order Matters
- Drag fields to group related information
- Put identification fields (name, email) first
- Group contact info together
- Group optional fields at the end

---

## 🔍 Troubleshooting

### Issue: Fields not auto-matching

**Possible Reasons:**
- Field names are very different from sheet column names
- Typos in either field name

**Solution:**
- Manually select from dropdown
- Use "Show Sample Mapping" for ideas

### Issue: Data not importing correctly

**Possible Reasons:**
- Column mapping is wrong
- Data format mismatch

**Solution:**
1. Check the preview table
2. Clear mapping and remap carefully
3. Look for default values that might override sheet data

### Issue: Duplicate records still importing

**Possible Reasons:**
- Email/phone field not mapped
- Duplicate detection fields are empty in sheet

**Solution:**
- Map email field (primary check)
- Ensure phone field is mapped (fallback)
- Check sheet for empty email/phone cells

---

## 💡 Tips & Tricks

### Quickly Remap a Field
1. Click the dropdown for that field
2. Select new column
3. Green checkmark updates immediately
4. Click "💾 Save Field Mapping"

### Reorder Fields
1. Click and hold the drag handle (⋮⋮) on a row
2. Drag to new position
3. Release to drop
4. Save mapping to persist order

### Clear One Field Only
1. Click the ✕ button on the specific row
2. Dropdown and default value are cleared
3. No confirmation needed for single fields

### Test Different Mappings
1. Save current mapping
2. Clear all mappings
3. Try different mapping
4. Test with manual fetch
5. Switch back if needed

---

## 📝 Field Mapping Reference

### Database Fields

| Field | Type | Description | Required |
|-------|------|-------------|----------|
| registration_id | INT | Auto-generated ID | No |
| event_id | INT | Event reference | No |
| name | VARCHAR | Full name | ✓ Yes |
| email | VARCHAR | Email address | ✓ Yes (for duplicates) |
| phone | VARCHAR | Phone number | ✓ Yes (fallback) |
| course | VARCHAR | Course/Grade | No |
| category | VARCHAR | Category type | No |
| gender | VARCHAR | Gender | No |
| address | TEXT | Street address | No |
| city | VARCHAR | City | No |
| state | VARCHAR | State | No |
| country | VARCHAR | Country | No |
| date_of_birth | DATE | DOB | No |
| college | VARCHAR | College/School | No |
| roll_number | VARCHAR | Roll/ID number | No |
| group_name | VARCHAR | Group name | No |
| notes | TEXT | Additional notes | No |

---

## 🎓 Learning Resources

- **Quick Start:** 5-minute setup guide
- **Full Guide:** Comprehensive documentation
- **Sample Data:** Use Show Sample Mapping button
- **Video Tutorial:** (Coming soon)

---

## ✨ Summary

The enhanced field mapping interface provides:
- ✓ Auto-matching for quick setup
- ✓ Visual status updates
- ✓ Quick action buttons
- ✓ Default value support
- ✓ Drag-and-drop reordering
- ✓ Real-time feedback
- ✓ Sample mappings
- ✓ Data preview

**Result:** Faster, easier, and more accurate field mapping! 🎉

---

## Need Help?

Check the troubleshooting section above or see the comprehensive **FETCH_REGISTRATION_GUIDE.md** for detailed setup instructions.
