<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrandingSetting extends Model
{
    public const LOGO_DISK = 'local';

    public const DEFAULTS = [
        'primary_color' => '#2563eb',
        'available_color' => '#16a34a',
        'busy_color' => '#dc2626',
        'soon_color' => '#d97706',
        'kiosk_theme' => 'dark',
    ];

    public const THEMES = ['dark', 'light'];

    private const ICON_PANEL_PATH = 'M18 14h7a5 5 0 0 1 5 5v26a5 5 0 0 1-5 5h-7a5 5 0 0 1-5-5V19a5 5 0 0 1 5-5Zm3.5 24a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z';

    protected $fillable = [
        'site_name',
        'logo_path',
        'primary_color',
        'available_color',
        'busy_color',
        'soon_color',
        'kiosk_theme',
        'kiosk_footer',
    ];

    protected $attributes = self::DEFAULTS;

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->site_name);

        return $name !== '' ? $name : (string) config('app.name', 'Laravel');
    }

    public function hasLogo(): bool
    {
        return filled($this->logo_path) && Storage::disk(self::LOGO_DISK)->exists($this->logo_path);
    }

    public function logoUrl(): ?string
    {
        if (! $this->hasLogo()) {
            return null;
        }

        return route('branding.logo', ['v' => substr(md5((string) $this->logo_path), 0, 10)]);
    }

    /**
     * Default app icon tinted with the primary colour, as a data URI (used as favicon).
     */
    public function iconDataUri(): string
    {
        $color = self::sanitizeColor($this->primary_color, self::DEFAULTS['primary_color']);
        $fg = self::readableForeground($color);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="15" fill="'.$color.'"/>'
            .'<path fill="'.$fg.'" fill-rule="evenodd" d="'.self::ICON_PANEL_PATH.'"/>'
            .'<rect x="34" y="14" width="17" height="8" rx="4" fill="'.$fg.'"/>'
            .'<rect x="34" y="28" width="17" height="8" rx="4" fill="'.$fg.'" opacity=".6"/>'
            .'<rect x="34" y="42" width="17" height="8" rx="4" fill="'.$fg.'" opacity=".35"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function deleteStoredLogo(): void
    {
        if ($this->hasLogo()) {
            Storage::disk(self::LOGO_DISK)->delete($this->logo_path);
        }
        $this->logo_path = null;
    }

    /**
     * Changes whenever the look of the screens changes, so open kiosks know when to reload.
     */
    public function version(): string
    {
        return substr(md5(implode('|', [$this->id, $this->updated_at?->getTimestamp(), $this->logo_path])), 0, 12);
    }

    public function kioskTheme(): string
    {
        return in_array($this->kiosk_theme, self::THEMES, true) ? $this->kiosk_theme : self::DEFAULTS['kiosk_theme'];
    }

    /**
     * CSS custom properties injected on :root so Tailwind utilities (bg-brand, text-busy…)
     * and Flux's accent follow the administrator's palette.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $primary = self::sanitizeColor($this->primary_color, self::DEFAULTS['primary_color']);
        $available = self::sanitizeColor($this->available_color, self::DEFAULTS['available_color']);
        $busy = self::sanitizeColor($this->busy_color, self::DEFAULTS['busy_color']);
        $soon = self::sanitizeColor($this->soon_color, self::DEFAULTS['soon_color']);
        $onPrimary = self::readableForeground($primary);

        return [
            '--color-brand' => $primary,
            '--color-brand-foreground' => $onPrimary,
            '--color-available' => $available,
            '--color-available-foreground' => self::readableForeground($available),
            '--color-busy' => $busy,
            '--color-busy-foreground' => self::readableForeground($busy),
            '--color-soon' => $soon,
            '--color-soon-foreground' => self::readableForeground($soon),
            '--color-accent' => $primary,
            '--color-accent-content' => $primary,
            '--color-accent-foreground' => $onPrimary,
        ];
    }

    public function cssVariablesInline(): string
    {
        return collect($this->cssVariables())
            ->map(fn (string $value, string $name): string => $name.':'.$value)
            ->implode(';');
    }

    public static function sanitizeColor(?string $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * Black or white, whichever contrasts best with the given colour (WCAG relative luminance).
     */
    public static function readableForeground(string $hex): string
    {
        $channels = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > 0.36 ? '#0a0a0a' : '#ffffff';
    }
}
