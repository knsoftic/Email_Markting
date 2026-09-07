<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        // Changing the address invalidates verification, so the new one has to
        // be confirmed before the account keeps working.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        ActivityLogger::log('profile.updated', "{$user->name} updated their profile");

        return to_route('profile.edit')->with('success', 'Profile updated.');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $disk = Storage::disk('public');

        if ($user->avatar_path && $disk->exists($user->avatar_path)) {
            $disk->delete($user->avatar_path);
        }

        $user->forceFill([
            'avatar_path' => $request->file('avatar')->store('avatars', 'public'),
        ])->save();

        ActivityLogger::log('profile.avatar_updated', "{$user->name} changed their profile image");

        return to_route('profile.edit')->with('success', 'Profile image updated.');
    }

    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $disk = Storage::disk('public');

        if ($user->avatar_path && $disk->exists($user->avatar_path)) {
            $disk->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => null])->save();

        return to_route('profile.edit')->with('success', 'Profile image removed.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults()->min(8), 'confirmed'],
        ]);

        $request->user()->update(['password' => Hash::make($validated['password'])]);

        ActivityLogger::log('profile.password_changed', $request->user()->name.' changed their password');

        return to_route('profile.edit')->with('success', 'Password changed.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // The owner cannot delete themselves out of an account that still has
        // other members — the account would be left with no owner.
        if ($user->isAccountOwner() && $user->account?->users()->where('id', '!=', $user->id)->exists()) {
            return to_route('profile.edit')->with('error', 'Transfer ownership or remove your team members before deleting your account.');
        }

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        ActivityLogger::log('profile.deleted', "{$user->name} deleted their account");

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
