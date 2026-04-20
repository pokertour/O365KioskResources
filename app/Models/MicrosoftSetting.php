<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MicrosoftSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        $row = static::query()->first();

        if ($row instanceof self) {
            return $row;
        }

        return static::query()->create([
            'tenant_id' => null,
            'client_id' => null,
            'client_secret' => null,
        ]);
    }
}
