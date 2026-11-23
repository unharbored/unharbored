<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/**
 * What an application can look like on a provider, worked out from what the
 * provider supplies. A shape the provider cannot support is not hidden: it
 * comes back locked, with a reason that names the missing service and the
 * provider, so the wizard, the API and the CLI explain a refusal the same way.
 */
final class Shapes
{
    public const SINGLE = 'single';

    public const MULTI = 'multi';

    public const AUTOSCALE = 'autoscale';

    /**
     * @return array<string, array{available: bool, reason: ?string}>
     */
    public static function for(Provider $provider): array
    {
        $has = fn (string $service) => in_array($service, $provider->supplies(), true);
        $name = $provider->label();

        // The first thing that is missing, as a sentence.
        $multi = match (true) {
            $provider->method() === 'ssh' => 'Your own servers are machines you provide, so Unharbored cannot create more to put behind a balancer.',
            ! $has('edge') => "Multi-server needs a load balancer. {$name} does not offer one yet.",
            ! $has('object_storage') => "Multi-server needs object storage for builds. {$name} does not offer it yet.",
            default => null,
        };
        $autoscale = $multi === null ? null : (str_starts_with($multi, 'Multi-server') ? 'Autoscaling'.substr($multi, strlen('Multi-server')) : $multi);

        return [
            self::SINGLE => ['available' => true, 'reason' => null],
            self::MULTI => ['available' => $multi === null, 'reason' => $multi],
            self::AUTOSCALE => ['available' => $autoscale === null, 'reason' => $autoscale],
        ];
    }

    /** The reason a shape is locked on a provider, or null when it is open. */
    public static function lockedBecause(Provider $provider, string $shape): ?string
    {
        $shapes = self::for($provider);

        return isset($shapes[$shape]) ? $shapes[$shape]['reason'] : "There is no shape called {$shape}.";
    }

    /**
     * Each provider as the wizard and the API show it.
     *
     * @return array<string, mixed>
     */
    public static function describe(Provider $provider): array
    {
        $catalog = $provider->catalog();

        return [
            'id' => $provider->id(),
            'label' => $provider->label(),
            'method' => $provider->method(),
            'supplies' => $provider->supplies(),
            'regions' => $catalog->regions,
            'sizes' => array_map(fn ($s) => $s + ['approximate' => true], $catalog->sizes),
            'shapes' => self::for($provider),
        ];
    }
}
