<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Services\ZoomRecordingWebhookHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same shape as RazorpayWebhookController — no `Route::domain(...)`
 * constraint (Zoom calls one fixed URL, no notion of this app's tenant
 * subdomains) and no business logic here; ZoomRecordingWebhookHandler
 * owns signature verification and everything after it.
 */
class ZoomRecordingWebhookController extends Controller
{
    public function __invoke(Request $request, ZoomRecordingWebhookHandler $handler): Response
    {
        $payload = (array) $request->json()->all();

        // Zoom's own one-time endpoint verification handshake — sent
        // unsigned, so it's handled before the signature check below.
        if (($payload['event'] ?? null) === 'endpoint.url_validation') {
            $plainToken = (string) ($payload['payload']['plainToken'] ?? '');

            return response()->json([
                'plainToken' => $plainToken,
                'encryptedToken' => $handler->encryptedToken($plainToken),
            ]);
        }

        abort_unless($handler->verifySignature(
            $request->getContent(),
            $request->header('x-zm-request-timestamp', ''),
            $request->header('x-zm-signature', ''),
        ), 401);

        if (($payload['event'] ?? null) === 'recording.completed') {
            $handler->handleRecordingCompleted($payload);
        }

        return response('ok');
    }
}
