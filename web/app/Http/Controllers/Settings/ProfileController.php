<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\EmailAccount;
use App\Models\Resume;
use App\Services\Mail\MailProvider;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    /**
     * Delete the account and everything in it. The database rows cascade; resume files and the
     * Gmail access the user granted are removed here, as the Privacy Policy promises.
     */
    public function destroy(ProfileDeleteRequest $request, MailProvider $provider): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->emailAccounts->each(fn (EmailAccount $account) => rescue(fn () => $provider->revoke($account), report: false));
        $paths = $user->resumes()->pluck('path')->filter()->all();
        $user->delete();
        rescue(fn () => Storage::disk(Resume::disk())->delete($paths), report: false);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
