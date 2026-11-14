<?php

declare(strict_types=1);

namespace App\Modules\Templates\Models;

use App\Modules\Notifications\Domain\Channel;
use App\Support\DbTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property Channel|null $channel
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
#[Table(name: 'templates')]
class Template extends Model
{
    protected $dateFormat = DbTime::FORMAT;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
