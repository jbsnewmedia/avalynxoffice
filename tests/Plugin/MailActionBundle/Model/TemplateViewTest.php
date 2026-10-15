<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Model;

use JBSNewMedia\MailActionBundle\Model\TemplateView;
use PHPUnit\Framework\TestCase;

final class TemplateViewTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $view = new TemplateView('template.html.twig', ['foo' => 'bar']);

        self::assertSame('template.html.twig', $view->getTemplate());
        self::assertSame(['foo' => 'bar'], $view->getContext());
    }
}
