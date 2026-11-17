# Pre Menu - Quick Reference

## Menu Organization

The Pre menu is now organized in 3 levels for better navigation:

### **Event** (Category)
- **Create Event** - Add new events
- **Registration Schedule** - Manage event registration dates
- **Itinerary** - Set up event schedule/itinerary

### **Applicants** (Category)
- **Registration** - View and manage registrations
- **Bulk Upload** - Upload multiple registrations at once
- **Fetch Registrations** - Retrieve registrations

### **Invitation** (Category)
- **Approval** - Approve pending registrations
- **Pass** - Create and manage event passes

---

## User Experience

### Top Menu (Dropdown)
When you hover over "Pre" in the top navigation:
- Three category sections appear
- Each category has a header label
- Menu items are indented under their category
- Clear visual separation between categories

### Sidebar Menu  
On the left side when Pre is selected:
- Shows category headers
- Lists items under each category with indentation
- Easy to scan and find what you need

---

## Technical Details

**Menu Structure Type**: Nested/Hierarchical
**Detection**: Automatic (based on string vs. numeric array keys)
**Styling**: Responsive, works on all devices
**Backward Compatibility**: Yes, all other menus work unchanged

---

## Support for Adding New Items

To add a new item to the Pre menu structure:

```php
'Pre' => [
    'Event' => [
        ['create_event', 'admin/create_event.php', 'Create Event'],
        // Add new item here:
        ['new_item', 'admin/new_item.php', 'New Item Label'],
    ],
    // Or add a new category:
    'NewCategory' => [
        ['item1', 'admin/item1.php', 'Item 1'],
        ['item2', 'admin/item2.php', 'Item 2'],
    ],
]
```

The system will automatically:
- Detect the nested structure
- Render category labels
- Indent items properly
- Apply correct styling
