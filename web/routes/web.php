<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationRuleController;
use App\Http\Controllers\CareerItemController;
use App\Http\Controllers\CareerProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\JobBulkController;
use App\Http\Controllers\JobImportController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\ReplyController;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('career-profile', [CareerProfileController::class, 'edit'])->name('career-profile.edit');
    Route::put('career-profile', [CareerProfileController::class, 'update'])->name('career-profile.update');
    Route::post('career-profile/import', [CareerProfileController::class, 'import'])->name('career-profile.import');
    Route::whereIn('section', CareerItemController::SECTIONS)->group(function () {
        Route::post('career-profile/{section}', [CareerItemController::class, 'store'])->name('career-items.store');
        Route::put('career-profile/{section}/{item}', [CareerItemController::class, 'update'])->name('career-items.update');
        Route::delete('career-profile/{section}/{item}', [CareerItemController::class, 'destroy'])->name('career-items.destroy');
    });

    Route::get('resumes/{resume}/download', [ResumeController::class, 'download'])->name('resumes.download');
    Route::get('resumes/{resume}/preview', [ResumeController::class, 'preview'])->name('resumes.preview');
    Route::post('resumes/{resume}/review', [ResumeController::class, 'review'])->name('resumes.review');
    Route::resource('resumes', ResumeController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('jobs/import', [JobImportController::class, 'create'])->name('jobs.import');
    Route::post('jobs/bulk/analyze', [JobBulkController::class, 'analyze'])->name('jobs.bulk.analyze');
    Route::post('jobs/bulk/generate', [JobBulkController::class, 'generate'])->name('jobs.bulk.generate');
    Route::post('jobs/bulk/schedule', [JobBulkController::class, 'schedule'])->name('jobs.bulk.schedule');
    Route::post('jobs/bulk/cancel', [JobBulkController::class, 'cancel'])->name('jobs.bulk.cancel');
    Route::post('jobs/import', [JobImportController::class, 'store'])->name('jobs.import.store');
    Route::resource('jobs', JobPostingController::class)->except(['edit'])
        ->parameters(['jobs' => 'job']);
    Route::post('jobs/{job}/application', [ApplicationController::class, 'store'])->name('applications.store');
    Route::post('jobs/{job}/analyze', [JobPostingController::class, 'analyze'])->name('jobs.analyze');

    Route::get('review', [ReviewController::class, 'index'])->name('review.index');
    Route::post('review/approve', [ReviewController::class, 'approve'])->name('review.approve');
    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
    Route::post('applications/{application}/regenerate', [ApplicationController::class, 'regenerate'])->name('applications.regenerate');
    Route::post('applications/{application}/approve', [ApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('applications/{application}/reopen', [ApplicationController::class, 'reopen'])->name('applications.reopen');
    Route::post('applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])->name('applications.withdraw');
    Route::post('applications/{application}/track', [ApplicationController::class, 'track'])->name('applications.track');
    Route::post('applications/{application}/queue', [ApplicationController::class, 'queue'])->name('applications.queue');
    Route::put('applications/{application}/recipients', [ApplicationController::class, 'updateRecipients'])->name('applications.recipients');
    Route::post('applications/{application}/cancel-send', [ApplicationController::class, 'cancelSend'])->name('applications.cancel-send');
    Route::post('applications/{application}/retry-send', [ApplicationController::class, 'retrySend'])->name('applications.retry-send');

    Route::post('messages/{message}/analyze', [ReplyController::class, 'analyze'])->name('replies.analyze');
    Route::post('messages/{message}/draft', [ReplyController::class, 'draft'])->name('replies.draft');
    Route::put('messages/{message}', [ReplyController::class, 'update'])->name('replies.update');
    Route::post('messages/{message}/send', [ReplyController::class, 'send'])->name('replies.send');
    Route::post('messages/{message}/cancel', [ReplyController::class, 'cancel'])->name('replies.cancel');
    Route::delete('messages/{message}', [ReplyController::class, 'destroy'])->name('replies.destroy');

    Route::get('email-accounts', [EmailAccountController::class, 'index'])->name('email-accounts.index');
    Route::post('email-accounts/sync', [EmailAccountController::class, 'sync'])->name('email-accounts.sync');
    Route::get('email-accounts/google/connect', [EmailAccountController::class, 'redirectToGoogle'])->name('email-accounts.google.connect');
    Route::get('email-accounts/google/callback', [EmailAccountController::class, 'handleGoogleCallback'])->name('email-accounts.google.callback');
    Route::patch('email-accounts/{emailAccount}', [EmailAccountController::class, 'update'])->name('email-accounts.update');
    Route::delete('email-accounts/{emailAccount}', [EmailAccountController::class, 'destroy'])->name('email-accounts.destroy');

    Route::get('rules', [ApplicationRuleController::class, 'edit'])->name('rules.edit');
    Route::put('rules', [ApplicationRuleController::class, 'update'])->name('rules.update');
});

require __DIR__.'/settings.php';
