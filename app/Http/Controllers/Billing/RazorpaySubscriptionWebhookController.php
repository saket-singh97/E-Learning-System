<?php

namespace App\Http\Controllers\Billing;

use App\Domains\Billing\Services\SubscriptionReconciler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lives in routes/api.php, outside every Route::domain('{subdomain}...')
 * group — same "one fixed vendor URL, no tenant subdomain" posture as
 * Fees\RazorpayWebhookController. No business logic here;
 * SubscriptionReconciler owns signature verification and everything after
 * it.
 */
class RazorpaySubscriptionWebhookController extends Controller
{
    public function __invoke(Request $request, SubscriptionReconciler $reconciler): Response
    {
        $reconciler->reconcile(
            $request->getContent(),
            $request->header('X-Razorpay-Signature', ''),
        );

        return response('ok');
    }
}
