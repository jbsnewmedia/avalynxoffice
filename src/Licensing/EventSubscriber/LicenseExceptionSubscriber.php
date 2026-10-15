<?php

declare(strict_types=1);

namespace App\Licensing\EventSubscriber;

use App\Licensing\Exception\LicenseReadOnlyException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Converts license enforcement exceptions into a proper HTTP 403 response
 * with the error code LICENSE_READ_ONLY.
 */
final readonly class LicenseExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 16],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        while (!$exception instanceof LicenseReadOnlyException && null !== $exception->getPrevious()) {
            $exception = $exception->getPrevious();
        }

        if (!$exception instanceof LicenseReadOnlyException) {
            return;
        }

        $this->logger->warning('Blocked a user-initiated write operation because the installation is in read-only mode.', [
            'operation' => $exception->operation->value,
            'path' => $event->getRequest()->getPathInfo(),
        ]);

        $event->setResponse($this->createResponse($event->getRequest(), $exception));
    }

    private function createResponse(\Symfony\Component\HttpFoundation\Request $request, LicenseReadOnlyException $exception): Response
    {
        if ($request->isXmlHttpRequest()
            || in_array('application/json', $request->getAcceptableContentTypes(), true)
        ) {
            return new JsonResponse([
                'success' => false,
                'code' => 'LICENSE_READ_ONLY',
                'error' => $exception->operation->value,
                'message' => $exception->getMessage(),
            ], Response::HTTP_FORBIDDEN);
        }

        $content = $this->twig->render('license/read_only_error.html.twig', [
            'operation' => $exception->operation->value,
            'message' => $exception->getMessage(),
        ]);

        return new Response($content, Response::HTTP_FORBIDDEN);
    }
}
