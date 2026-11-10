<?php

use App\Http\Controllers\People\PeopleController;
use Illuminate\Support\Facades\Route;

/*
| People: the organization's team directory and what their time costs.
| Inside {slug}.domain (routes/web.php).
*/

Route::get('people', [PeopleController::class, 'index'])->can('projects.view')->name('people.index');

Route::middleware('can:projects.people.manage')->group(function () {
    Route::get('people/create', [PeopleController::class, 'create'])->name('people.create');
    Route::post('people', [PeopleController::class, 'store'])->name('people.store');
    Route::get('people/{person}/edit', [PeopleController::class, 'edit'])->whereUuid('person')->name('people.edit');
    Route::put('people/{person}', [PeopleController::class, 'update'])->whereUuid('person')->name('people.update');
});
