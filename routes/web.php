<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\PlanningRuleSettingController;
use App\Http\Controllers\PlanningRunController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/', ScheduleController::class)->name('schedule');
Route::get('/planning-periods/{period}/schedule', ScheduleController::class)->name('planning-periods.schedule');
Route::post('/planning-periods/{period}/planning-runs', [PlanningRunController::class, 'store'])->name('planning-runs.store');
Route::patch('/planning-rules/{code}', [PlanningRuleSettingController::class, 'update'])->name('planning-rules.update');
Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
Route::post('/assignments/{assignment}/lock', [AssignmentController::class, 'lock'])->name('assignments.lock');
Route::post('/assignments/{assignment}/unlock', [AssignmentController::class, 'unlock'])->name('assignments.unlock');
Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
