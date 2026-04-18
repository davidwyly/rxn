<?php declare(strict_types=1);

namespace Organization\Product\Http\Controller\v1;

/**
 * Used by the CI integration job to verify that the full request
 * pipeline (Startup, Registry, Api, Request, Controller dispatch,
 * Response rendering) boots against a live MySQL without touching
 * application tables.
 */
class SmokeController extends \Rxn\Framework\Http\Controller
{
    public function ping_v1(): array
    {
        return [
            'ok'  => true,
            'env' => getenv('ENVIRONMENT') ?: 'unknown',
        ];
    }
}
