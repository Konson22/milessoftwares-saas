<?php

use App\Actions\WorkStructure;
use App\Http\Controllers\Work\ListController;
use App\Http\Controllers\Work\ListStatusController;
use App\Http\Controllers\Work\StructureController;
use App\Http\Controllers\Work\WorkTaskController;
use Illuminate\Support\Facades\Route;

/*
| Where work is organized: workspaces, spaces, folders and lists, and one
| list's tasks. Inside {slug}.domain (routes/web.php).
*/

Route::middleware('can:projects.view')->group(function () {
    Route::get('spaces', [StructureController::class, 'index'])->name('structure.index');
    Route::get('lists/{list}', [ListController::class, 'show'])->whereUuid('list')->name('lists.show');
    Route::get('work/tasks/{task}', [WorkTaskController::class, 'show'])->whereUuid('task')->name('work.tasks.show');
});

// Tasks of no project. A project's tasks are in routes/web/work.php.
Route::middleware('can:projects.tasks.manage')->group(function () {
    Route::post('lists/{list}/tasks', [WorkTaskController::class, 'store'])->whereUuid('list')->name('lists.tasks.store');
    Route::prefix('work/tasks/{task}')->whereUuid('task')->scopeBindings()->name('work.tasks.')->group(function () {
        Route::put('/', [WorkTaskController::class, 'update'])->name('update');
        Route::post('move', [WorkTaskController::class, 'move'])->name('move');
        Route::post('comments', [WorkTaskController::class, 'comment'])->name('comments.store');
        Route::post('subtasks', [WorkTaskController::class, 'addSubtask'])->name('subtasks.store');
        Route::post('checklist', [WorkTaskController::class, 'addChecklistItem'])->name('checklist.store');
        Route::post('checklist/{checklistItem}/toggle', [WorkTaskController::class, 'toggleChecklistItem'])->whereUuid('checklistItem')->name('checklist.toggle');
        Route::delete('checklist/{checklistItem}', [WorkTaskController::class, 'removeChecklistItem'])->whereUuid('checklistItem')->name('checklist.destroy');
    });
});

Route::prefix('structure')->middleware('can:work.structure.manage')->name('structure.')->group(function () {
    Route::post('workspaces', [StructureController::class, 'storeWorkspace'])->name('workspaces.store');
    Route::post('spaces', [StructureController::class, 'storeSpace'])->name('spaces.store');
    Route::post('folders', [StructureController::class, 'storeFolder'])->name('folders.store');
    Route::post('lists', [StructureController::class, 'storeList'])->name('lists.store');
    Route::prefix('lists/{list}/statuses')->whereUuid('list')->scopeBindings()->name('lists.statuses.')->group(function () {
        Route::post('/', [ListStatusController::class, 'store'])->name('store');
        Route::put('{status}', [ListStatusController::class, 'update'])->whereUuid('status')->name('update');
        Route::post('{status}/move', [ListStatusController::class, 'move'])->whereUuid('status')->name('move');
        Route::delete('{status}', [ListStatusController::class, 'destroy'])->whereUuid('status')->name('destroy');
    });
    Route::put('{kind}/{id}', [StructureController::class, 'rename'])->whereIn('kind', array_keys(WorkStructure::KINDS))->whereUuid('id')->name('rename');
    Route::post('{kind}/{id}/archive', [StructureController::class, 'archive'])->whereIn('kind', array_keys(WorkStructure::KINDS))->whereUuid('id')->name('archive');
});
