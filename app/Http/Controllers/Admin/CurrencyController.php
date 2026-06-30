<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function index(): Response
    {

        $currencies = Currency::orderBy('code')->get();

        return Inertia::render('Admin/Currencies/Index', ['currencies' => $currencies]);
    }

    public function update(Request $request, Currency $currency): \Illuminate\Http\RedirectResponse
    {

        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:16'],
            'decimals' => ['integer', 'min:0', 'max:6'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['boolean'],
            'enabled' => ['boolean'],
        ]);

        if (! empty($validated['is_default'])) {
            Currency::where('code', '!=', $currency->code)->update(['is_default' => false]);
        }

        $currency->update($validated);

        return back()->with('success', __('Currency updated.'));
    }
}
