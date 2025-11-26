# Filament v4 Guidelines

## CRITICAL: Correct Component Namespaces

### Schema Layout Components
```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
```

### Form Field Components
```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\MarkdownEditor;
```

### Table Components
```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
```

## Critical Namespace Rules for Actions

### Action Namespaces - MOST IMPORTANT RULE

**For ManageRelatedRecords Pages and Resource Pages:**
```php
use Filament\Actions; // ✅ CORRECT

protected function getHeaderActions(): array
{
    return [
        Actions\CreateAction::make(),
        Actions\EditAction::make(),
        Actions\DeleteAction::make(),
    ];
}

public function table(Table $table): Table
{
    return $table
        ->headerActions([
            Actions\CreateAction::make(), // ✅ CORRECT
        ])
        ->actions([
            Actions\EditAction::make(),    // ✅ CORRECT
            Actions\DeleteAction::make(),  // ✅ CORRECT
        ])
        ->bulkActions([
            Actions\BulkActionGroup::make([
                Actions\DeleteBulkAction::make(), // ✅ CORRECT
            ]),
        ]);
}
```

**For Table Configuration Classes:**
```php
use Filament\Tables\Actions; // ✅ CORRECT for recordActions only

public static function configure(Table $table): Table
{
    return $table
        ->recordActions([
            Actions\EditAction::make(),    // ✅ CORRECT
            Actions\DeleteAction::make(),  // ✅ CORRECT
        ])
        ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(), // ✅ CORRECT
            ]),
        ]);
}
```

### Never Mix These Namespaces

❌ **WRONG - Never do this:**
```php
// In ManageRelatedRecords or Page classes
use Filament\Tables\Actions\EditAction; // ❌ WRONG
use Filament\Tables\Actions\BulkActionGroup; // ❌ WRONG
```

✅ **CORRECT - Always do this:**
```php
// In ManageRelatedRecords or Page classes
use Filament\Actions; // ✅ CORRECT
```

---

## Pages and Widgets

### Custom Page Structure

```php
use Filament\Resources\Pages\Page;

class ManageProductVariants extends Page
{
    protected static string $resource = ProductResource::class;

    // Non-static view property
    protected string $view = 'filament.resources.product.products.pages.manage-product-variants';

    public static function getNavigationLabel(): string
    {
        return __('Variants');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProductOptionsWidget::class,
        ];
    }

    protected function getHeaderWidgetsColumns(): int | array
    {
        return [
            'md' => 2,
            'xl' => 3,
        ];
    }
}
```

### Page View Template

```blade
<x-filament-panels::page>
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="$this->getHeaderWidgetsColumns()"
    />
</x-filament-panels::page>
```

### Widget with Record Data

**Widget Class:**
```php
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ProductOptionsWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected string $view = 'filament.widgets.product-options-widget';

    public ?Model $record = null;

    protected static bool $isLazy = false;

    public function mount(?Model $record = null): void
    {
        $this->record = $record;

        if ($this->record) {
            $this->loadData();
        }
    }
}
```

**Passing Data to Widget from Page:**
```php
protected function getHeaderWidgetsData(): array
{
    return [
        'record' => $this->record,
    ];
}
```

---

## ManageRelatedRecords

### Basic Structure

```php
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;

class ManageProductVariants extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;
    protected static string $relationship = 'variants';

    public static function getNavigationLabel(): string
    {
        return __('Variants');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                Tables\Columns\TextColumn::make('sku'),
                Tables\Columns\TextColumn::make('price')
                    ->money('TRY'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->form([
                        Forms\Components\TextInput::make('sku')
                            ->required(),
                        Forms\Components\TextInput::make('price')
                            ->numeric()
                            ->required(),
                    ]),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->form([
                        Forms\Components\TextInput::make('sku')
                            ->required(),
                        Forms\Components\TextInput::make('price')
                            ->numeric()
                            ->required(),
                    ]),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

### Registering in Resource

```php
public static function getPages(): array
{
    return [
        'index' => Pages\ListProducts::route('/'),
        'create' => Pages\CreateProduct::route('/create'),
        'edit' => Pages\EditProduct::route('/{record}/edit'),
        'variants' => Pages\ManageProductVariants::route('/{record}/variants'),
    ];
}
```

---

## Forms and Actions

### Form Actions in Modal

```php
Actions\CreateAction::make()
    ->form([
        Forms\Components\Grid::make(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('type')
                ->label(__('Type'))
                ->options([
                    'option1' => __('Option 1'),
                    'option2' => __('Option 2'),
                ])
                ->required(),
        ]),
    ])
```

### Standalone Action

```php
public function deleteAction(): Action
{
    return Action::make('delete')
        ->color('danger')
        ->requiresConfirmation()
        ->action(fn () => $this->record->delete());
}
```

**Render in Blade:**
```blade
<div>
    {{ $this->deleteAction }}

    <x-filament-actions::modals />
</div>
```

---

## Table Configuration

### Table Actions Types

1. **headerActions** - Actions in table header (Create, Import, etc.)
2. **actions** (or recordActions) - Row-level actions (Edit, Delete, View)
3. **bulkActions** - Actions on selected records

```php
public function table(Table $table): Table
{
    return $table
        ->columns([
            Tables\Columns\TextColumn::make('name'),
            Tables\Columns\TextColumn::make('email'),
        ])
        ->headerActions([
            Actions\CreateAction::make(),
        ])
        ->actions([
            Actions\EditAction::make(),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ])
        ->bulkActions([
            Actions\BulkActionGroup::make([
                Actions\DeleteBulkAction::make(),
            ]),
        ]);
}
```

### Action Groups

```php
use Filament\Actions\ActionGroup;

->actions([
    ActionGroup::make([
        Actions\ViewAction::make(),
        Actions\EditAction::make(),
        Actions\DeleteAction::make(),
    ]),
])
```

---

## Resource Structure

### Resource Pages Configuration

```php
public static function getPages(): array
{
    return [
        'index' => Pages\ListCustomers::route('/'),
        'create' => Pages\CreateCustomer::route('/create'),
        'view' => Pages\ViewCustomer::route('/{record}'),
        'edit' => Pages\EditCustomer::route('/{record}/edit'),
        'addresses' => Pages\ManageCustomerAddresses::route('/{record}/addresses'),
    ];
}
```

### Sub-Navigation

```php
use Filament\Pages\Enums\SubNavigationPosition;

protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        EditProduct::class,
        ManageProductVariants::class,
        ManageProductInventory::class,
        ManageProductMedia::class,
    ]);
}
```

---

## Widgets on Pages

### Header and Footer Widgets

```php
protected function getHeaderWidgets(): array
{
    return [
        StatsOverviewWidget::class,
        ProductOptionsWidget::class,
    ];
}

protected function getFooterWidgets(): array
{
    return [
        RecentOrdersWidget::class,
    ];
}
```

### Widget Grid Customization

```php
public function getHeaderWidgetsColumns(): int | array
{
    return [
        'md' => 2,
        'xl' => 3,
    ];
}
```

### Passing Properties to Widgets

```php
protected function getHeaderWidgets(): array
{
    return [
        StatsOverviewWidget::make([
            'status' => 'active',
            'dateRange' => '30days',
        ]),
    ];
}
```

**In Widget Class:**
```php
class StatsOverviewWidget extends Widget
{
    public string $status;
    public string $dateRange;

    // Access via $this->status and $this->dateRange
}
```

---

## Livewire Integration

### Form Component Integration

```php
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

class CreatePost extends Component implements HasForms, HasSchemas
{
    use InteractsWithForms;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required(),
                MarkdownEditor::make('content'),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $data = $this->form->getState();
        Post::create($data);
    }

    public function render(): View
    {
        return view('livewire.create-post');
    }
}
```

### Action Component Integration

```php
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Livewire\Component;

class ManagePost extends Component implements HasActions
{
    use InteractsWithActions;

    public Post $post;

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->requiresConfirmation()
            ->action(fn () => $this->post->delete());
    }
}
```

---

## Common Patterns

### Grid Layout in Forms

```php
Forms\Components\Grid::make(2)->schema([
    Forms\Components\TextInput::make('first_name'),
    Forms\Components\TextInput::make('last_name'),
]),
Forms\Components\Grid::make(3)->schema([
    Forms\Components\TextInput::make('city'),
    Forms\Components\TextInput::make('state'),
    Forms\Components\TextInput::make('zip'),
]),
```

### Responsive Grid

```php
Forms\Components\Grid::make([
    'default' => 1,
    'sm' => 2,
    'md' => 3,
    'lg' => 4,
])->schema([
    // Components
]),
```

### Table Column Formatting

```php
Tables\Columns\TextColumn::make('price')
    ->money('TRY')
    ->sortable(),

Tables\Columns\TextColumn::make('created_at')
    ->dateTime()
    ->sortable()
    ->toggleable(isToggledHiddenByDefault: true),

Tables\Columns\TextColumn::make('status')
    ->badge()
    ->color(fn (string $state): string => match ($state) {
        'draft' => 'gray',
        'published' => 'success',
        'archived' => 'danger',
    }),
```

### Translation Support

```php
->label(__('Name'))
->options([
    'footwear' => __('Footwear'),
    'clothing' => __('Clothing'),
])
->formatStateUsing(fn ($state) => __($state))
```

---

## Testing

### Testing Resource Pages

```php
use function Pest\Livewire\livewire;

it('can create a product', function () {
    livewire(CreateProduct::class)
        ->fillForm([
            'title' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('sku', 'TEST-001'))->toExist();
});
```

### Testing Actions

```php
it('can delete a product', function () {
    $product = Product::factory()->create();

    livewire(EditProduct::class, ['record' => $product->id])
        ->callAction('delete')
        ->assertSuccessful();

    expect(Product::find($product->id))->toBeNull();
});
```

### Testing Form Validation

```php
it('validates required fields', function () {
    livewire(CreateProduct::class)
        ->fillForm([
            'title' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['title' => 'required']);
});
```

---

## Performance Optimization

### Caching for Production

```bash
# Cache components and icons
php artisan filament:optimize

# Clear cache
php artisan filament:optimize-clear
```

### Lazy Loading Widgets

```php
class StatsOverviewWidget extends Widget
{
    protected static bool $isLazy = true;
}
```

### Default Key Sorting

```php
// Disable if table doesn't have primary key
public function table(Table $table): Table
{
    return $table
        ->defaultKeySort(false);
}
```

---

## Code Organization Tips

### Using Component Classes

**Component Class:**
```php
namespace App\Filament\Resources\Products\Components;

use Filament\Forms\Components\TextInput;

class ProductSKUInput
{
    public static function make(): TextInput
    {
        return TextInput::make('sku')
            ->label(__('SKU'))
            ->required()
            ->unique(ignoreRecord: true)
            ->maxLength(255)
            ->helperText(__('Unique product identifier'));
    }
}
```

**Usage:**
```php
use App\Filament\Resources\Products\Components\ProductSKUInput;

Forms\Components\Grid::make(2)->schema([
    ProductSKUInput::make(),
    // Other components
])
```

### Using Action Classes

```php
namespace App\Filament\Resources\Customers\Actions;

use Filament\Actions\Action;

class EmailCustomerAction
{
    public static function make(): Action
    {
        return Action::make('email')
            ->icon(Heroicon::Envelope)
            ->schema([
                TextInput::make('subject')->required(),
                Textarea::make('body')->required(),
            ])
            ->action(function (Customer $customer, array $data) {
                // Send email
            });
    }
}
```

---

## Important Notes

1. **Always use `Filament\Actions` namespace** in ManageRelatedRecords and Page classes
2. **Never use `Filament\Tables\Actions`** in ManageRelatedRecords (except for recordActions in table config classes)
3. **Page `$view` property is NOT static** - use `protected string $view`
4. **Always call `$this->form->fill()` in `mount()`** for forms
5. **Use `$this->form->getState()` to get validated form data**, not `$this->data` directly
6. **Include `<x-filament-actions::modals />`** in views that use actions
7. **Run `vendor/bin/pint` to format code** according to Laravel conventions
8. **Use translation helpers `__()`** for all user-facing text
9. **Widgets can receive data** via `mount(?Model $record = null)`
10. **Test after making changes** - use `php artisan route:list` to verify routes

---

## Quick Reference Checklist

- [ ] Using correct action namespace (`Filament\Actions` for pages)
- [ ] Page `$view` is non-static
- [ ] Form calls `fill()` in `mount()`
- [ ] Form uses `getState()` to retrieve data
- [ ] Actions blade includes `<x-filament-actions::modals />`
- [ ] All text uses `__()` for translations
- [ ] Code formatted with Pint
- [ ] Routes registered in `getPages()`
- [ ] Widgets properly receive record data
- [ ] Tests cover main functionality
