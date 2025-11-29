# Filament v4 Namespace Reference

**IMPORTANT**: Always refer to this document when working with Filament components to ensure correct namespace usage.

## Core Namespaces

### Forms (`Filament\Forms\Components`)
```php
use Filament\Forms;

// Form Components
Forms\Components\TextInput
Forms\Components\Textarea
Forms\Components\Select
Forms\Components\MultiSelect
Forms\Components\Checkbox
Forms\Components\CheckboxList
Forms\Components\Radio
Forms\Components\Toggle
Forms\Components\DatePicker
Forms\Components\DateTimePicker
Forms\Components\TimePicker
Forms\Components\ColorPicker
Forms\Components\FileUpload
Forms\Components\RichEditor
Forms\Components\MarkdownEditor
Forms\Components\KeyValue
Forms\Components\Repeater
Forms\Components\Builder
Forms\Components\Hidden
Forms\Components\Placeholder    // ⚠️ DEPRECATED: Use Infolists\Components\TextEntry with state() instead
Forms\Components\Actions\Action

// Form Layout Components
Forms\Components\Section
Forms\Components\Fieldset
Forms\Components\Grid
Forms\Components\Group
Forms\Components\Split
Forms\Components\Tabs
Forms\Components\Wizard
```

### Tables (`Filament\Tables`)
```php
use Filament\Tables;

// Table Columns
Tables\Columns\TextColumn
Tables\Columns\BadgeColumn
Tables\Columns\BooleanColumn
Tables\Columns\CheckboxColumn
Tables\Columns\ColorColumn
Tables\Columns\IconColumn
Tables\Columns\ImageColumn
Tables\Columns\SelectColumn
Tables\Columns\TagsColumn
Tables\Columns\TextInputColumn
Tables\Columns\ToggleColumn
Tables\Columns\ViewColumn

// Table Filters
Tables\Filters\Filter           // Base filter with custom schema
Tables\Filters\SelectFilter     // Dropdown select filter
Tables\Filters\MultiSelectFilter // Multiple selection filter
Tables\Filters\TernaryFilter    // Three-state filter (true/false/blank)
Tables\Filters\TrashedFilter    // Soft delete filter
Tables\Filters\QueryBuilder     // Advanced query builder
Tables\Filters\Indicator        // Filter active indicator (NOT a form field)

// Table Actions
Tables\Actions\Action
Tables\Actions\ActionGroup
Tables\Actions\BulkAction
Tables\Actions\BulkActionGroup
Tables\Actions\CreateAction
Tables\Actions\EditAction
Tables\Actions\DeleteAction
Tables\Actions\DeleteBulkAction
Tables\Actions\ViewAction
Tables\Actions\ReplicateAction
Tables\Actions\RestoreAction
Tables\Actions\RestoreBulkAction
Tables\Actions\ForceDeleteAction
Tables\Actions\ForceDeleteBulkAction

// Table Grouping
Tables\Grouping\Group
```

### Schemas (`Filament\Schemas\Components`)
**CRITICAL**: In resource schemas, use `Schemas\Components` for LAYOUT only, `Forms\Components` for FIELDS
```php
use Filament\Schemas;

// Schema Layout Components (used in resources, widgets, etc.)
Schemas\Components\Section     // ✅ Use for resource form layouts
Schemas\Components\Fieldset    // ✅ Use for resource form layouts
Schemas\Components\Grid        // ✅ Use for resource form layouts
Schemas\Components\Group       // ✅ Use for resource form layouts
Schemas\Components\Split       // ✅ Use for resource form layouts
Schemas\Components\Tabs        // ✅ Use for resource form layouts
Schemas\Components\Wizard      // ✅ Use for resource form layouts

// For form fields inside schemas, use Forms\Components (TextInput, Select, etc.)
```

### Actions (`Filament\Actions`)
```php
use Filament\Actions;

// Page Header Actions (used in resource pages)
Actions\Action
Actions\ActionGroup
Actions\CreateAction
Actions\EditAction
Actions\DeleteAction
Actions\ViewAction
Actions\ReplicateAction
Actions\RestoreAction
Actions\ForceDeleteAction
Actions\ExportAction
Actions\ImportAction
```

### Infolists (`Filament\Infolists\Components`)
```php
use Filament\Infolists;

// Infolist Entries
Infolists\Components\TextEntry
Infolists\Components\BadgeEntry
Infolists\Components\BooleanEntry
Infolists\Components\ColorEntry
Infolists\Components\IconEntry
Infolists\Components\ImageEntry
Infolists\Components\TagsEntry
Infolists\Components\ViewEntry

// Infolist Layout
Infolists\Components\Section
Infolists\Components\Fieldset
Infolists\Components\Grid
Infolists\Components\Group
Infolists\Components\Split
```

### Notifications (`Filament\Notifications`)
```php
use Filament\Notifications\Notification;

Notification::make()
    ->title('Saved successfully')
    ->success()
    ->send();
```

### Support
```php
use Filament\Support\Icons\Heroicon;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Colors\Color;
```

## Common Patterns

### Display-Only Fields (TextEntry vs Placeholder)

**IMPORTANT**: `Placeholder` is deprecated in Filament v4. Use `Infolists\Components\TextEntry` with `state()` method instead.

**WRONG** ❌:
```php
Forms\Components\Placeholder::make('total')
    ->label('Total')
    ->content(fn ($get) => number_format($get('price'), 2))
```

**CORRECT** ✅:
```php
use Filament\Infolists;

Infolists\Components\TextEntry::make('total')
    ->label('Total')
    ->state(fn ($get) => number_format($get('price'), 2))
```

### Repeater/Builder Actions (IMPORTANT!)

**Use `Filament\Actions\Action` for Repeater and Builder component actions:**

```php
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;

Repeater::make('items')
    ->schema([
        // ...
    ])
    ->deleteAction(
        fn (Action $action) => $action->requiresConfirmation()  // ✅ Use Filament\Actions\Action
    )
    ->cloneAction(
        fn (Action $action) => $action->requiresConfirmation()
    )
```

**Note**: Even though these are form components, they use `Filament\Actions\Action`, NOT `Filament\Forms\Components\Actions\Action`.

### Table Filters with Date Range

**WRONG** ❌:
```php
Tables\Filters\Filter::make('order_date')
    ->form([
        Tables\Filters\Indicator::make('from')  // ❌ Indicator is NOT a form field!
            ->label(__('Order Date From'))
            ->date(),
    ])
```

**CORRECT** ✅:
```php
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;

Tables\Filters\Filter::make('order_date')
    ->form([
        DatePicker::make('from')              // ✅ Use Forms\Components\DatePicker
            ->label(__('Order Date From')),
        DatePicker::make('until')
            ->label(__('Order Date Until')),
    ])
    ->query(function ($query, array $data) {
        return $query
            ->when($data['from'], fn ($query, $date) => $query->whereDate('order_date', '>=', $date))
            ->when($data['until'], fn ($query, $date) => $query->whereDate('order_date', '<=', $date));
    })
    ->indicateUsing(function (array $data): array {
        $indicators = [];

        if ($data['from'] ?? null) {
            $indicators[] = Indicator::make(__('Order from: ') . $data['from'])
                ->removeField('from');
        }

        if ($data['until'] ?? null) {
            $indicators[] = Indicator::make(__('Order until: ') . $data['until'])
                ->removeField('until');
        }

        return $indicators;
    })
```

### Resource Page Actions

```php
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;

protected function getHeaderActions(): array
{
    return [
        Action::make('custom_action')
            ->label('Custom Action')
            ->action(fn () => ...),
        DeleteAction::make(),
    ];
}
```

### Table Record Actions

```php
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;

->recordActions([
    ViewAction::make(),
    EditAction::make(),
])
```

### Money Formatting

```php
// In Tables
Tables\Columns\TextColumn::make('price')
    ->money('TRY', divideBy: 100)  // Divides by 100 if stored in cents

// In Infolists
Infolists\Components\TextEntry::make('price')
    ->money('TRY', divideBy: 100)
```

## Key Rules

1. **Filter Form Fields**: Use `Filament\Forms\Components\*`, NOT `Filament\Tables\Filters\*`
2. **Indicator**: `Tables\Filters\Indicator` is ONLY for showing active filter badges, NOT for creating form fields
3. **Resource Schemas (CRITICAL)**:
   - **Layout components** (Section, Tabs, Grid, etc.): Use `Filament\Schemas\Components\*`
   - **Form field components** (TextInput, Select, DatePicker, etc.): Use `Filament\Forms\Components\*`
   - Example:
     ```php
     use Filament\Forms;      // For form fields
     use Filament\Schemas;    // For layout components
     use Filament\Schemas\Schema;

     public static function configure(Schema $schema): Schema
     {
         return $schema->components([
             Schemas\Components\Section::make('Info')  // ✅ Layout
                 ->schema([
                     Forms\Components\TextInput::make('name'),  // ✅ Field
                 ]),
         ]);
     }
     ```
4. **Actions Context** (VERY IMPORTANT):
   - Page header actions: `Filament\Actions\*`
   - Table actions: `Filament\Tables\Actions\*`
   - **Repeater/Builder actions**: `Filament\Actions\Action` (NOT Forms\Components\Actions!)
5. **Always check imports**: Ensure you're using the correct namespace for the context

## Version Info

This reference is for **Filament v4.x**. Always consult the official documentation at https://filamentphp.com/docs for the latest updates.
