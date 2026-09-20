<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanSelectionService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private PlanSelectionService $planSelection) {}

    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        $selectedPlanId = (int) $request->query('plan_id', 0);
        $selectedPlan = $selectedPlanId > 0
            ? Plan::query()->where('enabled', true)->find($selectedPlanId)
            : null;
        $cycle = $this->normalizeCycle((string) $request->query('cycle', 'month'));

        return Inertia::render('Auth/Register', [
            'plan_id' => $selectedPlan?->id,
            'cycle' => $cycle,
            'selected_plan' => $selectedPlan ? [
                'id' => $selectedPlan->id,
                'name' => $selectedPlan->name,
                'is_free' => $selectedPlan->isFree(),
                'price_cents' => $selectedPlan->priceCentsForCycle($cycle),
                'currency' => $selectedPlan->currency_code,
            ] : null,
            'signup_available' => $this->planSelection->defaultFreePlan() !== null,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'agree_terms' => ['accepted'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'plan_id' => [
                'nullable',
                'integer',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->where('enabled', true)),
            ],
            'cycle' => ['required', Rule::in(['month', 'year'])],
        ], [
            'agree_terms.accepted' => 'You must accept the Terms & Conditions to create an account.',
        ]);

        $plan = $request->filled('plan_id')
            ? Plan::query()->where('enabled', true)->findOrFail($request->integer('plan_id'))
            : null;
        $cycle = $this->normalizeCycle((string) $request->input('cycle', 'month'));
        if ($plan && ! $plan->isFree() && $plan->priceCentsForCycle($cycle) === null) {
            throw ValidationException::withMessages([
                'cycle' => 'That billing period is not available for the selected plan.',
            ]);
        }
        if (! $this->planSelection->defaultFreePlan()) {
            throw ValidationException::withMessages([
                'plan_id' => 'Account creation is temporarily unavailable because the initial Free plan is not configured.',
            ]);
        }

        // Use browser-detected timezone from signup; fall back to Bangladesh Standard Time.
        $timezone = $this->resolveTimezone($request->input('timezone'));

        $user = DB::transaction(function () use ($request, $timezone) {
            $client = Client::create([
                'name' => $request->name,
                'email' => $request->email,
                'status' => Client::STATUS_ACTIVE,
                'base_currency' => 'USD',
                'currency_symbol' => '$',
                'currency_position' => 'before',
            ]);

            ClientSetting::set($client->id, 'plan_selection_required', '1');

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_ACTIVE,
                'client_id' => $client->id,
                'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                'timezone' => $timezone,
            ]);

            $this->planSelection->activateDefaultFreePlan($user);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        // Preserve paid intent from public Pricing, but always leave the account
        // with usable Free access if checkout is abandoned.
        if ($plan && ! $plan->isFree()) {
            return redirect()->route('client.pricing')->with([
                'plan_id' => $plan->id,
                'cycle' => $cycle,
                'success' => "Your workspace is active on Free. Complete secure checkout to activate {$plan->name}.",
            ]);
        }

        return redirect(route('client.dashboard', absolute: false));
    }

    private function resolveTimezone(?string $tz): string
    {
        $default = 'Asia/Dhaka';
        if (! $tz) {
            return $default;
        }
        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Exception) {
            return $default;
        }
    }

    private function normalizeCycle(string $cycle): string
    {
        return in_array($cycle, ['year', 'yearly'], true) ? 'year' : 'month';
    }
}
