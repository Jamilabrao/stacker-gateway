<?php

namespace App\Services\Meta;

class MetaEventContext
{
    /**
     * @param  array<int, string>  $contentIds
     */
    public function __construct(
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $clientUserAgent = null,
        public readonly ?string $eventSourceUrl = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $country = null,
        public readonly ?string $zip = null,
        public readonly ?string $externalId = null,
        public readonly ?float $value = null,
        public readonly string $currency = 'BRL',
        public readonly array $contentIds = [],
        public readonly ?string $contentName = null,
        public readonly int $numItems = 1,
        public readonly ?int $eventTime = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
            'client_ip' => $this->clientIp,
            'client_user_agent' => $this->clientUserAgent,
            'event_source_url' => $this->eventSourceUrl,
            'email' => $this->email,
            'phone' => $this->phone,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'zip' => $this->zip,
            'external_id' => $this->externalId,
            'value' => $this->value,
            'currency' => $this->currency,
            'content_ids' => $this->contentIds,
            'content_name' => $this->contentName,
            'num_items' => $this->numItems,
            'event_time' => $this->eventTime,
        ];
    }
}
