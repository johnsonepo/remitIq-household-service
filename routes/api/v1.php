<?php

use App\Http\Controllers\Api\V1\Budget\BudgetCategoryController;
use App\Http\Controllers\Api\V1\Budget\BudgetController;
use App\Http\Controllers\Api\V1\Budget\BudgetItemController;
use App\Http\Controllers\Api\V1\Household\HouseholdController;
use App\Http\Controllers\Api\V1\Household\HouseholdInvitationController;
use App\Http\Controllers\Api\V1\Household\HouseholdMemberController;
use App\Http\Controllers\Api\V1\Remittance\RemittanceAnalyticsController;
use App\Http\Controllers\Api\V1\Remittance\RemittanceController;
use App\Http\Controllers\Api\V1\Remittance\TransferProviderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * ============================================================================
 * Public / Health Check Endpoints
 * ============================================================================
 */
Route::get('/', function () {
    return response()->json([
        'service' => config('app.name'),
        'version' => 'v1',
        'status' => 'ok',
    ]);
});

Route::get('/debug', function (Request $request) {
    return response()->json([
        'ip' => $request->ip(),
        'user' => $request->user()?->id,
        'limiter_key' => $request->user()?->id ?: $request->ip(),
    ]);
});

/**
 * ============================================================================
 * Authentication
 * ============================================================================
 */
require __DIR__.'/auth.php';

/**
 * ============================================================================
 * Protected API Routes (Requires Authentication)
 * ============================================================================
 */
Route::middleware('auth:api')->group(function () {

    /**
     * =========================================================================
     * Households
     * =========================================================================
     */

    Route::get('households/invitations', [HouseholdInvitationController::class, 'myInvitations'])
    ->name('households.invitations.mine');

    Route::apiResource('households', HouseholdController::class);

    /**
     * =========================================================================
     * Household Members
     * =========================================================================
     */
    Route::apiResource('households.members', HouseholdMemberController::class)
        ->except(['create', 'edit']);

    /**
     * =========================================================================
     * Household Invitations
     * =========================================================================
     */
    Route::apiResource('households.invitations', HouseholdInvitationController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    Route::post('households/invitations/{token}/accept', [HouseholdInvitationController::class, 'accept'])->name('households.invitations.accept');

    Route::post('households/invitations/{token}/decline', [HouseholdInvitationController::class, 'decline'])->name('households.invitations.decline');

    /**
     * =========================================================================
     * Budget Categories
     * =========================================================================
     *
     * System/default categories are visible to all authenticated users.
     * Custom categories belong to the authenticated user.
     */
    Route::apiResource('budget-categories', BudgetCategoryController::class)
        ->except(['create', 'edit']);

    /**
     * =========================================================================
     * Budgets
     * =========================================================================
     */
    Route::get('budgets/comparison', [BudgetController::class, 'compare'])
        ->name('budgets.comparison');

    Route::get('budgets/history/{householdId}', [BudgetController::class, 'history'])
        ->name('budgets.history');

    Route::apiResource('budgets', BudgetController::class)
        ->except(['create', 'edit']);

    /**
     * =========================================================================
     * Budget Items
     * =========================================================================
     *
     * Budget items belong to a specific budget.
     *
     * The explicit parameter mapping is important because Laravel's
     * nested resource convention would otherwise generate {item}.
     */
    Route::apiResource('budgets.items', BudgetItemController::class)
        ->parameters([
            'items' => 'budgetItem',
        ])
        ->except(['create', 'edit']);

    /**
     * =========================================================================
     * Transfer Providers
     * =========================================================================
     */
    Route::get('transfer-providers', [TransferProviderController::class, 'index'])
        ->name('transfer-providers.index');

    /**
     * =========================================================================
     * Remittances
     * =========================================================================
     *
     * Remittance records belong to the authenticated sender.
     */
    Route::get('remittances/history', [RemittanceController::class, 'history'])
        ->name('remittances.history');

    Route::get('remittances/household/{householdId}', [RemittanceController::class, 'household'])->name('remittances.household');

    Route::get('remittances/analytics', [RemittanceAnalyticsController::class, 'index'])
        ->name('remittances.analytics');

    Route::apiResource('remittances', RemittanceController::class)
        ->whereUuid('remittance')
        ->except(['create', 'edit']);
});
