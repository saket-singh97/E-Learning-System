<?php

namespace App\Http\Controllers\AI;

use App\Domains\AI\Services\WhatsAppInboundMessageHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lives in routes/api.php, outside every Route::domain('{subdomain}...')
 * group — the platform's single WhatsApp number has no notion of tenant
 * subdomains (design §4 decision 1). No business logic here;
 * WhatsAppInboundMessageHandler owns signature verification and
 * everything after it, same shape RazorpayWebhookController/
 * PaymentReconciler already established.
 */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppInboundMessageHandler $handler): Response
    {
        $handler->handle(
            $request->getContent(),
            $request->header('X-Webhook-Signature', ''),
        );

        return response('ok');
    }
}
