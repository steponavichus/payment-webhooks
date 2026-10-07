<?php

namespace App\Http\Middleware;

use App\Webhooks\SignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function __construct(private readonly SignatureVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');

        /** @var list<string> $secrets */
        $secrets = config('webhooks.providers.'.$provider.'.secrets', []);

        // Unknown provider or provider without secrets: do not reveal which one.
        abort_if($secrets === [], 404);

        $valid = $this->verifier->verify(
            $request->getContent(),
            $request->header('X-Webhook-Signature'),
            $secrets,
        );

        if (! $valid) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}
