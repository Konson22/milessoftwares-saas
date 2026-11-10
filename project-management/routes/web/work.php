<?php

use App\Http\Controllers\Work\TaskController;
use App\Http\Controllers\Work\TaskPartsController;
use App\Http\Controllers\Work\TimeController;
use Illuminate\Support\Facades\Route;

/*
| Work: a project's tasks (board, list, timeline) and the time logged on
| it. Inside {slug}.domain/projects/{project} (routes/web/projects.php).
*/

Route::middleware('can:projects.view')->group(function () {
    Route::get('board', [TaskController::class, 'board'])->name('board');
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('timeline', [TaskController::class, 'timeline'])->name('timeline');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->whereUuid('task')->name('tasks.show');
    Route::get('time', [TimeController::class, 'show'])->name('time');
    Route::get('tasks/{task}/attachments/{attachment}', [TaskPartsController::class, 'download'])->whereUuid(['task', 'attachment'])->name('tasks.attachments.show');
});

Route::middleware('can:projects.tasks.manage')->group(function () {
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->whereUuid('task')->name('tasks.update');
    Route::post('tasks/{task}/move', [TaskController::class, 'move'])->whereUuid('task')->name('tasks.move');
    Route::post('tasks/{task}/comments', [TaskController::class, 'comment'])->whereUuid('task')->name('tasks.comments.store');
    Route::post('tasks/{task}/dependencies', [TaskController::class, 'addDependency'])->whereUuid('task')->name('tasks.dependencies.store');
    Route::delete('tasks/{task}/dependencies/{dependency}', [TaskController::class, 'removeDependency'])->whereUuid(['task', 'dependency'])->name('tasks.dependencies.destroy');
    Route::post('tasks/{task}/subtasks', [TaskPartsController::class, 'addSubtask'])->whereUuid('task')->name('tasks.subtasks.store');
    Route::post('tasks/{task}/collaborators', [TaskPartsController::class, 'addCollaborator'])->whereUuid('task')->name('tasks.collaborators.store');
    Route::delete('tasks/{task}/collaborators/{collaborator}', [TaskPartsController::class, 'removeCollaborator'])->whereUuid(['task', 'collaborator'])->name('tasks.collaborators.destroy');
    Route::post('tasks/{task}/checklist', [TaskPartsController::class, 'addChecklistItem'])->whereUuid('task')->name('tasks.checklist.store');
    Route::post('tasks/{task}/checklist/{checklistItem}/toggle', [TaskPartsController::class, 'toggleChecklistItem'])->whereUuid(['task', 'checklistItem'])->name('tasks.checklist.toggle');
    Route::delete('tasks/{task}/checklist/{checklistItem}', [TaskPartsController::class, 'removeChecklistItem'])->whereUuid(['task', 'checklistItem'])->name('tasks.checklist.destroy');
    Route::post('tasks/{task}/attachments', [TaskPartsController::class, 'attach'])->whereUuid('task')->name('tasks.attachments.store');
    Route::delete('tasks/{task}/attachments/{attachment}', [TaskPartsController::class, 'detach'])->whereUuid(['task', 'attachment'])->name('tasks.attachments.destroy');
});

// Own time with projects.time.log; anyone's with projects.time.manage (checked there).
Route::middleware('can:projects.time.log')->group(function () {
    Route::post('time', [TimeController::class, 'store'])->name('time.store');
    Route::delete('time/{timeEntry}', [TimeController::class, 'destroy'])->whereUuid('timeEntry')->name('time.destroy');
});
