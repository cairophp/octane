<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Cairo\Http\Adapter;
use Cairo\Http\Request as CairoRequest;
use Cairo\Http\Response;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Throwable;

/**
 * Octane's link to Cairo: a Cairo request becomes an Illuminate request, and
 * the response Octane sends goes back to Cairo through the request context.
 */
final class CairoClient implements Client
{
    /** @return array{0: Request, 1: RequestContext} */
    public function marshalRequest(RequestContext $context): array
    {
        $request = Adapter::toSymfony($context['cairo.request'], Request::class);
        $context['request'] = $request;
        return [$request, $context];
    }

    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $out = Adapter::fromSymfony($response->response, $context['request'] ?? null);
        if ((string) $response->outputBuffer !== '') {
            $out = new Response($out->status, $out->headers, $response->outputBuffer . $out->body);
        }
        $context['cairo.response'] = $out;
    }

    public function error(Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $context['cairo.response'] = self::serverError(
            Octane::formatExceptionForClient($e, (bool) $app->make('config')->get('app.debug'))
        );
    }

    /** Runs one Cairo request through the Octane worker. */
    public function handle(Worker $worker, CairoRequest $req): Response
    {
        [$request, $context] = $this->marshalRequest(new RequestContext(['cairo.request' => $req]));
        $worker->handle($request, $context);
        return $context['cairo.response'] ?? self::serverError('Internal Server Error');
    }

    public static function serverError(string $body): Response
    {
        return new Response(500, ['Content-Type' => ['text/plain; charset=utf-8']], $body);
    }
}
