<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Model;

final readonly class TemplateView
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $template,
        private array $context = [],
    ) {
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
