# ✅ Accessing Fetch Registration - Complete Instructions

## 🚨 Why You See "403 Forbidden"

The error occurs because:
1. ❌ You're not logged in to the admin panel
2. ❌ Your browser doesn't have an active session

**Solution:** Log in first, then access the page.

---

## ✅ CORRECT WAY TO ACCESS

### Step 1: Go to Login Page
```
URL: http://localhost/ems/admin/
```

**What you'll see:**
```
Admin Panel
This area is protected and requires authentication

Sign in
[Username or email field]
[Password field]
[Login button]
```

---

### Step 2: Enter Your Credentials

**Email/Username:** `ihce@ihgi.in`  
**Password:** `Ihgi@2020`

Click **Login**

---

### Step 3: Navigate to Fetch Registration

Once logged in, use the menu:

**Option A: Using Top Menu**
- Click menu dropdown
- Select: **Pre**
- Click: **Fetch Registration**

**Option B: Using Sidebar**
- Look for: **Pre** section
- Click: **Fetch Registration**

**Option C: Direct URL**
After logging in, you can use:
```
http://localhost/ems/admin/fetch_registration.php?event_id=1
```

---

## ⚠️ COMMON MISTAKES

### ❌ Mistake 1: Using Wrong URL Encoding
```
WRONG: http://localhost/ems/admin/fetch_registration.php%3Fevent_id%3D1
RIGHT: http://localhost/ems/admin/fetch_registration.php?event_id=1
```
The `%3F` is URL-encoded `?` - use actual `?` character

---

### ❌ Mistake 2: Accessing Without Login
```
WRONG: Open browser → Type URL → Press Enter → Get 403 error
RIGHT: Log in first → Then access the page → It works
```

---

### ❌ Mistake 3: Copy-Pasting from URL Bar
Some tools URL-encode the `?` character automatically.

**Solution:**
- Type the URL manually OR
- Use the menu system to navigate

---

## 🔄 STEP-BY-STEP WORKFLOW

### For First Time Users

```
1. Open browser
   ↓
2. Go to: http://localhost/ems/admin/
   ↓
3. See login form
   ↓
4. Enter:
   Email: ihce@ihgi.in
   Password: Ihgi@2020
   ↓
5. Click: Login
   ↓
6. You're now logged in!
   ↓
7. Use menu to navigate:
   Click: Pre → Fetch Registration
   ↓
8. You should see:
   📌 Select Event
   🔗 Step 1: Google Sheet Configuration
   🗂️ Step 2: Map Fields (ENHANCED!)
   ⚙️ Step 3: Configure Fetch Methods
   📥 Step 4: Fetch Data Now
   ↓
9. Select your event
   ↓
10. Continue with registration import!
```

---

## 🌐 BROWSER SESSIONS

### How Sessions Work

```
Browser opens → No session yet → Can't access protected pages
         ↓
      Log in
         ↓
Session created → Can access all protected pages
         ↓
Navigate normally
         ↓
Session expires OR logout → Back to step 1
```

### Session Tips

✅ **Do This:**
- Stay logged in while working
- Open multiple tabs (same session works)
- Refresh page when needed
- Log out when done

❌ **Don't Do This:**
- Close browser → Lose session
- Clear cookies → Lose session
- Use private/incognito mode (clears session)
- Access URL without logging in first

---

## 🔗 CORRECT URLS

### Admin Panel URLs

| Page | Correct URL |
|------|-------------|
| Login | `http://localhost/ems/admin/` |
| Users | `http://localhost/ems/admin/users.php` |
| Events | `http://localhost/ems/admin/events.php` |
| Registrations | `http://localhost/ems/admin/registration.php` |
| Fetch Registration | `http://localhost/ems/admin/fetch_registration.php` |
| Fetch with Event | `http://localhost/ems/admin/fetch_registration.php?event_id=1` |

### Important Notes
- Always use regular `?` not `%3F`
- Always `&` not `%26` for multiple parameters
- Use menu system if unsure of URL

---

## 📱 WHAT HAPPENS WHEN YOU LOG IN

### Session Cookie Created
```
Browser remembers: "This is user ihce@ihgi.in"
Stored in: Browser session/cookies
Expires: After 30 minutes of inactivity (default)
```

### Pages Now Accessible
```
✓ Admin dashboard
✓ Users management
✓ Events management
✓ Registrations
✓ Fetch Registration (NEW!)
✓ All admin features
```

### Automatic Redirect
```
If you try to access admin page while logged out:
  Browser redirects to login page automatically
  After login: Redirects to the page you wanted
```

---

## 🎯 QUICK CHECKLIST

Before accessing Fetch Registration:

- [ ] Open browser
- [ ] Go to `http://localhost/ems/admin/`
- [ ] See login form
- [ ] Enter email: `ihce@ihgi.in`
- [ ] Enter password: `Ihgi@2020`
- [ ] Click Login button
- [ ] Wait for page to load
- [ ] See admin dashboard
- [ ] Click menu: Pre → Fetch Registration
- [ ] Select your event
- [ ] See Step 1: Google Sheet Configuration

✅ You're in! Ready to use the enhanced field mapping!

---

## 🚀 NEXT STEPS

Once logged in and on the Fetch Registration page:

1. **Step 1:** Configure Google Sheet URL
   - Paste your Google Form response sheet URL
   - Click "Save Sheet URL"

2. **Step 2:** Map Fields (ENHANCED!)
   - Click "🔗 Auto-Match Fields" for quick setup
   - Or manually select sheet columns
   - Add default values if needed
   - Click "💾 Save Field Mapping"

3. **Step 3:** Configure Fetch Methods
   - Choose manual, automatic, or real-time
   - Click "Save Fetch Methods"

4. **Step 4:** Fetch Data
   - Click "🚀 Fetch Data Now"
   - Watch for results
   - Check registrations table

---

## 💡 TIPS

### Tip 1: Bookmark the Page
After logging in, bookmark:
```
http://localhost/ems/admin/fetch_registration.php?event_id=1
```
(Replace `1` with your event ID)

### Tip 2: Keep Tab Open
The session stays active as long as the browser is open, so:
- Keep the tab open
- Work on other tasks
- Come back when ready

### Tip 3: Session Timeout
If page shows 403 again after a while:
- Your session expired
- Go back to login page
- Log in again
- Session is restored

### Tip 4: Multiple Events
To switch events:
- Use dropdown on page
- Select different event
- Settings change automatically

---

## ❌ TROUBLESHOOTING

### Problem: Still Getting 403 After Login

**Possible Causes:**
1. Session cookie not saved
2. Browser cookies disabled
3. Private/Incognito mode

**Solutions:**
```
Try 1: Clear browser cookies
Try 2: Use normal browsing mode (not incognito)
Try 3: Try different browser
Try 4: Restart browser after login
```

---

### Problem: Login Not Working

**Check:**
1. Email: `ihce@ihgi.in` (exactly)
2. Password: `Ihgi@2020` (exactly)
3. Server is running
4. No CAPS LOCK on

**If Still Failing:**
- Check browser console for errors
- Try opening admin in new tab
- Contact system admin

---

### Problem: Page Shows But No Content

**Likely Cause:** Event not selected or no events exist

**Solution:**
1. Check dropdown: "Select Event"
2. If empty: Create an event first
3. Then refresh page

---

### Problem: URL Gets URL-Encoded Automatically

**Issue:** Browser changes `?` to `%3F`

**Solutions:**
- Use the menu system instead
- Or type URL carefully
- Or check your bookmarks syntax

---

## 📞 NEED HELP?

**See Documentation:**
- Quick Reference: `FIELD_MAPPING_QUICK_REFERENCE.md`
- Full Guide: `FIELD_MAPPING_GUIDE.md`
- Status: `FETCH_REGISTRATION_STATUS.md`

**Common Questions:**
- Q: How do I log in? → See "Step-by-Step Workflow" above
- Q: My session expired. → Log in again
- Q: 403 error. → Make sure you're logged in first
- Q: Can't find the menu. → Use direct URL

---

## ✅ YOU'RE READY!

Everything is set up and working.

**Right now:**
1. Go to `http://localhost/ems/admin/`
2. Log in with the credentials above
3. Navigate to Fetch Registration
4. Start mapping your Google Sheet fields!

---

**Status: ✅ System Fully Operational**

The enhanced field mapping is ready to use. Just log in and start! 🎉
