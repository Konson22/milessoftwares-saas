<?php

use App\Http\Controllers\Finance\ApprovalController;
use App\Http\Controllers\Finance\CommitmentController;
use Illuminate\Support\Facades\Route;

/*
| What waits on finance across the organization. Inside {slug}.domain
| (routes/web.php); the page checks which queues the member may act on.
*/

Route::get('approvals', ApprovalController::class)->name('approvals');
Route::get('commitments', CommitmentController::class)->can('finance.commitments.view')->name('commitments');
