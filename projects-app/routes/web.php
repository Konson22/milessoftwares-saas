<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TimeController;
use App\Http\Middleware\RememberPerson;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Product pages
|--------------------------------------------------------------------------
|
| Served at {workspace}/<path>: every route here (and every platform-client
| route) carries the path prefix. The proxy must not strip it. The
| "platform" middleware resolves the workspace from the host and requires a
| signed-in member whose platform access allows this product.
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform', RememberPerson::class])
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('my-work', MyWorkController::class)->name('my-work');

        // The team directory and what people cost.
        Route::get('people', [PeopleController::class, 'index'])->can('projects.view')->name('people.index');
        Route::middleware('can:projects.people.manage')->group(function () {
            Route::get('people/create', [PeopleController::class, 'create'])->name('people.create');
            Route::post('people', [PeopleController::class, 'store'])->name('people.store');
            Route::get('people/{person}/edit', [PeopleController::class, 'edit'])->whereUuid('person')->name('people.edit');
            Route::put('people/{person}', [PeopleController::class, 'update'])->whereUuid('person')->name('people.update');
        });

        Route::middleware('can:projects.manage')->group(function () {
            Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        });
        Route::get('projects', [ProjectController::class, 'index'])->can('projects.view')->name('projects.index');

        // One project and everything in it. Nested records resolve through
        // the project (scoped bindings): another project's look unknown.
        Route::prefix('projects/{project}')->whereUuid('project')->scopeBindings()->name('projects.')->group(function () {
            Route::middleware('can:projects.view')->group(function () {
                Route::get('/', [ProjectController::class, 'show'])->name('show');
                Route::get('board', [TaskController::class, 'board'])->name('board');
                Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
                Route::get('timeline', [TaskController::class, 'timeline'])->name('timeline');
                Route::get('tasks/{task}', [TaskController::class, 'show'])->whereUuid('task')->name('tasks.show');
                Route::get('team', [TeamController::class, 'show'])->name('team');
                Route::get('time', [TimeController::class, 'show'])->name('time');
                Route::get('risks', [RiskController::class, 'index'])->name('risks.index');
            });

            Route::middleware('can:projects.manage')->group(function () {
                Route::get('edit', [ProjectController::class, 'edit'])->name('edit');
                Route::put('/', [ProjectController::class, 'update'])->name('update');
                Route::post('status', [ProjectController::class, 'status'])->name('status');
                Route::post('members', [TeamController::class, 'addMember'])->name('members.store');
                Route::delete('members/{member}', [TeamController::class, 'removeMember'])->whereUuid('member')->name('members.destroy');
                Route::post('milestones', [TeamController::class, 'addMilestone'])->name('milestones.store');
                Route::post('milestones/{milestone}/toggle', [TeamController::class, 'toggleMilestone'])->whereUuid('milestone')->name('milestones.toggle');
                Route::delete('milestones/{milestone}', [TeamController::class, 'removeMilestone'])->whereUuid('milestone')->name('milestones.destroy');
                Route::post('risks', [RiskController::class, 'store'])->name('risks.store');
                Route::put('risks/{risk}', [RiskController::class, 'update'])->whereUuid('risk')->name('risks.update');
            });

            Route::middleware('can:projects.tasks.manage')->group(function () {
                Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
                Route::put('tasks/{task}', [TaskController::class, 'update'])->whereUuid('task')->name('tasks.update');
                Route::post('tasks/{task}/move', [TaskController::class, 'move'])->whereUuid('task')->name('tasks.move');
                Route::post('tasks/{task}/comments', [TaskController::class, 'comment'])->whereUuid('task')->name('tasks.comments.store');
                Route::post('tasks/{task}/dependencies', [TaskController::class, 'addDependency'])->whereUuid('task')->name('tasks.dependencies.store');
                Route::delete('tasks/{task}/dependencies/{dependency}', [TaskController::class, 'removeDependency'])->whereUuid(['task', 'dependency'])->name('tasks.dependencies.destroy');
            });

            // Own time with projects.time.log; anyone's with projects.time.manage (checked there).
            Route::middleware('can:projects.time.log')->group(function () {
                Route::post('time', [TimeController::class, 'store'])->name('time.store');
                Route::delete('time/{timeEntry}', [TimeController::class, 'destroy'])->whereUuid('timeEntry')->name('time.destroy');
            });

            Route::middleware('can:projects.finance.view')->group(function () {
                Route::get('budget', [BudgetController::class, 'show'])->name('budget');
            });
            Route::middleware(['can:projects.finance.view', 'can:projects.manage'])->group(function () {
                Route::post('budget/lines', [BudgetController::class, 'addLine'])->name('budget.lines.store');
                Route::delete('budget/lines/{budgetLine}', [BudgetController::class, 'removeLine'])->whereUuid('budgetLine')->name('budget.lines.destroy');
                Route::post('expenses', [BudgetController::class, 'addExpense'])->name('expenses.store');
                Route::delete('expenses/{expense}', [BudgetController::class, 'removeExpense'])->whereUuid('expense')->name('expenses.destroy');
            });
        });
    });
