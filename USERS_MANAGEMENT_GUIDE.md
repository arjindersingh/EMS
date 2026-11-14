# Users Management Module

## Overview
The Users Management module is a comprehensive admin interface for managing user accounts, roles, and permissions in the EMS system. It provides three integrated sections accessible from the "Users" menu in the Administration dropdown.

**URL:** `http://localhost:8000/admin/users.php`

---

## Features

### 1. 📝 Create User Tab
Create and manage user accounts with the following capabilities:

**Create New Users:**
- Full Name (required)
- Email (required, must be unique)
- Username (required, must be unique)
- Password (required for new users, optional when updating)
- Assign Role (optional)
- Assign Event (optional, for role-specific event scoping)

**Manage Existing Users:**
- View all users in a table format
- Edit user details and role assignments
- Delete users with confirmation
- Bulk operations ready

**User Details Displayed:**
- Username
- Email
- Full Name
- Assigned Role
- Assigned Event

---

### 2. 👥 User Roles Tab
Create and manage user roles that define access levels:

**Create New Roles:**
- Role Name (required)
- Description (optional)
- Event Assignment Toggle (whether this role can be scoped to specific events)

**Manage Existing Roles:**
- View all system roles
- Edit role names and descriptions
- Access quick "Permissions" link for each role
- Delete roles (removes associated permissions automatically)

**Default Roles:**
- Admin (full access to all menus)
- Event Manager (manages events and registrations)
- Coordinator (coordinates events and registrations)
- Registration Incharge (manages registration process)
- Stage Manager (manages event staging/operations)
- Registration Desk (frontend registration operations)

---

### 3. 🔒 Permission Settings Tab
Configure menu item access for each role using a visual checkbox grid:

**Permission Configuration:**
- Select a role to configure
- View all 25 available menu items in a grid format
- Check/uncheck menu items to grant/revoke access
- Save permissions with confirmation

**What it Controls:**
- Which menu items appear in the sidebar for users with a specific role
- Menu-level access control (not action-level)
- Hierarchical menu structure support

**Menu Categories:**
- Administration (users, settings, registration options)
- Pre-Event (create events, schedules, registration)
- On-Event (check-in, attendance tracking)
- Post-Event (reports, feedback, analysis)

---

## Database Schema

### Related Tables:
1. **admin_users** - User accounts with role and event assignment
2. **roles** - Role definitions with event scoping flags
3. **menu_items** - Available menu items in the system (25 items)
4. **role_menu_permissions** - Role-menu access matrix

---

## Integration with RBAC System

This module fully integrates with the existing RBAC (Role-Based Access Control) system:

**Functions Used:**
- `getAllAdminUsers()` - Retrieve all users
- `getAdminUserById()` - Get specific user details
- `createAdminUser()` - Create new account
- `updateAdminUser()` - Modify user details
- `deleteAdminUser()` - Remove user account
- `assignRoleToUser()` - Assign role to user
- `assignEventToUser()` - Scope user to specific event

- `getAllRoles()` - Retrieve all roles
- `getRoleById()` - Get role details
- `createRole()` - Create new role
- `updateRole()` - Modify role
- `deleteRole()` - Remove role

- `getAllMenuItems()` - Get available menus
- `getMenuPermissionsForRole()` - Get role permissions
- `updateMenuPermission()` - Update single permission

---

## User Workflow

### Creating a New User:

1. Go to **Users > Create User** tab
2. Fill in user details:
   - Name: "John Coordinator"
   - Email: "john@event.com"
   - Username: "john_coord"
   - Password: (secure password)
3. Select Role: "Coordinator"
4. Select Event: "Annual Fest 2024" (if applicable)
5. Click "➕ Create User"
6. User appears in "Existing Users" list
7. User can now login with credentials

### Assigning Permissions to a Role:

1. Go to **Users > User Roles** tab
2. Locate the role you want to configure
3. Click "Permissions" button
4. In Permission Settings tab, check/uncheck menu items
5. Example: For "Registration Desk" role:
   - Check: Event Check-in, Attendee List, Live Attendance
   - Uncheck: Settings, Admin Users, Reports
6. Click "💾 Save Permissions"
7. Permissions take effect immediately for all users with that role

---

## Security Features

1. **Role-based Authorization:** Each page checks user's assigned role
2. **Password Hashing:** Passwords stored using bcrypt (password_hash)
3. **Session Management:** User authentication persists across pages
4. **SQL Injection Prevention:** All queries use PDO prepared statements
5. **CSRF Protection:** Form submissions validated server-side

---

## Permission Model

The system uses a **menu-based permission model**:

- **Granularity:** Menu item level (not action-level)
- **Assignment:** Roles → Menu Items (25 combinations)
- **Inheritance:** Users inherit all menu permissions of their assigned role
- **Override:** Not applicable (no user-specific overrides)

### Permission Matrix:
```
Roles:      Admin  Manager  Coordinator  Incharge  Stage  Desk
Menu Items: ✓✓✓✓✓  ✓✓✓✓    ✓✓✓✓✓✓     ✓✓✓      ✓✓✓   ✓✓
(25 items total, each role has specific subset)
```

---

## Common Tasks

### Change User's Role:
1. Go to **Users > Create User** tab
2. Click "Edit" next to user
3. Change "Role" dropdown
4. Click "💾 Update User"

### Remove User Access:
1. Go to **Users > Create User** tab
2. Click "Delete" next to user
3. Confirm deletion

### Create a New Role with Custom Permissions:
1. Go to **Users > User Roles** tab
2. Fill in role name: "Event Reviewer"
3. Add description: "Can view events and feedback only"
4. Click "➕ Create Role"
5. Click "Permissions" on the new role
6. Select only: Event Reports, Feedback View
7. Click "💾 Save Permissions"

### Restrict a User to Specific Event:
1. Go to **Users > Create User** tab
2. Click "Edit" next to user
3. Select "Assigned Event": "Summer Camp 2024"
4. Click "💾 Update User"
5. User's role can now scope to this event

---

## API Endpoints

All operations are form-based (POST method) with these actions:

- `action=create_user` - Create new user account
- `action=update_user` - Modify existing user
- `action=delete_user` - Remove user account
- `action=create_role` - Create new role
- `action=update_role` - Modify role
- `action=delete_role` - Remove role
- `action=update_permissions` - Configure role menu access

---

## Error Handling

The system validates all inputs and provides user-friendly error messages:

- ✓ Success: Green alert with confirmation message
- ✗ Error: Red alert with specific error details
- ⚠ Warning: Confirmation dialog for destructive actions (delete)

---

## Notes

- **Event Assignment:** Optional per role; allows multi-tenancy for users
- **Role Deletion:** Cascading delete removes associated permissions
- **Permission Updates:** Take effect immediately (no cache)
- **User Deactivation:** Delete user or remove role assignment
- **Bulk Operations:** Can be added in future versions

---

## Files Involved

- `admin/users.php` - Main Users Management page
- `admin/rbac_funcs.php` - Core RBAC functions
- `admin/auth_funcs.php` - User authentication
- `admin/layout.php` - Admin layout template
- Database: `roles`, `menu_items`, `role_menu_permissions`, `admin_users` tables

---

## Session Initialization

The Users page requires admin authentication. Users must first login via:
- **Email or Username:** ihce@ihgi.in or ihce
- **Password:** Ihgi@2020 (or other assigned password)

Login redirects to admin dashboard, where "Users" menu is available.
