<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AccountProvisioner;
use App\Services\SettingsService;
use App\Support\ActivityLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class RegisteredUserController extends Controller
{
    public function __construct(
        protected AccountProvisioner $provisioner,
        protected SettingsService $settings,
    ) {}

    public function create(): View|Response
    {
        abort_unless($this->registrationOpen(), 403, 'Registration is currently closed.');

        return view('auth.register', [
            'defaultPlan' => $this->provisioner->defaultPlan(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Creates the account, its owner user and the starting subscription in one
     * transaction, then signs the owner in.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        abort_unless($this->registrationOpen(), 403, 'Registration is currently closed.');

        $user = $this->provisioner->provision($request->validated());

        event(new Registered($user));

        Auth::login($user);

        ActivityLogger::log('account.registered', "Account {$user->account->name} registered", [
            'account_id' => $user->account_id,
            'user_id' => $user->id,
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'Welcome to '.$this->settings->brand('company_name').'. Your account is ready.');
    }

    protected function registrationOpen(): bool
    {
        return (bool) $this->settings->get('system', 'allow_registration', '1');
    }
}
