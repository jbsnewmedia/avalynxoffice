<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class EmailDetailTemplateResolver
{
    public const string DEFAULT_TEMPLATE = 'mail/_email_detail.html.twig';
    private const string PROVIDER_SERVICE_ID = \JBSNewMedia\MailViewBundle\View\EmailDetailTemplateProvider::class;

    public function __construct(
        private ContainerInterface $pluginLocator = new ServiceLocator([]),
    ) {
    }

    public function resolveTemplate(): string
    {
        if (!$this->pluginLocator->has(self::PROVIDER_SERVICE_ID)) {
            return self::DEFAULT_TEMPLATE;
        }

        $provider = $this->pluginLocator->get(self::PROVIDER_SERVICE_ID);
        if (!is_object($provider) || !method_exists($provider, 'getTemplate')) {
            return self::DEFAULT_TEMPLATE;
        }

        $template = $provider->getTemplate();

        return is_string($template) && '' !== $template ? $template : self::DEFAULT_TEMPLATE;
    }
}
