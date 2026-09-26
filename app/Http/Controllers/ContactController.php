<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\LandingPageController;
use App\Models\ContactMessage;
use App\Services\Marketing\MetaConversions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('marketing/Contact', [
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
        ]);
    }

    public function store(Request $request, MetaConversions $conversions): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'meta_event_id' => ['nullable', 'string', 'max:64'],
        ]);

        ContactMessage::create([
            ...Arr::except($validated, 'meta_event_id'),
            'ip_address' => $request->ip(),
        ]);

        $conversions->lead($request, $validated['email'], $validated['name'], $validated['meta_event_id'] ?? null);

        return back()->with('success', __('Your message has been received. We\'ll get back to you soon!'));
    }
}
