<?php

namespace App\Http\Controllers\Fees;

use App\Domains\Fees\Services\PaymentReconciler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lives in routes/api.php, outside every Route::domain('{subdomain}...')
 * group — Razorpay calls one fixed URL, it has no notion of this app's
 * tenant subdomains (design doc §2.4 decision 6). No business logic here;
 * PaymentReconciler owns signature verification and everything after it.
 */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentReconciler $reconciler): Response
    {
        $reconciler->reconcile(
            $request->getContent(),
            $request->header('X-Razorpay-Signature', ''),
        );

        return response('ok');
    }
}
