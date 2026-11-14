<?php

declare(strict_types=1);
use Illuminate\Http\Request;

/*
 * The application is a modular monolith. These rules keep the modules from
 * growing into each other.
 */

arch('the domain is plain PHP: no framework, no other layer')
    ->expect('App\Modules\Notifications\Domain')
    ->toUseNothing();

arch('accepting notifications does not know how they are delivered or announced')
    ->expect('App\Modules\Notifications')
    ->not->toUse(['App\Modules\Delivery', 'App\Modules\Templates', 'App\Modules\Realtime']);

arch('real-time updates only listen to events; nothing depends on them')
    ->expect('App\Modules\Realtime')
    ->not->toBeUsedIn(['App\Modules\Notifications', 'App\Modules\Delivery', 'App\Modules\Templates', 'App\Modules\Observability']);

arch('templates depend only on the contract they implement')
    ->expect('App\Modules\Templates')
    ->not->toUse(['App\Modules\Delivery', 'App\Modules\Notifications\Application', 'App\Modules\Notifications\Http']);

arch('the delivery pipeline never reaches into the HTTP layer')
    ->expect('App\Modules\Delivery')
    ->not->toUse(['App\Modules\Notifications\Http', 'App\Modules\Templates', Request::class]);

arch('application services do not depend on HTTP')
    ->expect('App\Modules\Notifications\Application')
    ->not->toUse(['Illuminate\Http', 'App\Modules\Notifications\Http']);

arch('every file declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die'])
    ->not->toBeUsed();
