<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrandingSetting extends Model
{
    protected $fillable = [
        'site_name',
        'logo_path',
    ];

    public static function current(): self
    {
        $row = static::query()->first();
        if ($row instanceof self) {
            return $row;
        }

        return static::query()->create([
            'site_name' => null,
            'logo_path' => null,
        ]);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->site_name);

        return $name !== '' ? $name : (string) config('app.name', 'Laravel');
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }

        return asset('storage/'.$this->logo_path);
    }

    public function deleteStoredLogo(): void
    {
        if ($this->logo_path && Storage::disk('public')->exists($this->logo_path)) {
            Storage::disk('public')->delete($this->logo_path);
        }
        $this->logo_path = null;
    }
}
