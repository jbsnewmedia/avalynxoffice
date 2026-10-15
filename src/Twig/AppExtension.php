<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Address;
use App\Entity\CalendarEvent;
use App\Entity\Email;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    private const string COMPOSE_BUTTON_REGISTRY_ID = \JBSNewMedia\MailActionBundle\Registry\ComposeButtonRegistry::class;
    private const string EMAIL_ACTION_REGISTRY_ID = \JBSNewMedia\MailActionBundle\Registry\EmailActionRegistry::class;
    private const string CONTACT_ACTION_REGISTRY_ID = \JBSNewMedia\MailActionBundle\Registry\ContactActionRegistry::class;
    private const string CALENDAR_ACTION_REGISTRY_ID = \JBSNewMedia\MailActionBundle\Registry\CalendarActionRegistry::class;

    public function __construct(
        private readonly ContainerInterface $pluginLocator = new ServiceLocator([]),
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('bytes_to_human', $this->bytesToHuman(...)),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('file_icon', $this->fileIconClass(...)),
            new TwigFunction('file_icon_color', $this->fileIconColor(...)),
            new TwigFunction('mail_compose_button_views', $this->getComposeButtonViews(...)),
            new TwigFunction('mail_email_action_views', $this->getEmailActionViews(...)),
            new TwigFunction('mail_contact_action_views', $this->getContactActionViews(...)),
            new TwigFunction('mail_calendar_action_views', $this->getCalendarActionViews(...)),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function getComposeButtonViews(): array
    {
        if (!$this->pluginLocator->has(self::COMPOSE_BUTTON_REGISTRY_ID)) {
            return [];
        }

        $registry = $this->pluginLocator->get(self::COMPOSE_BUTTON_REGISTRY_ID);
        if (!is_object($registry) || !method_exists($registry, 'getButtons')) {
            return [];
        }

        $views = $registry->getButtons();

        return is_array($views) ? array_values($views) : [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getEmailActionViews(Email $email): array
    {
        if (!$this->pluginLocator->has(self::EMAIL_ACTION_REGISTRY_ID)) {
            return [];
        }

        $registry = $this->pluginLocator->get(self::EMAIL_ACTION_REGISTRY_ID);
        if (!is_object($registry) || !method_exists($registry, 'getActions')) {
            return [];
        }

        $views = $registry->getActions($email);

        return is_array($views) ? array_values($views) : [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getContactActionViews(Address $contact): array
    {
        if (!$this->pluginLocator->has(self::CONTACT_ACTION_REGISTRY_ID)) {
            return [];
        }

        $registry = $this->pluginLocator->get(self::CONTACT_ACTION_REGISTRY_ID);
        if (!is_object($registry) || !method_exists($registry, 'getActions')) {
            return [];
        }

        $views = $registry->getActions($contact);

        return is_array($views) ? array_values($views) : [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getCalendarActionViews(CalendarEvent $event): array
    {
        if (!$this->pluginLocator->has(self::CALENDAR_ACTION_REGISTRY_ID)) {
            return [];
        }

        $registry = $this->pluginLocator->get(self::CALENDAR_ACTION_REGISTRY_ID);
        if (!is_object($registry) || !method_exists($registry, 'getActions')) {
            return [];
        }

        $views = $registry->getActions($event);

        return is_array($views) ? array_values($views) : [];
    }

    public function bytesToHuman(?int $bytes, int $decimals = 1): string
    {
        if (null === $bytes) {
            return '';
        }
        if ($bytes < 0) {
            $bytes = 0;
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $bytes = (float) $bytes;
        foreach ($units as $unit) {
            $bytes /= 1024.0;
            if ($bytes < 1024.0) {
                return number_format($bytes, $decimals).' '.$unit;
            }
        }

        return number_format($bytes / 1024.0, $decimals).' PB';
    }

    public function fileIconClass(?string $mimeType, ?string $name = null): string
    {
        $mime = strtolower((string) $mimeType);
        $ext = $name ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : '';
        if ('pdf' === $ext || str_starts_with($mime, 'application/pdf')) {
            return 'fa-file-pdf';
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'], true) || str_starts_with($mime, 'image/')) {
            return 'fa-file-image';
        }
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'm4a'], true) || str_starts_with($mime, 'audio/')) {
            return 'fa-file-audio';
        }
        if (in_array($ext, ['mp4', 'avi', 'mov', 'mkv', 'webm'], true) || str_starts_with($mime, 'video/')) {
            return 'fa-file-video';
        }
        if (in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar'], true) || 'application/zip' === $mime || str_contains($mime, 'compressed')) {
            return 'fa-file-zipper';
        }
        if (in_array($ext, ['doc', 'docx'], true) || str_contains($mime, 'msword') || str_contains($mime, 'wordprocessingml')) {
            return 'fa-file-word';
        }
        if (in_array($ext, ['xls', 'xlsx', 'csv'], true) || str_contains($mime, 'spreadsheetml') || str_contains($mime, 'excel')) {
            return 'fa-file-excel';
        }
        if (in_array($ext, ['ppt', 'pptx'], true) || str_contains($mime, 'presentationml') || str_contains($mime, 'powerpoint')) {
            return 'fa-file-powerpoint';
        }
        if (in_array($ext, ['txt', 'md', 'rtf'], true) || str_starts_with($mime, 'text/')) {
            return 'fa-file-lines';
        }
        if (in_array($ext, ['json', 'xml', 'yml', 'yaml', 'html', 'css', 'js', 'ts', 'php'], true) || str_contains($mime, 'xml') || str_contains($mime, 'json') || str_contains($mime, 'javascript')) {
            return 'fa-file-code';
        }

        return 'fa-file';
    }

    public function fileIconColor(?string $mimeType, ?string $name = null): ?string
    {
        $mime = strtolower((string) $mimeType);
        $ext = $name ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : '';
        if ('pdf' === $ext || str_starts_with($mime, 'application/pdf')) {
            return 'text-danger';
        }
        if (in_array($ext, ['doc', 'docx'], true) || str_contains($mime, 'msword') || str_contains($mime, 'wordprocessingml')) {
            return 'text-primary';
        }

        return null;
    }
}
