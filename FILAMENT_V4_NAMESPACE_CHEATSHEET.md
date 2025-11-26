# Filament v4 Namespace Cheatsheet

## ⚠️ CRITICAL: Always Use Correct Namespaces!

In Filament v4, components are split into different namespaces based on their purpose. **Using the wrong namespace will cause "Class not found" errors.**

---

## Layout Components (Schema Components)

These are used for organizing your form/schema structure:

```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Split;
use Filament\Schemas\Components\Component;
```

### ❌ WRONG
```php
use Filament\Forms\Components\Section;  // ❌ Will cause "Class not found"
use Filament\Forms\Components\Grid;     // ❌ Will cause "Class not found"
use Filament\Forms\Components\Tabs;     // ❌ Will cause "Class not found"
```

### ✅ CORRECT
```php
use Filament\Schemas\Components\Section;  // ✅
use Filament\Schemas\Components\Grid;     // ✅
use Filament\Schemas\Components\Tabs;     // ✅
```

---

## Form Field Components

These are the actual input fields:

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Hidden;
```

---

## Table Components

### Table Columns
```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\SelectColumn;
```

### Table Filters
```php
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
```

---

## Action Components

### For Pages and Resources
```php
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
```

### For Tables (recordActions)
```php
// In table configuration classes, for recordActions use:
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ActionGroup;
```

---

## Infolist Components

```php
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\BadgeEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
```

---

## Quick Reference Table

| Component Type | Namespace | Example |
|---|---|---|
| Layout/Structure | `Filament\Schemas\Components\` | Section, Grid, Tabs, Fieldset |
| Form Fields | `Filament\Forms\Components\` | TextInput, Select, Repeater |
| Table Columns | `Filament\Tables\Columns\` | TextColumn, IconColumn |
| Table Filters | `Filament\Tables\Filters\` | SelectFilter, TrashedFilter |
| Page Actions | `Filament\Actions\` | CreateAction, EditAction, DeleteAction |
| Table Record Actions | `Filament\Tables\Actions\` | EditAction, DeleteAction |
| Infolist Entries | `Filament\Infolists\Components\` | TextEntry, Section |

---

## Common Mistakes to Avoid

### ❌ Mistake #1: Using Forms namespace for layout components
```php
use Filament\Forms\Components\Section;  // ❌ Wrong!
use Filament\Forms\Components\Grid;     // ❌ Wrong!
```

**Fix:**
```php
use Filament\Schemas\Components\Section;  // ✅ Correct
use Filament\Schemas\Components\Grid;     // ✅ Correct
```

### ❌ Mistake #2: Using wrong Actions namespace
```php
// In ManageRelatedRecords or Page classes:
use Filament\Tables\Actions\CreateAction;  // ❌ Wrong!
use Filament\Tables\Actions\EditAction;    // ❌ Wrong!
```

**Fix:**
```php
// In ManageRelatedRecords or Page classes:
use Filament\Actions\CreateAction;  // ✅ Correct
use Filament\Actions\EditAction;    // ✅ Correct
```

### ❌ Mistake #3: Mixing up table actions
```php
// In table configuration classes, in recordActions:
use Filament\Actions\EditAction;  // ❌ Wrong for recordActions!
```

**Fix:**
```php
// In table configuration classes, in recordActions:
use Filament\Tables\Actions\EditAction;  // ✅ Correct for recordActions
```

---

## How to Remember

1. **Layout/Structure = Schemas namespace**
   - If it organizes other components → `Filament\Schemas\Components\`
   - Examples: Section, Grid, Tabs, Fieldset

2. **Input Fields = Forms namespace**
   - If users type/select into it → `Filament\Forms\Components\`
   - Examples: TextInput, Select, Repeater

3. **Table Display = Tables namespace**
   - If it displays data in a table → `Filament\Tables\Columns\`
   - Examples: TextColumn, IconColumn

4. **Actions depend on context:**
   - Page/Resource headerActions → `Filament\Actions\`
   - Table recordActions → `Filament\Tables\Actions\`

---

## Before You Code

**Always check the Filament v4 documentation** in `docs/filamentphp-v4/` to verify the correct namespace!

**Quick grep command to find examples:**
```bash
grep -r "use Filament.*ComponentName" docs/filamentphp-v4/
```

Example:
```bash
# Find the correct namespace for Section:
grep -r "use Filament.*Section" docs/filamentphp-v4/

# Result: use Filament\Schemas\Components\Section;
```

---

## Testing Your Code

If you get a "Class not found" error:
1. Check the namespace of the component
2. Verify it matches this cheatsheet
3. Run `vendor/bin/pint` to format your code
4. Test in the browser

---

**Remember:** Filament v4 split components into logical namespaces. Using the wrong one will break your code!
