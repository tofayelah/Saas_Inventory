<?php

use App\Modules\Crm\Controllers\CrmLeadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| InoManage CRM Routes
|--------------------------------------------------------------------------
|
| Scoped within the authenticated, verified tenant middleware pipeline.
|
*/

Route::prefix('crm')->as('crm.')->group(function () {
    // Leads Management (WP1)
    Route::get('/leads', [CrmLeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/create', [CrmLeadController::class, 'create'])->name('leads.create');
    Route::post('/leads', [CrmLeadController::class, 'store'])->name('leads.store');
    Route::get('/leads/check-duplicates', [CrmLeadController::class, 'checkDuplicates'])->name('leads.check-duplicates');
    Route::get('/leads/{lead}', [CrmLeadController::class, 'show'])->name('leads.show');
    Route::get('/leads/{lead}/edit', [CrmLeadController::class, 'edit'])->name('leads.edit');
    Route::put('/leads/{lead}', [CrmLeadController::class, 'update'])->name('leads.update');
    Route::delete('/leads/{lead}', [CrmLeadController::class, 'destroy'])->name('leads.destroy');
    Route::post('/leads/{lead}/convert', [CrmLeadController::class, 'convert'])->name('leads.convert');
    Route::post('/leads/{lead}/activities', [CrmLeadController::class, 'storeActivity'])->name('leads.activities.store');
    Route::post('/leads/{lead}/activities/{activity}/toggle', [CrmLeadController::class, 'toggleActivity'])->name('leads.activities.toggle');
});
