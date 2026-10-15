<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber implements EventSubscriberInterface
{
    /** @var array<string> */
    private array $supportedLocales = ['en', 'de'];

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $locale = $request->query->get('_locale');
        if (\is_string($locale) && $this->isSupported($locale)) {
            $this->applyLocale($request, $locale);

            return;
        }
        if ($session->has('_locale')) {
            $sessionLocale = $session->get('_locale');
            if (is_string($sessionLocale) && $this->isSupported($sessionLocale)) {
                $request->setLocale($sessionLocale);

                return;
            }
        }
        $preferred = $request->getPreferredLanguage($this->supportedLocales);
        if (null !== $preferred && $this->isSupported($preferred)) {
            $this->applyLocale($request, $preferred);

            return;
        }
        $this->applyLocale($request, 'en');
    }

    private function isSupported(?string $locale): bool
    {
        if (!$locale) {
            return false;
        }
        $locale = strtolower($locale);
        if (str_contains($locale, '-')) {
            $locale = explode('-', $locale)[0];
        }

        return in_array($locale, $this->supportedLocales, true);
    }

    private function applyLocale(Request $request, string $locale): void
    {
        $locale = strtolower($locale);
        if (str_contains($locale, '-')) {
            $locale = explode('-', $locale)[0];
        }
        $request->setLocale($locale);
        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $locale);
        }
    }
}
