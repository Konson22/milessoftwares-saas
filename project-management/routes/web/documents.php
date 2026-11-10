<?php

use App\Http\Controllers\Documents\DocumentController;
use Illuminate\Support\Facades\Route;

/*
| The organization's documents. Inside an organization (routes/web.php).
| DocumentController keeps confidential and archived ones from anyone without
| documents.manage.
*/

Route::middleware('can:documents.view')->group(function () {
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->whereUuid('document')->name('documents.show');
    Route::get('documents/{document}/versions/{version}', [DocumentController::class, 'download'])
        ->whereUuid(['document', 'version'])->scopeBindings()->name('documents.versions.download');
});

Route::middleware('can:documents.manage')->group(function () {
    Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:60,1')->name('documents.store');
    Route::put('documents/{document}', [DocumentController::class, 'update'])->whereUuid('document')->name('documents.update');
    Route::post('documents/{document}/versions', [DocumentController::class, 'addVersion'])->whereUuid('document')->middleware('throttle:60,1')->name('documents.versions.store');
    Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->whereUuid('document')->name('documents.archive');
    Route::post('documents/{document}/restore', [DocumentController::class, 'restore'])->whereUuid('document')->name('documents.restore');
});
