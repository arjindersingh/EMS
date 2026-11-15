# RBAC System - Quick Start Guide

## 🎯 What Was Built

A complete **Role-Based Access Control** system replacing simple admin authentication with:

✅ Multiple user accounts  
✅ 6 pre-configured roles (Admin, Event Manager, Coordinator, Registration Incharge, Stage Manager, Registration Desk)  
✅ Configurable menu permissions per role  
✅ User management interface  
✅ Role management interface  
✅ Event assignment per user  

## 🚀 Get Started in 3 Steps

### Step 1: Initialize the System

```bash
php admin/setup_rbac.php
```

This will:
- Create database tables for roles and permissions
- Set up 6 default roles
- Configure 25 menu items
- Create default permission mappings
- Migrate your admin user to Admin role

**Expected output:**
```
✓ RBAC tables created
✓ Default roles created
✓ Menu items configured
✓ Default permissions set
✓ Setup Complete!
```

### Step 2: Log In to Admin Panel

Open: **http://localhost:8000/admin**

```
Username: admin
Password: admin123 (or your custom password from .env)
```

### Step 3: Create Users & Configure Roles

#### A. Create Your First Non-Admin User

1. Go to **Administration > User Management**
2. Click **"Add New User"**
3. Fill in:
   - **Full Name:** John Coordinator
   - **Username:** john_coord
   - **Email:** john@school.edu
   - **Password:** temp_password_123
   - **Role:** Coordinator
   - **Assigned Event:** (select an event if needed)
4. Click **"Create User"**

#### B. Configure Role Permissions

1. Go to **Administration > Roles & Permissions**
2. Click **"Configure"** next to any role
3. Check/uncheck menu items to allow/deny access
4. Click **"Save Permissions"**

**Example: Registration Incharge**
- Allow: Registrations, Approvals, Bulk Upload, Attendee List, Reports
- Deny: Event creation, Broadcasting, Feedback

## 👥 Default Roles Explained

### Admin 🔐
- **Access:** Everything
- **Use:** System administration
- **Created Automatically:** Yes, from existing admin user

### Event Manager 👔
- **Access:** Event management, Registration, Check-in, Reports, Feedback
- **Use:** Full event control
- **Event Required:** Yes

### Coordinator 📊
- **Access:** Event coordination, Registration, Attendance
- **Use:** Event organization
- **Event Required:** Yes

### Registration Incharge 📋
- **Access:** Registration approvals, Bulk upload, Attendance
- **Use:** Registration workflow management
- **Event Required:** Yes

### Stage Manager 🎬
- **Access:** Event check-in, Broadcasting, Schedules
- **Use:** On-stage operations
- **Event Required:** Yes

### Registration Desk 🗂️
- **Access:** Event check-in, Attendee list
- **Use:** On-site registrations
- **Event Required:** Yes

## 📋 Common Tasks

### Create a New User

```
Admin > User Management > Add New User
├── Full Name: Required
├── Username: Required (unique)
├── Email: Required (unique)
├── Password: Required
├── Role: Select from dropdown
└── Assigned Event: Required for event-specific roles
```

### Edit a User

```
Admin > User Management > (click Edit)
├── Modify: Name, Email, Password
├── Change: Role or Assigned Event
└── Save: Update User
```

### Delete a User

```
Admin > User Management > (click Delete)
└── Confirm: Deletion
```

### Create a Custom Role

```
Admin > Roles & Permissions > Create Role
├── Role Name: (e.g., "Feedback Analyst")
├── Description: (e.g., "Collects and analyzes event feedback")
├── Event Assignment: Check if users need event assignment
└── Create: Role
```

### Configure Role Permissions

```
Admin > Roles & Permissions > Configure (on any role)
├── View: All 25 menu items
├── Check/Uncheck: Items to allow/deny
└── Save: Permissions
```

## 🔑 Key Concepts

### Roles
- Groups of users with same permissions
- Examples: Admin, Event Manager, Registration Desk
- Can be created, edited, or deleted
- Cannot delete Admin role

### Menu Items
- Individual features/pages in admin panel
- Examples: Event check-in, Registrations, Reports
- 25 items organized into 9 sections
- Users see only items their role allows

### Permissions
- Links between roles and menu items
- Determines if role can access that menu
- Configured via checkboxes
- Changes apply to all users with that role

### Event Assignment
- Users assigned to specific event
- Applies only to event-specific roles
- Examples: Stage Manager for "Tech Expo", another for "Sports Day"
- Admin users not required to have event

## 🛡️ Security

### Built-in Security
✅ Passwords hashed with bcrypt  
✅ Session validation on each request  
✅ Permission checks before page access  
✅ SQL injection prevention (prepared statements)  
✅ XSS prevention (output escaping)  
✅ Role-based access enforcement  

### Best Practices
1. Change default password immediately
2. Use strong passwords (12+ characters)
3. Don't share logins - create individual accounts
4. Remove unused roles
5. Grant only needed permissions

## 🔄 Database

### New Tables Created
1. **roles** - Role definitions
2. **menu_items** - Available menu items
3. **role_menu_permissions** - Role-menu access mappings
4. **admin_users (enhanced)** - Added role_id, assigned_event_id columns

### Data Pre-Populated
- 6 roles
- 25 menu items
- 150 permissions (25 per role x 6 roles)
- Existing admin user → Admin role

## 📊 Permission Matrix

```
                        Admin    Event   Coord   Reg.In   Stage   Reg.Desk
                               Manager          charge   Mgr     
Dashboard                ✓        ✓       ✓       ✓        ✓        ✓
Events Section            ✓        ✓       ✓       ✗        ✗        ✗
  Manage Events           ✓        ✓       ✓       ✗        ✗        ✗
  Check-in                ✓        ✓       ✓       ✗        ✓        ✓
  Schedules               ✓        ✓       ✓       ✗        ✓        ✗
  Itineraries             ✓        ✓       ✓       ✗        ✓        ✗
Registration Section      ✓        ✓       ✓       ✓        ✗        ✗
  Registrations           ✓        ✓       ✓       ✓        ✗        ✗
  Approvals               ✓        ✓       ✓       ✓        ✗        ✗
  Bulk Upload             ✓        ✓       ✗       ✓        ✗        ✗
Attendance Section        ✓        ✓       ✓       ✓        ✓        ✓
  Attendee List           ✓        ✓       ✓       ✓        ✓        ✓
  View Attendance         ✓        ✓       ✓       ✓        ✓        ✓
  Reports                 ✓        ✓       ✓       ✓        ✗        ✗
Feedback Section          ✓        ✓       ✓       ✗        ✗        ✗
  Feedback                ✓        ✓       ✓       ✗        ✗        ✗
  Analysis                ✓        ✓       ✗       ✗        ✗        ✗
Event Passes              ✓        ✓       ✗       ✗        ✗        ✗
Broadcast Messages        ✓        ✓       ✗       ✗        ✓        ✗
Event Playback            ✓        ✓       ✗       ✗        ✗        ✗
Administration            ✓        ✗       ✗       ✗        ✗        ✗
  User Management         ✓        ✗       ✗       ✗        ✗        ✗
  Roles & Permissions     ✓        ✗       ✗       ✗        ✗        ✗
  Admin Users             ✓        ✗       ✗       ✗        ✗        ✗
  Settings                ✓        ✗       ✗       ✗        ✗        ✗
```

## 🐛 Troubleshooting

### User can't see menu item
**Check:**
1. User is logged in
2. User has role assigned
3. Role has permission for that menu
4. Refresh browser cache

**Fix:**
```
1. Edit user > Assign role > Save
2. Go to role permissions > Check menu > Save
3. User logs out and back in
```

### Setup script fails
**Check:**
1. `.env` file exists with DB credentials
2. MySQL is running
3. Database user has CREATE TABLE permissions

**Fix:**
```bash
php admin/setup_rbac.php
```

### Users can't log in
**Check:**
1. Username/email is correct
2. Password is correct
3. User has role assigned

**Fix:**
Edit user, assign role "Admin" or other role, save.

### Permissions don't update
**Fix:**
1. Clear browser cache (Ctrl+Shift+Delete)
2. Log out completely
3. Log back in
4. Try again

## 📚 Full Documentation

For complete documentation, see:
- **RBAC_GUIDE.md** - Comprehensive guide (11,000+ words)
- **RBAC_IMPLEMENTATION_SUMMARY.md** - Technical details
- **admin/rbac_funcs.php** - Function reference (inline comments)

## 🎓 Example Scenarios

### Scenario 1: School Tech Day Event

```
Roles Needed:
├── Admin (System admin only)
├── Event Manager (Priya - full control)
├── Registration Incharge (Ramesh - approvals)
├── Stage Manager (Ravi - announcements)
└── Registration Desk (3 volunteers - check-in)

Setup:
1. Create users for each person
2. Assign: Priya → Event Manager + Tech Day event
3. Assign: Ramesh → Registration Incharge + Tech Day event
4. Assign: Ravi → Stage Manager + Tech Day event
5. Assign: Volunteers → Registration Desk + Tech Day event

Result:
- Each person sees only their menu items
- All work on same event
- Clear role separation
- No cross-access
```

### Scenario 2: Multi-Event Management

```
Roles Needed:
├── Admin (Coordinator only)
├── Event Manager x 2 (One per event)
└── Registration Desk x 4 (2 per event)

Setup:
1. Create Event Manager 1 assigned to "Sports Day"
2. Create Event Manager 2 assigned to "Cultural Festival"
3. Create 2 Registration Desk users for Sports Day
4. Create 2 Registration Desk users for Cultural Festival

Result:
- Each event manager works only on their event
- Registration staff see only their event
- No data mixing
- Independent workflows
```

## ✨ Key Files

### Core System
```
admin/rbac_funcs.php              Main RBAC functions (600+ lines)
admin/rbac_init.php               Initialization helper
admin/setup_rbac.php              One-time setup script
```

### Admin Interfaces
```
admin/user_management.php         Create/edit/delete users
admin/role_management.php         Configure roles & permissions
```

### Documentation
```
RBAC_GUIDE.md                     Full user guide
RBAC_IMPLEMENTATION_SUMMARY.md    Technical details
RBAC_QUICK_START.md              This file
```

## 🚢 Deployment

### Local Development
```bash
# Already set up via setup_rbac.php
# Just log in and use!
```

### Production (Vercel, etc)
```
1. Ensure external MySQL database
2. Run: php admin/setup_rbac.php (once)
3. Set admin credentials in .env
4. Deploy code to Vercel
5. Users log in with their credentials
```

## ✅ Verification Checklist

After setup, verify everything works:

- [ ] Admin user can log in
- [ ] Can access User Management
- [ ] Can access Roles & Permissions
- [ ] Can create new user
- [ ] Can assign role to user
- [ ] Can configure role permissions
- [ ] New user can log in
- [ ] New user sees only allowed menus
- [ ] New user cannot access denied menus

## 🎉 You're Ready!

The system is fully functional and ready to use. Start by:

1. ✅ Running `php admin/setup_rbac.php`
2. ✅ Logging in to admin panel
3. ✅ Creating your first non-admin user
4. ✅ Testing user login with limited access
5. ✅ Customizing roles and permissions as needed

**Questions?** See **RBAC_GUIDE.md** for comprehensive documentation.

---

**Status:** ✅ Production Ready  
**Version:** 1.0  
**Date:** 2026-11-14
