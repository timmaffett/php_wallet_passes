<?php

namespace Chiiya\Passes\Google;

use Chiiya\Passes\Common\Component;
use Chiiya\Passes\Common\Validation\MinItems;
use Chiiya\Passes\Common\Validation\Required;
use Chiiya\Passes\Google\Passes\EventTicketClass;
use Chiiya\Passes\Google\Passes\EventTicketObject;
use Chiiya\Passes\Google\Passes\FlightClass;
use Chiiya\Passes\Google\Passes\FlightObject;
use Chiiya\Passes\Google\Passes\GiftCardClass;
use Chiiya\Passes\Google\Passes\GiftCardObject;
use Chiiya\Passes\Google\Passes\LoyaltyClass;
use Chiiya\Passes\Google\Passes\LoyaltyObject;
use Chiiya\Passes\Google\Passes\OfferClass;
use Chiiya\Passes\Google\Passes\OfferObject;
use Chiiya\Passes\Google\Passes\TransitClass;
use Chiiya\Passes\Google\Passes\TransitObject;
use Firebase\JWT\JWT as Encoder;

class JWT extends Component
{
    public const AUDIENCE = 'google';

    public const TYPE = 'savetoandroidpay';

    /**
     * Required.
     * Your OAuth 2.0 service account generated email address.
     */
    public string $iss;

    /**
     * Required.
     * Audience. The audience for Google Pay API for Passes Objects will always be google.
     */
    public string $aud = self::AUDIENCE;

    /**
     * Required.
     * Type of JWT. The audience for Google Pay API for Passes Objects will always be savetoandroidpay.
     */
    public string $typ = self::TYPE;

    /**
     * Required.
     * Issued at time in seconds since epoch.
     */
    public int $iat;

    /**
     * Required.
     * Payload object. Refer to Generating the JWT Guide for an example of creating the payload.
     * Only one object or class should be included in the payload arrays.
     */
    public array $payload = [];

    /**
     * Required.
     * Signing key. Should be the service account private key.
     */
    public string $key;

    /**
     * Required.
     * Array of domains to whitelist JWT saving functionality. The Google Pay API for Passes button will
     * not render when the origins field is not defined. You could potentially get an "Load denied by X-Frame-Options"
     * or "Refused to display" messages in the browser console when the origins field is not defined.
     */
    #[Required]
    #[MinItems(1)]
    public array $origins = [];

    public function __construct(...$args)
    {
        $this->iat = time();
        parent::__construct($args);
    }

    public function addOfferClass(OfferClass $class): static
    {
        return $this->addComponent($class, 'offerClasses');
    }

    public function addOfferObject(OfferObject $object): static
    {
        return $this->addComponent($object, 'offerObjects');
    }

    public function addLoyaltyClass(LoyaltyClass $class): static
    {
        return $this->addComponent($class, 'loyaltyClasses');
    }

    public function addLoyaltyObject(LoyaltyObject $object): static
    {
        return $this->addComponent($object, 'loyaltyObjects');
    }

    public function addGiftCardClass(GiftCardClass $class): static
    {
        return $this->addComponent($class, 'giftCardClasses');
    }

    public function addGiftCardObject(GiftCardObject $object): static
    {
        return $this->addComponent($object, 'giftCardObjects');
    }

    public function addEventTicketClass(EventTicketClass $class): static
    {
        return $this->addComponent($class, 'eventTicketClasses');
    }

    public function addEventTicketObject(EventTicketObject $object): static
    {
        return $this->addComponent($object, 'eventTicketObjects');
    }

    public function addFlightClass(FlightClass $class): static
    {
        return $this->addComponent($class, 'flightClasses');
    }

    public function addFlightObject(FlightObject $object): static
    {
        return $this->addComponent($object, 'flightObjects');
    }

    public function addTransitClass(TransitClass $class): static
    {
        return $this->addComponent($class, 'transitClasses');
    }

    public function addTransitObject(TransitObject $object): static
    {
        return $this->addComponent($object, 'transitObjects');
    }

    /**
     * Sign the JWT.
     */
    public function sign(): string
    {
        $payload = $this->except('key')->toArray();

        return Encoder::encode($payload, $this->key, 'RS256');
    }

    private function addComponent(Component $component, string $type): static
    {
        if (! array_key_exists($type, $this->payload)) {
            $this->payload[$type] = [];
        }

        $this->payload[$type][] = $component;

        return $this;
    }
}