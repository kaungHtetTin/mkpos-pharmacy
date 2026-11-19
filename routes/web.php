<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\PricingRuleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierImportController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\InventoryAdjustmentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ActiveBranchController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ReturnsController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;
use App\Support\Spa;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect()->route('dashboard');
});

/*
|--------------------------------------------------------------------------
| Cashier mobile PWA
|--------------------------------------------------------------------------
|
| This is intentionally public at the shell level. The app authenticates
| against Sanctum's token API and every cashier operation remains protected
| by the existing API permission checks.
*/
Route::get('/cashier/manifest.webmanifest', function () {
    $locale = app()->getLocale();
    $translations = [];
    foreach ($locale === 'en' ? [] : [base_path("lang/{$locale}.json"), base_path("lang/cashier-{$locale}.json")] as $translationFile) {
        if (is_file($translationFile)) {
            $translations = array_replace($translations, json_decode(file_get_contents($translationFile), true) ?: []);
        }
    }
    $translate = static fn (string $text): string => $translations[$text] ?? $text;

    return response()->json([
        'id' => url('/cashier/'),
        'name' => config('app.name', 'Pharmacy POS').' '.$translate('Cashier'),
        'short_name' => $translate('Cashier POS'),
        'description' => $translate('Mobile point of sale for pharmacy cashiers.'),
        'start_url' => url('/cashier/'),
        'scope' => url('/cashier/'),
        'display' => 'standalone',
        'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
        'background_color' => '#f4f7f5',
        'theme_color' => '#087f74',
        'orientation' => 'portrait-primary',
        'categories' => ['business', 'medical', 'productivity'],
        'icons' => [
            [
                'src' => url('/pwa/cashier-icon-192.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => url('/pwa/cashier-icon-512.png'),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => url('/pwa/cashier-maskable.svg'),
                'sizes' => 'any',
                'type' => 'image/svg+xml',
                'purpose' => 'maskable',
            ],
        ],
        'shortcuts' => [
            [
                'name' => $translate('New sale'),
                'short_name' => $translate('Sale'),
                'url' => url('/cashier/#sale'),
                'icons' => [[
                    'src' => url('/pwa/cashier-icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ]],
            ],
            [
                'name' => $translate('Sale history'),
                'short_name' => $translate('History'),
                'url' => url('/cashier/#history'),
                'icons' => [[
                    'src' => url('/pwa/cashier-icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ]],
            ],
        ],
    ])->header('Content-Type', 'application/manifest+json');
})->name('cashier-pwa.manifest');

Route::get('/cashier/{path?}', function () {
    $locale = app()->getLocale();
    $translations = [];

    foreach ($locale === 'en' ? [] : [base_path("lang/{$locale}.json"), base_path("lang/cashier-{$locale}.json")] as $translationFile) {
        if (is_file($translationFile)) {
            $translations = array_replace(
                $translations,
                json_decode(file_get_contents($translationFile), true) ?: []
            );
        }
    }
    $translate = static fn (string $text): string => $translations[$text] ?? $text;

    return view('cashier', [
        'cashierPwaConfig' => [
            'appName' => config('app.name', 'Pharmacy POS'),
            'locale' => $locale,
            'translations' => $translations,
            'languageUrl' => route('language.switch'),
            'csrfToken' => csrf_token(),
            'pageTitle' => $translate('Cashier POS').' - '.config('app.name', 'Pharmacy POS'),
            'pageDescription' => $translate('Mobile point of sale for pharmacy cashiers.'),
            'noScriptMessage' => $translate('This cashier app requires JavaScript.'),
            'baseUrl' => rtrim(url('/'), '/'),
            'apiUrl' => rtrim(url('/api'), '/'),
            'serviceWorkerUrl' => url('/cashier-sw.js').'?v='.substr(hash_file('sha256',public_path('cashier-sw.js')),0,16),
            'scopeUrl' => url('/cashier/'),
        ],
    ]);
})->where('path', '^(?!manifest\.webmanifest$).*$')->name('cashier-pwa');

Route::post('/language', [LanguageController::class, 'switch'])->name('language.switch');

// Authentication and application routes share one locale-independent URL space.
require __DIR__.'/auth.php';

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
        $placeholderPage = function (string $title, string $section, string $description, array $actions = []) {
            return function () use ($title, $section, $description, $actions) {
                return Spa::render('Placeholder/Index', [
                    'title' => $title,
                    'section' => $section,
                    'description' => $description,
                    'actions' => $actions,
                ]);
            };
        };

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::get('/roles', [RolesController::class, 'index'])->name('roles.index')->middleware('permission:manage_roles_permissions');
        Route::post('/roles', [RolesController::class, 'store'])->name('roles.store')->middleware('permission:manage_roles_permissions');
        Route::patch('/roles/{role}', [RolesController::class, 'update'])->name('roles.update')->middleware('permission:manage_roles_permissions');
        Route::delete('/roles/{role}', [RolesController::class, 'destroy'])->name('roles.destroy')->middleware('permission:manage_roles_permissions');
        Route::get('/permissions', [PermissionsController::class, 'index'])->name('permissions.index')->middleware('permission:manage_roles_permissions');
        
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index')->middleware('permission:manage_staff');
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store')->middleware('permission:manage_staff');
        Route::post('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update')->middleware('permission:manage_staff');
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy')->middleware('permission:manage_staff');

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index')->middleware('permission:manage_branches');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store')->middleware('permission:manage_branches');
        Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update')->middleware('permission:manage_branches');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy')->middleware('permission:manage_branches');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index')->middleware('permission:manage_products');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store')->middleware('permission:manage_products');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update')->middleware('permission:manage_products');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->middleware('permission:manage_products');

        Route::get('/taxes', [TaxController::class, 'index'])->name('taxes.index')->middleware('permission:manage_products');
        Route::post('/taxes', [TaxController::class, 'store'])->name('taxes.store')->middleware('permission:manage_products');
        Route::patch('/taxes/{tax}', [TaxController::class, 'update'])->name('taxes.update')->middleware('permission:manage_products');
        Route::delete('/taxes/{tax}', [TaxController::class, 'destroy'])->name('taxes.destroy')->middleware('permission:manage_products');

        Route::middleware('permission:manage_products')->group(function () {
            Route::get('/products/import', [ProductImportController::class, 'create'])->name('products.import.create');
            Route::get('/products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
            Route::get('/products/import/unit-prices/template', [ProductImportController::class, 'unitPriceTemplate'])->name('products.import.unit-prices.template');
            Route::post('/products/import', [ProductImportController::class, 'store'])->name('products.import.store');
            Route::post('/products/import/unit-prices', [ProductImportController::class, 'storeUnitPrices'])->name('products.import.unit-prices.store');
        });
        Route::post('/settings/prices/{rule}/preview', [PricingRuleController::class, 'preview'])->name('pricing.preview')->middleware('permission:manage_settings');
        Route::patch('/settings/prices/{rule}', [PricingRuleController::class, 'update'])->name('pricing.update')->middleware('permission:manage_settings');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index')->middleware('permission:manage_products');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create')->middleware('permission:manage_products');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit')->middleware('permission:manage_products');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store')->middleware('permission:manage_products');
        Route::post('/products/{product}', [ProductController::class, 'update'])->name('products.update')->middleware('permission:manage_products');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy')->middleware('permission:manage_products');

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->middleware('permission:manage_suppliers');
        Route::middleware('permission:manage_suppliers')->group(function () {
            Route::get('/suppliers/import', [SupplierImportController::class, 'create'])->name('suppliers.import');
            Route::get('/suppliers/import/template', [SupplierImportController::class, 'template'])->name('suppliers.import.template');
            Route::post('/suppliers/import', [SupplierImportController::class, 'store'])->name('suppliers.import.store');
        });
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show')->middleware('permission:manage_suppliers');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('permission:manage_suppliers');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update')->middleware('permission:manage_suppliers');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy')->middleware('permission:manage_suppliers');
        Route::post('/supplier-payments', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store')->middleware('permission:manage_payments');

        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index')->middleware('permission:manage_purchases');
        Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create')->middleware('permission:manage_purchases');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show')->middleware('permission:manage_purchases');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store')->middleware('permission:manage_purchases');
        Route::patch('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update')->middleware('permission:manage_purchases');
        Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy')->middleware('permission:manage_purchases');

        Route::get('/inventory/adjustments', [InventoryAdjustmentController::class, 'index'])->name('inventory.adjustments.index')->middleware('permission:adjust_stock');
        Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])->name('inventory.adjustments.store')->middleware('permission:adjust_stock');
        Route::get('/inventory/batches/{product}/{branch}', [InventoryAdjustmentController::class, 'getBatches'])->name('inventory.batches.get')->middleware('permission:adjust_stock');
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index')->middleware('permission:manage_inventory');
        
        Route::get('/inventory/transfers', [StockTransferController::class, 'index'])->name('inventory.transfers.index')->middleware('permission:adjust_stock');
        Route::get('/inventory/transfers/create', [StockTransferController::class, 'create'])->name('inventory.transfers.create')->middleware('permission:adjust_stock');
        Route::get('/inventory/transfers/{transfer}', [StockTransferController::class, 'show'])->name('inventory.transfers.show')->middleware('permission:adjust_stock');
        Route::post('/inventory/transfers', [StockTransferController::class, 'store'])->name('inventory.transfers.store')->middleware('permission:adjust_stock');
        Route::get('/inventory/{product}', [InventoryController::class, 'show'])->name('inventory.show')->middleware('permission:manage_inventory');

        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index')->middleware('permission:manage_expenses');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store')->middleware('permission:manage_expenses');
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update')->middleware('permission:manage_expenses');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy')->middleware('permission:manage_expenses');
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index')->middleware('permission:manage_expenses');
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store')->middleware('permission:manage_expenses');
        Route::patch('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update')->middleware('permission:manage_expenses');
        Route::delete('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy')->middleware('permission:manage_expenses');

        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index')->middleware('permission:view_financial_reports');
        Route::get('/reports/expiry', [ReportsController::class, 'expiry'])->name('reports.expiry')->middleware('permission:manage_inventory');
        Route::get('/reports/low-balance', [ReportsController::class, 'lowBalance'])->name('reports.low-balance')->middleware('permission:manage_inventory');
        Route::get('/reports/purchases', [ReportsController::class, 'purchases'])->name('reports.purchases')->middleware('permission:view_financial_reports');
        Route::get('/reports/purchases/suppliers/{supplier}', [ReportsController::class, 'purchaseSupplier'])->name('reports.purchases.supplier')->middleware('permission:view_financial_reports');
        Route::get('/reports/sales-by-customers', [ReportsController::class, 'salesByCustomers'])->name('reports.sales-by-customers')->middleware('permission:view_financial_reports');
        Route::get('/reports/cash-sessions', [ReportsController::class, 'cashSessions'])->name('reports.cash-sessions')->middleware('permission:view_financial_reports');
        Route::get('/finance/sale-representative', [ReportsController::class, 'saleRepresentatives'])->name('finance.sale-representative')->middleware('permission:view_financial_reports');
        Route::get('/sales', [SalesController::class, 'index'])->name('sales.index')->middleware('permission:view_financial_reports');
        Route::get('/sales/{sale}', [SalesController::class, 'show'])->name('sales.show')->middleware('permission:view_financial_reports');
        Route::post('/sales/{sale}/void', [SalesController::class, 'void'])->name('sales.void')->middleware('permission:cancel_transaction');

        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index')->middleware('permission:process_sale');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show')->middleware('permission:process_sale');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store')->middleware('permission:process_sale');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->middleware('permission:process_sale');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->middleware('permission:process_sale');

        Route::get('/returns', [ReturnsController::class, 'index'])->name('returns.index')->middleware('permission:process_sale,manage_payments');
        Route::post('/returns', [ReturnsController::class, 'store'])->name('returns.store')->middleware('permission:process_sale');
        Route::post('/returns/status/{return}', [ReturnsController::class, 'updateStatus'])->name('returns.status')->middleware('permission:approve_returns');
        Route::post('/returns/{return}/settlements', [ReturnsController::class, 'settle'])->name('returns.settle')->middleware('permission:manage_payments');
        Route::get('/returns/lookup/sale', [ReturnsController::class, 'lookupSale'])->name('returns.lookup.sale')->middleware('permission:process_sale');
        Route::get('/returns/lookup/purchase', [ReturnsController::class, 'lookupPurchase'])->name('returns.lookup.purchase')->middleware('permission:process_sale');

        Route::get('/finance/amount-receivable', [FinanceController::class, 'amountReceivable'])->name('finance.amount-receivable')->middleware('permission:manage_payments');
        Route::post('/finance/amount-receivable/{sale}/receive', [FinanceController::class, 'receiveReceivablePayment'])->name('finance.amount-receivable.receive')->middleware('permission:manage_payments');
        Route::get('/finance/outstanding-balance', [FinanceController::class, 'outstandingBalance'])->name('finance.outstanding-balance')->middleware('permission:manage_payments');
        Route::get('/finance/pending-payments', function () {
            return redirect()->route('finance.outstanding-balance', request()->query());
        })->name('finance.pending-payments')->middleware('permission:manage_payments');
        $financeReportRedirect = function () {
            return redirect()->route('reports.index', request()->query());
        };

        Route::get('/finance/profit-report', $financeReportRedirect)->name('finance.profit-report')->middleware('permission:view_financial_reports');
        Route::get('/finance/balance-sheet', $financeReportRedirect)->name('finance.balance-sheet')->middleware('permission:view_financial_reports');

        Route::get('/sale-person/reports', function () {
            return redirect()->route('finance.sale-representative', request()->query());
        })->name('sale-person.reports')->middleware('permission:view_financial_reports');
        Route::get('/sale-person/bonus-calculation', $placeholderPage('Sales Person Bonus Calculation', 'Sale Person', 'Define and review bonus calculations based on sales-person performance.'))->name('sale-person.bonus-calculation')->middleware('permission:view_financial_reports');

        Route::get('/administration', [StaffController::class, 'index'])->name('administration.index')->middleware('permission:manage_staff');

        Route::get('/pos', [PosController::class, 'index'])->name('pos.index')->middleware('permission:process_sale');
        Route::get('/pos/products', [PosController::class, 'products'])->name('pos.products')->middleware('permission:process_sale');
        Route::get('/pos/categories', [PosController::class, 'categories'])->name('pos.categories')->middleware('permission:process_sale');
        Route::get('/pos/catalog', [PosController::class, 'catalog'])->name('pos.catalog')->middleware('permission:process_sale');
        Route::get('/pos/scan', [PosController::class, 'scan'])->name('pos.scan')->middleware('permission:process_sale');
        Route::get('/pos/customers', [PosController::class, 'customers'])->name('pos.customers')->middleware('permission:process_sale');
        Route::post('/pos/customers', [PosController::class, 'storeCustomer'])->name('pos.customers.store')->middleware('permission:process_sale');
        Route::post('/pos/session/open', [PosController::class, 'openSession'])->name('pos.session.open')->middleware('permission:process_sale');
        Route::post('/pos/session/close', [PosController::class, 'closeSession'])->name('pos.session.close')->middleware('permission:process_sale');
        Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout')->middleware('permission:process_sale');

        Route::post('/active-branch', [ActiveBranchController::class, 'update'])->name('active-branch.update');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index')->middleware('permission:manage_settings');
        Route::get('/manual', [ManualController::class, 'index'])->name('manual.index');
        Route::patch('/settings/pos-behavior', [SettingsController::class, 'updatePosBehavior'])->name('settings.pos-behavior.update')->middleware('permission:manage_settings');
        Route::patch('/settings/branch-preferences', [SettingsController::class, 'updateBranchPreferences'])->name('settings.branch-preferences.update')->middleware('permission:manage_settings');
        Route::post('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update')->middleware('permission:manage_settings');
        Route::post('/settings/business-profile', [SettingsController::class, 'updateBusinessProfile'])->name('settings.business-profile.update')->middleware('permission:manage_settings');
        Route::patch('/settings/appearance', [SettingsController::class, 'updateAppearance'])->name('settings.appearance.update')->middleware('permission:manage_settings');
        Route::patch('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update')->middleware('permission:manage_settings');
        Route::patch('/settings/localization', [SettingsController::class, 'updateLocalization'])->name('settings.localization.update')->middleware('permission:manage_settings');
        Route::patch('/settings/labels', [SettingsController::class, 'updateLabels'])->name('settings.labels.update')->middleware('permission:manage_settings');
        Route::post('/settings/invoice', [SettingsController::class, 'updateInvoice'])->name('settings.invoice.update')->middleware('permission:manage_settings');
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index')->middleware('permission:monitor_activity');

        Route::get('/products/labels/print', [ProductController::class, 'printLabels'])->name('products.labels.print')->middleware('permission:manage_products');
});

// Central identity and platform office routes are separate from the tenant workspace.
Route::middleware('auth')->group(function () {
    Route::get('/support', [\App\Http\Controllers\SupportController::class,'index'])->name('support.index');
    Route::post('/support', [\App\Http\Controllers\SupportController::class,'store'])->middleware('throttle:support-create')->name('support.store');
    Route::get('/support/{id}', [\App\Http\Controllers\SupportController::class,'show'])->name('support.show');
    Route::post('/support/{id}/reply', [\App\Http\Controllers\SupportController::class,'reply'])->middleware('throttle:support-actions')->name('support.reply');
    Route::post('/support/{id}/action', [\App\Http\Controllers\SupportController::class,'action'])->middleware('throttle:support-actions')->name('support.action');
    Route::get('/business-export', [\App\Http\Controllers\BusinessExportController::class,'index'])->name('business-export.index');
    Route::post('/business-export', [\App\Http\Controllers\BusinessExportController::class,'download'])->middleware('throttle:business-exports')->name('business-export.download');
    Route::get('/billing', [\App\Http\Controllers\BillingController::class,'index'])->name('billing.index');
    Route::post('/billing/invoices', [\App\Http\Controllers\BillingController::class,'invoice'])->middleware('throttle:10,1')->name('billing.invoices.store');
    Route::post('/billing/invoices/{invoice}/payments', [\App\Http\Controllers\BillingController::class,'submit'])->middleware('throttle:10,1')->name('billing.payments.store');
    Route::post('/billing/invoices/{invoice}/void', [\App\Http\Controllers\BillingController::class,'void'])->name('billing.invoices.void');
    Route::put('/billing/renewal', [\App\Http\Controllers\BillingController::class,'renewal'])->name('billing.renewal');
    Route::get('/billing/payments/{payment}/receipt', [\App\Http\Controllers\BillingController::class,'receipt'])->name('billing.receipt');
    Route::get('/business-members', [\App\Http\Controllers\BusinessController::class,'members'])->name('business-members.index');
    Route::put('/business-members/{user}', [\App\Http\Controllers\BusinessController::class,'updateMember'])->name('business-members.update');
    Route::middleware(['platform.admin',\App\Http\Middleware\OfficeSecurity::class])->prefix('platform')->name('platform.')->group(function () {
        Route::get('/finance', [\App\Http\Controllers\Platform\FinanceController::class,'index'])->name('finance');
        Route::get('/finance/export', [\App\Http\Controllers\Platform\FinanceController::class,'export'])->middleware('throttle:office-finance-export')->name('finance.export');
        Route::post('/finance/entries', [\App\Http\Controllers\Platform\FinanceController::class,'store'])->middleware('throttle:office-finance-write')->name('finance.store');
        Route::post('/finance/entries/{entry}/void', [\App\Http\Controllers\Platform\FinanceController::class,'void'])->name('finance.void');
        Route::put('/finance/receipts/{receipt}/book', [\App\Http\Controllers\Platform\FinanceController::class,'assign'])->name('finance.assign');
        Route::get('/founders', [\App\Http\Controllers\Platform\FinanceController::class,'founders'])->name('founders.index');
        Route::put('/founders', [\App\Http\Controllers\Platform\FinanceController::class,'saveFounders'])->name('founders.save');
        Route::get('/bank-books', [\App\Http\Controllers\Platform\FinanceController::class,'books'])->name('bank-books.index');
        Route::post('/bank-books', [\App\Http\Controllers\Platform\FinanceController::class,'saveBook'])->name('bank-books.store');
        Route::put('/bank-books/{book}', [\App\Http\Controllers\Platform\FinanceController::class,'saveBook'])->name('bank-books.update');
        Route::get('/users', [\App\Http\Controllers\Platform\AccountController::class,'users'])->name('users.index');
        Route::put('/users/{account}', [\App\Http\Controllers\Platform\AccountController::class,'updateUser'])->name('users.update');
        Route::put('/users/{account}/password', [\App\Http\Controllers\Platform\AccountController::class,'passwordUser'])->middleware('throttle:10,1')->name('users.password');
        Route::get('/admins', [\App\Http\Controllers\Platform\AccountController::class,'admins'])->name('admins.index');
        Route::post('/admins', [\App\Http\Controllers\Platform\AccountController::class,'createAdmin'])->name('admins.store');
        Route::put('/admins/{account}', [\App\Http\Controllers\Platform\AccountController::class,'updateAdmin'])->name('admins.update');
        Route::put('/admins/{account}/password', [\App\Http\Controllers\Platform\AccountController::class,'passwordAdmin'])->middleware('throttle:10,1')->name('admins.password');
        Route::get('/calendar', [\App\Http\Controllers\Platform\InsightsController::class,'calendar'])->name('calendar');
        Route::get('/reports', [\App\Http\Controllers\Platform\InsightsController::class,'reports'])->name('reports');
        Route::get('/reports/export', [\App\Http\Controllers\Platform\InsightsController::class,'export'])->middleware('throttle:5,1')->name('reports.export');
        Route::get('/health', [\App\Http\Controllers\Platform\InsightsController::class,'health'])->name('health');
        Route::get('/payment-methods', [\App\Http\Controllers\Platform\PaymentMethodController::class,'index'])->name('payment-methods.index');
        Route::post('/payment-methods', [\App\Http\Controllers\Platform\PaymentMethodController::class,'store'])->name('payment-methods.store');
        Route::put('/payment-methods/{method}', [\App\Http\Controllers\Platform\PaymentMethodController::class,'update'])->name('payment-methods.update');
        Route::post('/businesses/{business}/complimentary', [\App\Http\Controllers\Platform\BusinessBillingController::class,'complimentary'])->middleware('throttle:office-finance-write')->name('businesses.complimentary');
        Route::put('/businesses/{business}/subscription', [\App\Http\Controllers\Platform\BusinessBillingController::class,'renewal'])->middleware('throttle:office-finance-write')->name('businesses.subscription');
        Route::post('/businesses/{business}/invoices', [\App\Http\Controllers\Platform\BusinessBillingController::class,'storeInvoice'])->middleware('throttle:office-finance-write')->name('businesses.invoices.store');
        Route::put('/businesses/{business}/invoices/{invoice}', [\App\Http\Controllers\Platform\BusinessBillingController::class,'updateInvoice'])->middleware('throttle:office-finance-write')->name('businesses.invoices.update');
        Route::post('/businesses/{business}/invoices/{invoice}/void', [\App\Http\Controllers\Platform\BusinessBillingController::class,'voidInvoice'])->middleware('throttle:office-finance-write')->name('businesses.invoices.void');
        Route::post('/businesses/{business}/invoices/{invoice}/payments', [\App\Http\Controllers\Platform\BusinessBillingController::class,'storePayment'])->middleware('throttle:office-finance-write')->name('businesses.payments.store');
        Route::put('/businesses/{business}/payments/{payment}', [\App\Http\Controllers\Platform\BusinessBillingController::class,'updatePayment'])->middleware('throttle:office-finance-write')->name('businesses.payments.update');
        Route::post('/businesses/{business}/payments/{payment}/review', [\App\Http\Controllers\Platform\BusinessBillingController::class,'reviewPayment'])->middleware('throttle:office-finance-write')->name('businesses.payments.review');
        Route::get('/businesses/{business}', [\App\Http\Controllers\Platform\DirectoryController::class,'show'])->name('businesses.show');
        Route::put('/businesses/{business}/details', [\App\Http\Controllers\Platform\DirectoryController::class,'update'])->name('businesses.details');
        Route::post('/businesses/{business}/trial', [\App\Http\Controllers\Platform\DirectoryController::class,'extendTrial'])->name('businesses.trial');
        Route::get('/email',[\App\Http\Controllers\Platform\MailController::class,'index'])->name('email.index');
        Route::put('/email',[\App\Http\Controllers\Platform\MailController::class,'update'])->name('email.update');
        Route::post('/email/test',[\App\Http\Controllers\Platform\MailController::class,'test'])->middleware('throttle:office-mail-test')->name('email.test');
        Route::get('/dashboard', [\App\Http\Controllers\Platform\DashboardController::class,'index'])->name('dashboard');
        Route::get('/audit', [\App\Http\Controllers\Platform\DashboardController::class,'audit'])->name('audit');
        Route::get('/security', [\App\Http\Controllers\Platform\OfficeSecurityController::class,'index'])->name('security.index');
        Route::post('/security/setup', [\App\Http\Controllers\Platform\OfficeSecurityController::class,'setup'])->middleware('throttle:office-mfa')->name('security.setup');
        Route::post('/security/enable', [\App\Http\Controllers\Platform\OfficeSecurityController::class,'enable'])->middleware('throttle:office-mfa')->name('security.enable');
        Route::post('/security/challenge', [\App\Http\Controllers\Platform\OfficeSecurityController::class,'challenge'])->middleware('throttle:office-mfa')->name('security.challenge');
        Route::get('/support', [\App\Http\Controllers\SupportController::class,'index'])->name('support.index');
        Route::get('/support/{id}', [\App\Http\Controllers\SupportController::class,'show'])->name('support.show');
        Route::post('/support/{id}/reply', [\App\Http\Controllers\SupportController::class,'reply'])->middleware('throttle:support-actions')->name('support.reply');
        Route::post('/support/{id}/action', [\App\Http\Controllers\SupportController::class,'action'])->middleware('throttle:support-actions')->name('support.action');
        Route::get('/support/{id}/diagnostics', [\App\Http\Controllers\SupportController::class,'diagnostics'])->middleware('throttle:support-actions')->name('support.diagnostics');
        Route::get('/plans', [\App\Http\Controllers\Platform\BillingController::class,'plans'])->name('plans.index');
        Route::post('/plans', [\App\Http\Controllers\Platform\BillingController::class,'savePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [\App\Http\Controllers\Platform\BillingController::class,'savePlan'])->name('plans.update');
        Route::get('/billing', [\App\Http\Controllers\Platform\BillingController::class,'index'])->name('billing.index');
        Route::get('/billing/business-search', [\App\Http\Controllers\Platform\BillingController::class,'businesses'])->name('billing.businesses');
        Route::post('/billing/invoices', [\App\Http\Controllers\Platform\BillingController::class,'invoice'])->name('billing.invoices.store');
        Route::post('/billing/invoices/{invoice}/void', [\App\Http\Controllers\Platform\BillingController::class,'void'])->name('billing.invoices.void');
        Route::post('/billing/payments/{payment}/review', [\App\Http\Controllers\Platform\BillingController::class,'review'])->name('billing.payments.review');
        Route::get('/billing/payments/{payment}/receipt', [\App\Http\Controllers\BillingController::class,'receipt'])->name('billing.receipt');
        Route::put('/billing/settings', [\App\Http\Controllers\Platform\BillingController::class,'settings'])->name('billing.settings');
        Route::get('/businesses', [\App\Http\Controllers\Platform\BusinessController::class,'index'])->name('businesses.index');
        Route::post('/businesses', [\App\Http\Controllers\Platform\BusinessController::class,'store'])->name('businesses.store');
        Route::put('/businesses/{business}', [\App\Http\Controllers\Platform\BusinessController::class,'update'])->name('businesses.update');
    });
});

Route::get('/business-media/{business}/{path}', [\App\Http\Controllers\BusinessFileController::class,'show'])->where('path','.*')->middleware('signed')->name('business-files.signed');
Route::get('/storage/businesses/{business}/{path}', [\App\Http\Controllers\BusinessFileController::class,'show'])->where('path','.*')->middleware('auth')->name('business-files.show');
