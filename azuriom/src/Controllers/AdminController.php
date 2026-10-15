<?php

namespace Azuriom\Plugin\JangoPay\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\JangoPay\Models\Attempt;
use Azuriom\Plugin\JangoPay\Services\JangoPayApi;
use Azuriom\Plugin\Shop\Models\Gateway;
use Illuminate\Http\Request;
use Throwable;

class AdminController extends Controller
{
    private function authorizePayments(Request $request): void
    {
        abort_unless($request->user() !== null && $request->user()->can('shop.payments'), 403);
    }

    public function index(Request $request)
    {
        $this->authorizePayments($request);
        $attempts = Attempt::orderByDesc('id')->paginate(30);
        return view('jangopay::admin.payments', compact('attempts'));
    }

    public function reverify(Request $request, Attempt $attempt)
    {
        $this->authorizePayments($request);
        $gateway = Gateway::findOrFail($attempt->gateway_id);
        abort_unless($gateway->type === 'jangopay', 404);
        try {
            $method = $gateway->paymentMethod();
            $state = $attempt->state === 'unknown_creation'
                ? $method->recoverUnknownCreation($attempt)
                : $method->reconcile($attempt);
            return back()->with('success', 'JangoPay reconciliation result: '.$state);
        } catch (Throwable $e) {
            return back()->with('error', 'Reconciliation unavailable. Check server settings and redacted logs.');
        }
    }

    public function testConnection(Request $request, JangoPayApi $api)
    {
        $this->authorizePayments($request);
        try {
            $assets = $api->listAssets();
            return back()->with('success', 'Read-only API connection worked; returned '.count($assets).' assets.');
        } catch (Throwable $e) {
            return back()->with('error', 'API connection failed. Check API Key, query permission and server logs.');
        }
    }
}
