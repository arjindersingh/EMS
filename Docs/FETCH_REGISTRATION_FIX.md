# Fix for Fetch Registration 403 Forbidden Error

## Problem
When accessing `http://localhost/ems/admin/fetch_registration.php` and selecting an event, users were getting a "Forbidden - You don't have permission to access this resource" error (HTTP 403).

## Root Cause
The `fetch_registration` page was referenced in the navigation menu (`layout.php`), but the corresponding menu item was not:
1. Registered in the `menu_items` database table
2. Configured with role-based permissions in the `role_menu_permissions` table

This caused the role-based access control system to deny access to the page.

## Solution
Added the `fetch_registration` menu item to the RBAC (Role-Based Access Control) system configuration:

### Files Modified

#### 1. **admin/rbac_funcs.php**
   - **Added** `fetch_registration` menu item to `createDefaultMenuItems()` function
     - Parent: `registration_section`
     - Order: 24 (after `registration_bulk_upload`)
     - Icon: `icon-download`
   
   - **Updated** `determineDefaultMenuAccess()` function to grant access for:
     - `Registration Incharge` role
     - `Coordinator` role
     - `Event Manager` role
   
   - **Added** new `ensureMissingMenuItemsExist()` function to handle existing installations where the menu item may not yet exist in the database

#### 2. **admin/rbac_init.php**
   - **Updated** `initializeRBACSystem()` to call `ensureMissingMenuItemsExist()` after creating default menu items

#### 3. **admin/index.php**
   - **Added** call to `ensureMissingMenuItemsExist()` in the admin initialization code to ensure menu items are up-to-date for existing installations

#### 4. **admin/fetch_registration.php**
   - **Added** `require_once` for `rbac_funcs.php`
   - **Added** call to `ensureMissingMenuItemsExist()` before processing any page logic to ensure the menu item exists for direct page access

## How It Works

### For New Installations
The menu item is automatically registered and configured when the admin database is first initialized.

### For Existing Installations
The `ensureMissingMenuItemsExist()` function uses `INSERT IGNORE` to add missing menu items without affecting existing ones. This function is called:
1. When the admin dashboard is accessed (`index.php`)
2. When the RBAC system is initialized
3. When the fetch_registration page is accessed directly

### Role Permissions
After the menu item is added, the permission system automatically grants access to users with the following roles:
- **Admin**: Full access (all pages)
- **Registration Incharge**: Can manage registrations, including fetching from Google Sheets
- **Coordinator**: Can manage all registration-related operations
- **Event Manager**: Can manage events and registrations

## Testing
All PHP files were syntax-checked and verified to have no errors:
- `admin/rbac_funcs.php` ✓
- `admin/fetch_registration.php` ✓
- `admin/index.php` ✓
- `admin/rbac_init.php` ✓

## Migration for Existing Databases
Existing installations will automatically get the `fetch_registration` menu item added when:
1. An admin user logs in and accesses the admin dashboard
2. The fetch_registration page is accessed directly
3. The RBAC system is manually reinitialized

No manual database migration is required - the `INSERT IGNORE` statement ensures no duplicate keys are created.
