# 🎨 Enhanced Field Mapping - Quick Reference

## 🖱️ New Features at a Glance

### Buttons & Actions

| Button | Icon | Function |
|--------|------|----------|
| **Auto-Match Fields** | 🔗 | Automatically match similar field names |
| **Clear All** | 🗑️ | Remove all mappings (with confirmation) |
| **Show Sample Mapping** | 📋 | Display example mappings |
| **Clear** (per row) | ✕ | Clear individual field mapping |

---

## 📊 Field Mapping Table

### Column Headers

1. **⋮** - Drag handle (reorder fields)
2. **Registration Table Field** - Database column name
3. **Google Sheet Column** - Dropdown selector
4. **Default Value** - Fallback value for empty cells
5. **Action** - Clear button for individual fields

### Visual Indicators

- ✓ Green checkmark = Field successfully mapped
- * Red asterisk = Required field (email, phone, name)
- ⚠️ Auto-matched = System auto-selected this mapping
- 🟢 Hover = Field row highlights in light gray

---

## ⚡ Quick Workflows

### Workflow 1: Auto-Setup (2 minutes)
```
1. Click "🔗 Auto-Match Fields"
2. Review suggested mappings
3. Adjust any incorrect matches
4. Click "💾 Save Field Mapping"
```

### Workflow 2: Manual Setup (5 minutes)
```
1. Scroll through sheet preview
2. For each DB field:
   - Click dropdown
   - Select matching sheet column
   - Set default if needed
3. Click "💾 Save Field Mapping"
```

### Workflow 3: Start Over (1 minute)
```
1. Click "🗑️ Clear All"
2. Confirm deletion
3. All fields reset to unmapped
```

---

## 🎯 Essential Mappings

**Must have:**
- ✓ `email` - Primary duplicate detection
- ✓ `phone` - Fallback duplicate detection
- ✓ `name` - Basic identification

**Should have:**
- `course` - For categorizing registrations
- `gender` - Demographic data
- `address` - Contact information

**Nice to have:**
- Other fields based on your needs

---

## 📈 Mapping Status Meanings

```
✓ 8 of 15 fields mapped       → Good to go!
⚠️ 5 of 15 fields mapped      → Consider mapping more
⚠️ 0 of 15 fields mapped      → No fields mapped
```

**Recommendation:** Minimum 5-7 core fields for production use

---

## 💾 Save & Test

### After Mapping

1. **Save:** Click "💾 Save Field Mapping"
2. **Scroll down** to Step 3 & 4
3. **Test:** Click "Fetch Data Now"
4. **Verify:** Check registrations table for correct data
5. **Adjust:** If data is wrong, come back and remap

---

## 🔀 Reordering Fields

```
1. Hover over row → cursor changes to ↔️
2. Click and drag the ⋮⋮ handle
3. Drag up/down to new position
4. Release to drop
5. Save mapping to persist order
```

---

## 🔧 Troubleshooting

| Problem | Solution |
|---------|----------|
| Auto-match didn't find anything | Field names are very different; use Show Sample Mapping |
| Data importing to wrong column | Check column selection in dropdown |
| Duplicates still importing | Map email or phone field (required) |
| Forgot what I mapped | Check the green checkmarks in sheet column dropdown |

---

## 📋 Common Field Mappings

### School Event
```
name → Full Name of the Child
email → Email Address
phone → Mobile number
gender → Gender of the Child
course → Grade/Class
```

### Conference
```
name → Participant Name
email → Email Address
phone → Phone Number
course → Designation
college → Company
```

---

## ✨ Features Summary

✓ Auto-matching based on similarity  
✓ Real-time mapping status display  
✓ Per-field default values  
✓ Drag-to-reorder fields  
✓ Clear individual or all mappings  
✓ Sample mapping reference  
✓ Visual feedback (✓ checkmarks)  
✓ Required field indicators (*)  

---

## 🎓 Next Steps

After mapping fields:

1. **Step 3:** Configure fetch methods (manual/automatic/real-time)
2. **Step 4:** Click "Fetch Data Now" to test
3. **Monitor:** Watch for duplicate detection
4. **Adjust:** Refine mapping if needed
5. **Deploy:** Set automatic fetch for production

---

**Pro Tip:** 💡 Always test with manual fetch before enabling automatic fetch!
