# URL Query String Encoding Fix

## Problem
When accessing admin pages with query parameters (e.g., `admin_users.php?edit=2`), the URL was being malformed to `admin_users.php%3Fedit%3D2`, causing 403 Forbidden errors or other unexpected behavior. The query string was being URL-encoded as part of the path instead of being preserved as a query string.

## Root Cause
The `buildUrl()` function in `config/database.php` was URL-encoding ALL path segments without distinguishing between the path and query string. When a query string was passed (e.g., `admin/admin_users.php?edit=2`), the `?` and `=` characters were being `rawurlencode()`d as part of the path segments, resulting in `admin/admin_users.php%3Fedit%3D2`.

## Solution
Updated the `buildUrl()` function to:
1. Detect and separate the query string from the path using the `?` delimiter
2. URL-encode only the path segments
3. Preserve the query string as-is (unencoded)
4. Concatenate the encoded path with the query string

### File Modified
**config/database.php** - Updated `buildUrl()` function

#### Before
```php
function buildUrl(string $path = ''): string
{
    $basePath = rtrim(getAppBasePath(), '/');
    $normalizedPath = '/' . ltrim($path, '/');

    if ($basePath === '') {
        return $normalizedPath;
    }

    $segments = array_map(static fn (string $segment): string => rawurlencode($segment), array_filter(explode('/', trim($normalizedPath, '/')), ...));
    $encodedPath = '/' . implode('/', $segments);

    return $basePath . $encodedPath;
}
```

#### After
```php
function buildUrl(string $path = ''): string
{
    $basePath = rtrim(getAppBasePath(), '/');
    $normalizedPath = '/' . ltrim($path, '/');

    if ($basePath === '') {
        return $normalizedPath;
    }

    // Separate path from query string
    $pathOnly = $normalizedPath;
    $queryString = '';
    if (strpos($normalizedPath, '?') !== false) {
        [$pathOnly, $queryString] = explode('?', $normalizedPath, 2);
        $queryString = '?' . $queryString;
    }

    $segments = array_map(static fn (string $segment): string => rawurlencode($segment), array_filter(explode('/', trim($pathOnly, '/')), ...));
    $encodedPath = '/' . implode('/', $segments);

    return $basePath . $encodedPath . $queryString;
}
```

## Impact
This fix resolves issues with:
- Edit links in admin pages (e.g., `admin_users.php?edit=2`)
- Any page that uses query parameters for filtering, sorting, or editing
- All admin pages that use `buildUrl()` with query strings

## Examples
Before and after:
- `buildUrl('admin/admin_users.php?edit=2')` 
  - Before: `admin/admin_users.php%3Fedit%3D2` ❌
  - After: `admin/admin_users.php?edit=2` ✓

- `buildUrl('admin/events.php?sort=name&order=asc')`
  - Before: `admin/events.php%3Fsort%3Dname%26order%3Dasc` ❌
  - After: `admin/events.php?sort=name&order=asc` ✓

## Testing
PHP syntax verification: ✓ No errors detected

## Related Changes
- Also updated [admin/admin_users.php](/C:/wamp64/www/ems/admin/admin_users.php) to include RBAC setup (added `rbac_funcs.php` require and `ensureMissingMenuItemsExist()` call)
- This prevents any 403 Forbidden errors from missing menu item registrations
