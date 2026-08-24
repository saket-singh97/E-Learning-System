<?php

namespace App\Http\Controllers\Batches;

use App\Domains\Batches\Models\DeviceWebhookCredential;
use App\Domains\Batches\Services\DeviceWebhookHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S16 B4 (H12) — same shape as ZoomRecordingWebhookController: no
 * business logic here, DeviceWebhookHandler owns signature verification
 * and everything after it. Unlike Zoom's single global secret, each
 * credential carries its own — the route embeds the credential id so the
 * right secret can be looked up before the signature is checked. No
 * `Route::domain(...)` constraint (a physical device has no notion of
 * this app's tenant subdomains) — no `CurrentTenant` bound on this route,
 * so `BelongsToTenant`'s own global scope skips filtering (same reasoning
 * `ZoomRecordingWebhookHandler` documents), making this a genuine
 * cross-tenant lookup by design, not a leak.
 */
class DeviceWebhookController extends Controller
{
    public function __invoke(Request $request, DeviceWebhookHandler $handler): Response
    {
        $credential = DeviceWebhookCredential::find($request->route('credential'));

        abort_if($credential === null, 404);

        abort_unless($handler->verifySignature(
            $request->getContent(),
            $request->header('X-Device-Timestamp', ''),
            $request->header('X-Device-Signature', ''),
            $credential->webhook_secret,
        ), 401);

        $handler->handleEvent($credential, (array) $request->json()->all());

        return response('ok');
    }
}
