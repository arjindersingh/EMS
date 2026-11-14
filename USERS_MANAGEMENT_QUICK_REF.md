# Quick Reference: Users Management

## Access the Module

**URL:** `http://localhost:8000/admin/users.php`

**Menu:** Administration → Users

**Requirements:** Admin login with credentials

---

## Three Main Tabs

### 1️⃣ Create User
| Action | Steps |
|--------|-------|
| **Create User** | Fill form → Select role → Click "Create User" |
| **Edit User** | Click Edit on user row → Modify fields → Click "Update User" |
| **Delete User** | Click Delete → Confirm in dialog |
| **Assign Role** | Select from role dropdown |
| **Assign Event** | Select from event dropdown |

### 2️⃣ User Roles
| Action | Steps |
|--------|-------|
| **Create Role** | Enter name & description → Toggle event flag → Click "Create Role" |
| **Edit Role** | Click Edit → Modify details → Click "Update Role" |
| **Delete Role** | Click Delete → Confirm |
| **Set Permissions** | Click "Permissions" button → Go to Tab 3 |

### 3️⃣ Permission Settings
| Action | Steps |
|--------|-------|
| **Configure** | Select role from list |
| **Grant Access** | Check menu items ✓ |
| **Revoke Access** | Uncheck menu items ☐ |
| **Save** | Click "Save Permissions" |

---

## Default Roles

| Role | Primary Use | Default Menus |
|------|-------------|---------------|
| **Admin** | System administrator | All 25 menus |
| **Event Manager** | Create/manage events | Events, Registration, Reports |
| **Coordinator** | Event coordination | Events, Registration, Attendance, Feedback |
| **Registration Incharge** | Registration oversight | Registration, Approval, Reports |
| **Stage Manager** | Event operations | Check-in, Schedules, Attendance |
| **Registration Desk** | Front desk operations | Check-in, Attendee List, Attendance |

---

## Form Fields Reference

### Create User Form
```
Full Name*           : Text input
Email*               : Email input (unique)
Username*            : Text input (unique)
Password*            : Password input
Role                 : Dropdown (optional)
Assigned Event       : Dropdown (optional)
```

### Create Role Form
```
Role Name*           : Text input (unique)
Description          : Textarea
Event Assignment     : Checkbox
```

### Permission Form
```
Role Selection       : Read-only dropdown
Menu Items (25)      : Checkbox grid
                       - Administration (5)
                       - Pre-Event (8)
                       - On-Event (4)
                       - Post-Event (8)
```

---

## Menu Items Available (25 Total)

**Administration (5):**
- Users
- Admin Users
- Settings
- Registration Dropdown Options
- Playback

**Pre-Event (8):**
- Create Event
- Registration Schedule
- Itinerary
- Approval
- Event Pass
- Registration
- Bulk Upload
- More...

**On-Event (4):**
- Event Check-in
- Live Checkin
- Live Attendance
- More...

**Post-Event (8):**
- Event Reports
- Feedback
- Feedback Analysis
- Certificate
- More...

---

## Common Scenarios

### Scenario 1: Add New Registration Staff
```
1. Click "Create User" tab
2. Enter:
   - Name: "Priya Desk"
   - Email: "priya@event.com"
   - Username: "priya_desk"
   - Password: "SecurePass123"
3. Select Role: "Registration Desk"
4. Click "Create User"
5. Priya can now login and see only registration menus
```

### Scenario 2: Restrict User to Specific Event
```
1. Click "Create User" tab
2. Click "Edit" on user row
3. Select Event: "Annual Fest 2024"
4. Click "Update User"
5. User's role is now scoped to that event
```

### Scenario 3: Customize Role Permissions
```
1. Click "User Roles" tab
2. Find role, click "Permissions"
3. In "Permission Settings" tab:
   - Check only needed menus
   - Uncheck unnecessary menus
4. Click "Save Permissions"
5. All users with this role see updated menus
```

---

## Error Messages & Solutions

| Message | Cause | Solution |
|---------|-------|----------|
| "All fields are required" | Missing form fields | Fill all required fields (*) |
| "Username or email already exists" | Duplicate user | Use unique email/username |
| "Role name is required" | Empty role name | Enter role name |
| Database connection failed | DB unreachable | Check MySQL is running |

---

## Success Messages

✅ User 'Name' created successfully!
✅ User updated successfully!
✅ User deleted successfully!
✅ Role 'Name' created successfully!
✅ Role updated successfully!
✅ Role deleted successfully!
✅ Permissions updated successfully!

---

## Key Points

⭐ **Remember:**
- Passwords use bcrypt hashing (secure)
- Role deletion cascades to permissions
- Event assignment is optional
- Permission changes take effect immediately
- All usernames and emails must be unique
- Admin role cannot be removed (only via database)

⚠️ **Before Deleting:**
- Verify user is not critical
- Check if permissions can be transferred
- Consider deactivation alternatives

✅ **Best Practices:**
- Use descriptive role names
- Keep role descriptions current
- Test permissions with test account
- Document custom role purposes
- Regularly audit user access

---

## Database Tables

```
admin_users
├── admin_user_id
├── name
├── username (unique)
├── email (unique)
├── password_hash
├── role_id
├── assigned_event_id
└── created_at

roles
├── role_id
├── role_name (unique)
├── role_description
├── event_assigned (flag)
└── created_at

menu_items
├── menu_id
├── menu_key (unique)
├── menu_label
├── menu_icon
├── menu_url
└── menu_order

role_menu_permissions
├── permission_id
├── role_id (FK)
├── menu_id (FK)
└── can_access (1/0)
```

---

## Keyboard Shortcuts

| Action | Shortcut |
|--------|----------|
| Tab navigation | Click tab button |
| Save form | Ctrl+Enter (if supported) |
| Cancel edit | Click "Cancel" link |
| Confirm delete | Click "OK" in dialog |

---

## Support

📖 **Full Documentation:**
- `USERS_MANAGEMENT_GUIDE.md` - Comprehensive guide
- `USERS_MANAGEMENT_IMPLEMENTATION.md` - Technical details

🐛 **Issues:**
- Check browser console (F12) for errors
- Verify admin is logged in
- Ensure database is running
- Check file permissions

---

**Last Updated:** 2024
**Status:** ✅ Production Ready
