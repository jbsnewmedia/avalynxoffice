<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Util;

use AvalynxOffice\Util\CssClassCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

class CssClassCollectorTest extends TestCase
{
    public function testExtractCssClasses(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $twig = $this->createStub(Environment::class);
        $params = $this->createStub(ParameterBagInterface::class);

        $collector = new CssClassCollector($kernel, $twig, $params);

        $code = '
            <div class="container active"></div>
            <p class=\'text-muted\'>{# Comment #}</p>
            <span class="btn {{ dynamic }}"></span>
        ';

        $classes = $collector->extractCssClasses($code);

        $this->assertContains('container', $classes);
        $this->assertContains('active', $classes);
        $this->assertContains('text-muted', $classes);
        $this->assertContains('btn', $classes);
        $this->assertNotContains('dynamic', $classes);
    }

    public function testExtractCssClassesTrimsPunctuationAndSkipsTemplateOnlyTokens(): void
    {
        $collector = new CssClassCollector(
            $this->createStub(KernelInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(ParameterBagInterface::class),
        );

        $classes = $collector->extractCssClasses('<div class="(alert), {% if active %} active {% endif %} [data-state=open]"></div>');

        $this->assertSame(['alert', 'active', '[data-state=open]'], $classes);
    }

    public function testExtractCssClassesSkipsMalformedTemplateTokens(): void
    {
        $collector = new CssClassCollector(
            $this->createStub(KernelInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(ParameterBagInterface::class),
        );

        $classes = $collector->extractCssClasses('<div class="{{broken keep"></div>');

        $this->assertSame(['keep'], $classes);
    }

    public function testCollectGroupedAndAllReadTemplatesAndSkipLoaderErrors(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $templateDir = self::varDir().'/css-class-collector-'.$suffix;
        self::assertTrue(mkdir($templateDir, 0777, true) || is_dir($templateDir));
        self::assertNotFalse(file_put_contents($templateDir.'/alpha.html.twig', '<div class="btn btn-primary"></div>'));
        self::assertNotFalse(file_put_contents($templateDir.'/beta.html.twig', '<div class="alert {{ dynamic }} btn"></div>'));
        self::assertNotFalse(file_put_contents($templateDir.'/broken.html.twig', '<div class="ignored"></div>'));

        try {
            $kernel = $this->createStub(KernelInterface::class);
            $kernel->method('getBundles')->willReturn([]);

            $loader = $this->createStub(LoaderInterface::class);
            $loader->method('getSourceContext')
                ->willReturnCallback(static fn (string $template): Source => match ($template) {
                    'alpha.html.twig' => new Source('<div class="btn btn-primary"></div>', $template),
                    'beta.html.twig' => new Source('<div class="alert {{ dynamic }} btn"></div>', $template),
                    'broken.html.twig' => throw new LoaderError('broken'),
                });

            $twig = $this->createMock(Environment::class);
            $twig->expects(self::exactly(6))->method('getLoader')->willReturn($loader);

            $params = $this->createStub(ParameterBagInterface::class);
            $params->method('has')
                ->willReturnMap([
                    ['twig.default_path', true],
                    ['twig.paths', false],
                ]);
            $params->method('get')
                ->willReturnMap([
                    ['twig.default_path', $templateDir],
                    ['twig.paths', null],
                ]);

            $collector = new CssClassCollector($kernel, $twig, $params);

            $grouped = $collector->collectGrouped();
            self::assertSame([
                'alpha.html.twig' => ['btn', 'btn-primary'],
                'beta.html.twig' => ['alert', 'btn'],
            ], $grouped);
            self::assertSame(['alert', 'btn', 'btn-primary'], $collector->collectAll());
        } finally {
            @unlink($templateDir.'/alpha.html.twig');
            @unlink($templateDir.'/beta.html.twig');
            @unlink($templateDir.'/broken.html.twig');
            @rmdir($templateDir);
        }
    }

    public function testCollectGroupedFallsBackWhenTwigParametersHaveUnexpectedTypes(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getBundles')->willReturn([]);

        $twig = $this->createStub(Environment::class);
        $params = $this->createStub(ParameterBagInterface::class);
        $params->method('has')
            ->willReturnMap([
                ['twig.default_path', true],
                ['twig.paths', true],
            ]);
        $params->method('get')
            ->willReturnMap([
                ['twig.default_path', ['unexpected']],
                ['twig.paths', 'unexpected'],
            ]);

        $collector = new CssClassCollector($kernel, $twig, $params);

        self::assertSame([], $collector->collectGrouped());
        self::assertSame([], $collector->collectAll());
    }

    /**
     * Repository-local var directory, created on demand: tests must not
     * depend on a pre-existing /app/var nor on the process working directory.
     */
    private static function varDir(): string
    {
        $dir = dirname(__DIR__, 2).'/var';

        if (!is_dir($dir)) {
            self::assertTrue(@mkdir($dir, 0777, true) || is_dir($dir));
        }

        return $dir;
    }
}
