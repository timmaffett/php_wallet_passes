<?php

namespace Chiiya\LaravelPasses\Apple\Components;

use Chiiya\LaravelPasses\Common\Component;
use Chiiya\LaravelPasses\Common\Validation\Required;
use Spatie\DataTransferObject\Attributes\Strict;

#[Strict]
class Location extends Component
{
    /**
     * Optional.
     * Altitude, in meters, of the location.
     */
    public ?float $altitude;

    /**
     * Required.
     * Latitude, in degrees, of the location.
     */
    #[Required]
    public ?float $latitude;

    /**
     * Required.
     * Longitude, in degrees, of the location.
     */
    #[Required]
    public ?float $longitude;

    /**
     * Optional.
     * ext displayed on the lock screen when the pass is currently relevant.
     */
    public ?string $relevantText;
}