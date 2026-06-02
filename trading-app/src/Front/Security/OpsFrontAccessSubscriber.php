<?php

declare(strict_types=1);

namespace App\Front\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class OpsFrontAccessSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['guardOpsFront', 128],
        ];
    }

    public function guardOpsFront(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ($path !== '/app' && !str_starts_with($path, '/app/')) {
            return;
        }

        $password = $this->env('OPS_FRONT_PASSWORD');
        $token = $this->env('OPS_FRONT_TOKEN');
        if ($password === '' && $token === '') {
            if (in_array($this->env('APP_ENV'), ['dev', 'test'], true)) {
                return;
            }

            $event->setResponse(new Response('Ops front access is not configured.', Response::HTTP_SERVICE_UNAVAILABLE));

            return;
        }

        if ($token !== '' && hash_equals($token, (string) $request->headers->get('X-Ops-Token', ''))) {
            return;
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if ($token !== '' && str_starts_with($authorization, 'Bearer ')) {
            if (hash_equals($token, trim(substr($authorization, 7)))) {
                return;
            }
        }

        if ($password !== '') {
            $user = $this->env('OPS_FRONT_USER') !== '' ? $this->env('OPS_FRONT_USER') : 'ops';
            if (hash_equals($user, (string) $request->getUser())
                && hash_equals($password, (string) $request->getPassword())
            ) {
                return;
            }
        }

        $response = new Response('Authentication required.', Response::HTTP_UNAUTHORIZED);
        $response->headers->set('WWW-Authenticate', 'Basic realm="TradingV3 Ops"');
        $event->setResponse($response);
    }

    private function env(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
