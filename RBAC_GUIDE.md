# Role-Based Access Control (RBAC) System Guide

## Overview

The EMS now includes a comprehensive **Role-Based Access Control (RBAC)** system that replaces simple admin/password authentication. This system allows you to:

- ✅ Create multiple user accounts with different roles
- ✅ Define custom roles for different event responsibilities
- ✅ Control which menu items and features each role can access
- ✅ Assign users to specific events
- ✅ Manage API token access per role

## Quick Start

### 1. Initial Setup

Run the RBAC setup script once:

```bash
php admin/setup_rbac.php
```

This will:
- Create all necessary database tables
- Set up 6 default roles
- Configure default menu items
- Set initial permissions for each role
- Create/migrate your admin user

### 2. Login to Admin Panel

Visit `http://localhost:8000/admin` and log in with:
- **Username/Email:** admin
- **Password:** admin123 (or your custom password from `.env`)

### 3. Create Users & Assign Roles

1. Go to **Administration > User Management**
2. Click **Add New User**
3. Fill in user details and select a role
4. Click **Create User**

### 4. Configure Role Permissions

1. Go to **Administration > Roles & Permissions**
2. Click **Configure** on any role
3. Check/uncheck menu items to allow/deny access
4. Click **Save Permissions**

## Default Roles

### 1. **Admin** 🔐
- **Full access** to all system features
- Cannot be deleted
- System management only

### 2. **Registration Incharge** 📋
- Manages registration approvals
- Bulk upload registrations
- View attendance records
- Generate reports
- *Requires event assignment*

### 3. **Registration Desk** 🗂️
- On-site event check-in
- View attendee list
- Basic attendance viewing
- *Requires event assignment*

### 4. **Stage Manager** 🎬
- Event check-in operations
- Broadcast announcements
- View event schedules
- Manage itineraries
- *Requires event assignment*

### 5. **Coordinator** 📊
- Full event coordination
- Registration management
- Attendance tracking
- Feedback viewing
- Event schedule management
- *Requires event assignment*

### 6. **Event Manager** 👔
- Complete event management
- Event creation and editing
- Registration workflows
- Attendee check-in
- Feedback analysis
- Generate all reports
- *Requires event assignment*

## Creating Custom Roles

### Steps:

1. Go to **Administration > Roles & Permissions**
2. Click **Create Role**
3. Enter role details:
   - **Role Name:** (e.g., "Feedback Analyst")
   - **Description:** What this role does
   - **Event Assignment:** Check if users need to be assigned to specific events
4. Click **Create Role**
5. Click **Configure** on the new role to set permissions
6. Select which menu items this role can access
7. Click **Save Permissions**

### Example Custom Roles:

**Feedback Analyst**
- Access: Feedback management, Feedback analysis, Attendee list
- Event: Required (one event per user)

**Registration Manager**
- Access: Registration management, Approvals, Bulk upload, Attendee list
- Event: Required

**Check-in Only**
- Access: Event check-in, Attendee list, Attendance view
- Event: Required

**Reports Only**
- Access: Attendance view, Reports, Attendee list
- Event: Not required (view all events)

## User Management

### Creating Users

**Admin > User Management > Add New User**

Fields:
- **Full Name:** User's display name
- **Username:** Unique login identifier
- **Email:** Email address (unique)
- **Password:** Temporary password (user should change on first login)
- **Role:** Select from available roles
- **Assigned Event:** (if role requires event) Select specific event

### Editing Users

1. Go to **Administration > User Management**
2. Click **Edit** on any user
3. Update user details
4. Change role or assigned event
5. Click **Update User**

### Deleting Users

1. Go to **Administration > User Management**
2. Click **Delete** on any user
3. Confirm deletion (cannot be undone)

### Important Notes:

- Username and email must be unique
- Password changes can be made by editing user (leave blank to keep current)
- Users must have a role assigned to access the admin panel
- Cannot delete your own user account
- Admin users can assign themselves to events

## Menu Items & Permissions

### Menu Structure

```
Dashboard
├── Events
│   ├── Manage Events
│   ├── Check-in
│   ├── Event Schedules
│   └── Itineraries
├── Registrations
│   ├── Registrations
│   ├── Approvals
│   └── Bulk Upload
├── Attendance
│   ├── Attendee List
│   ├── View Attendance
│   └── Reports
├── Feedback
│   ├── Feedback
│   └── Analysis
├── Event Passes
├── Broadcast Messages
├── Event Playback
└── Administration
    ├── User Management
    ├── Roles & Permissions
    ├── Admin Users
    └── Settings
```

### Configuring Menu Access

1. Go to **Administration > Roles & Permissions**
2. Click **Configure** on a role
3. Check/uncheck menu items
4. Parent items (sections) should have at least one child checked
5. Click **Save Permissions**

## Advanced Features

### Event Assignment

Roles can be configured to require event assignment. This means:

- Users must be assigned to one specific event
- They can only access data for their assigned event
- Useful for event-specific roles like "Stage Manager per Event"

To assign an event:
1. Edit the user
2. Select a role that requires events
3. Choose an event from the dropdown
4. Save

### API Token Support

Each user can generate API tokens for external integrations:

- Tokens inherit the role's permissions
- Tokens are tied to specific events (if role requires)
- Tokens can be revoked at any time

(See API documentation for token management details)

### Access Control Checks

Pages automatically enforce role-based access:

```php
// At the top of any admin page:
requireMenuAccess($pdo, 'menu_key');

// This will:
// 1. Check if user is authenticated
// 2. Check if user's role has access to this menu
// 3. Deny access with 403 if not permitted
```

## Security Considerations

### Best Practices:

1. **Change default passwords** immediately after setup
2. **Use strong passwords** for all user accounts
3. **Regularly audit** user permissions and role assignments
4. **Remove unused roles** to reduce confusion
5. **Assign principle of least privilege** - only grant needed access
6. **Monitor admin activities** in audit logs (when enabled)

### Password Security:

- Passwords are hashed using PHP's `password_hash()` with bcrypt
- Never store plain-text passwords
- Always use HTTPS in production
- Consider two-factor authentication for sensitive accounts

## Database Schema

### Tables Created:

#### `roles`
Stores role definitions and metadata

```sql
role_id INT PRIMARY KEY
role_name VARCHAR(100) UNIQUE
role_description TEXT
event_assigned TINYINT(1)
created_at DATETIME
updated_at DATETIME
```

#### `menu_items`
Stores all available menu items and structure

```sql
menu_id INT PRIMARY KEY
menu_key VARCHAR(100) UNIQUE
menu_label VARCHAR(100)
menu_icon VARCHAR(50)
menu_url VARCHAR(255)
menu_parent_key VARCHAR(100)
menu_order INT
menu_type ENUM('link', 'section')
created_at DATETIME
```

#### `role_menu_permissions`
Maps role -> menu access permissions

```sql
permission_id INT PRIMARY KEY
role_id INT FOREIGN KEY
menu_id INT FOREIGN KEY
can_access TINYINT(1)
created_at DATETIME
UNIQUE(role_id, menu_id)
```

#### `admin_users` (Modified)
Added RBAC columns:

```sql
role_id INT FOREIGN KEY
assigned_event_id INT FOREIGN KEY
```

## API Integration

### Getting User Info

```php
$user = getUserRoleWithPermissions($pdo, $adminUserId);
// Returns: user data + role info + event assignment
```

### Checking Access

```php
// Check if user can access a menu item
if (canUserAccessMenu($pdo, $adminUserId, 'event_checkin')) {
    // User can access check-in
}

// Get user's accessible menus
$menus = getUserAccessibleMenus($pdo, $adminUserId);
// Returns: array of menu items user can access
```

### Getting User Role

```php
$roleName = getAdminUserRoleName($pdo, $adminUserId);
// Returns: "Admin", "Registration Incharge", etc.
```

## Troubleshooting

### User can't see menu item

1. ✓ User is authenticated (logged in)
2. ✓ User has a role assigned
3. ✓ Role has permission for that menu
4. Clear browser cache and refresh

### Setup script fails

- Ensure database connection is working
- Check `.env` file has correct DB credentials
- Ensure tables can be created (sufficient permissions)

### Users can't log in

- Check username/email and password
- Ensure user has a role assigned
- Check database `admin_users` and `roles` tables

### Permissions not updating

- Verify role and menu items exist in database
- Check `role_menu_permissions` table
- Ensure all queries executed successfully

## Functions Reference

### Role Functions

```php
getAllRoles($pdo)
getRoleById($pdo, $roleId)
getRoleByName($pdo, $roleName)
createRole($pdo, $name, $desc, $eventAssigned)
updateRole($pdo, $roleId, $name, $desc, $eventAssigned)
deleteRole($pdo, $roleId)
```

### Menu Functions

```php
getAllMenuItems($pdo)
getMenuItemByKey($pdo, $menuKey)
```

### Permission Functions

```php
getMenuPermissionsForRole($pdo, $roleId)
updateMenuPermission($pdo, $roleId, $menuId, $canAccess)
setDefaultRolePermissions($pdo)
```

### User Role Functions

```php
assignRoleToUser($pdo, $adminUserId, $roleId, $eventId)
removeRoleFromUser($pdo, $adminUserId)
getUserRoleWithPermissions($pdo, $adminUserId)
getUserAccessibleMenus($pdo, $adminUserId)
canUserAccessMenu($pdo, $adminUserId, $menuKey)
getAdminUserRoleName($pdo, $adminUserId)
requireMenuAccess($pdo, $menuKey)
```

## Maintenance

### Adding New Menu Items

Edit `rbac_funcs.php` and update `createDefaultMenuItems()`:

```php
['key' => 'unique_key', 'label' => 'Display Label', 
 'icon' => 'icon-name', 'url' => 'page.php', 
 'parent' => 'parent_key', 'order' => 100, 'type' => 'link'],
```

Then re-run setup:

```bash
php admin/setup_rbac.php
```

### Backing Up Roles & Permissions

Export the tables:

```sql
SELECT * FROM roles;
SELECT * FROM menu_items;
SELECT * FROM role_menu_permissions;
```

### Migration Guide

If upgrading from old system:

1. Run `setup_rbac.php`
2. Existing admin users remain in system
3. Assign roles to existing users via User Management
4. Existing data is preserved

## Support & Documentation

For detailed implementation details, see:
- `admin/rbac_funcs.php` - Core RBAC functions
- `admin/user_management.php` - User CRUD interface
- `admin/role_management.php` - Role & permission configuration
- `admin/setup_rbac.php` - Setup and initialization

## Changelog

### Version 1.0 (Initial Release)
- ✅ Role-based access control
- ✅ 6 default roles
- ✅ Menu-based permission model
- ✅ Event assignment support
- ✅ User management UI
- ✅ Role management UI
- ✅ Permission configuration UI
