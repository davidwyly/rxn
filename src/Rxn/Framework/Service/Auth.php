<?php declare(strict_types=1);

namespace Rxn\Framework\Service;

use \Rxn\Framework\Service as BaseService;

/**
 * Minimal authentication service. Holds a user-supplied resolver
 * that maps a bearer token to a principal (usually an array of user
 * fields) and exposes helpers to extract the token from an
 * Authorization header and look it up.
 *
 * Usage in app bootstrap:
 *
 *   $auth = $container->get(Auth::class);
 *   $auth->setResolver(function (string $token): ?array {
 *       return $myUserRepository->findByToken($token);
 *   });
 *
 * Usage in a controller:
 *
 *   $token = $auth->extractBearer($request->getCollector()->getAuthorizationHeader());
 *   $user  = $auth->resolve($token);
 *   if ($user === null) { throw new \Exception('Unauthorized', 401); }
 */
class Auth extends BaseService
{
    /**
     * @var callable|null
     */
    private $resolver;

    public function setResolver(callable $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function hasResolver(): bool
    {
        return $this->resolver !== null;
    }

    /**
     * Pull the bearer credential out of an Authorization header
     * value. Returns null for missing, malformed, or non-Bearer
     * headers.
     */
    public function extractBearer(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null) {
            return null;
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', $authorizationHeader, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Resolve a token to a principal, or null when no resolver is
     * registered, no token was supplied, or the resolver rejected
     * the credential.
     *
     * @return array|null
     */
    public function resolve(?string $token): ?array
    {
        if ($token === null || $token === '' || $this->resolver === null) {
            return null;
        }
        $result = ($this->resolver)($token);
        return is_array($result) ? $result : null;
    }
}
