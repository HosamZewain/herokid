<?php

namespace App\Services\Cart;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Identifies a browser-only draft; customer field values are never stored here. */
class CheckoutFormDraft
{
    public function scope(Request $request): string
    {
        $owner = $request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'guest';
        $context = $request->session()->get('checkout.form_draft');

        if (! is_array($context) || ($context['owner'] ?? null) !== $owner) {
            $context = ['owner' => $owner, 'scope' => (string) Str::uuid()];
            $request->session()->put('checkout.form_draft', $context);
        }

        return $context['scope'];
    }

    public function complete(Request $request): void
    {
        $scope = $request->input('checkout_draft_scope');
        $context = $request->session()->get('checkout.form_draft');

        // Older clients have no draft. A replay must not clear a newer cart's draft.
        if (! is_string($scope) || ! is_array($context) || ($context['scope'] ?? null) !== $scope) {
            return;
        }

        $request->session()->put('checkout.completed_draft_scope', $scope);
        $request->session()->forget('checkout.form_draft');
    }
}
