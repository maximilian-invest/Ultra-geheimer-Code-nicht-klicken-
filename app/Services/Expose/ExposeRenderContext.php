<?php

namespace App\Services\Expose;

use App\Models\Property;
use App\Models\PropertyExposeVersion;
use App\Models\User;

class ExposeRenderContext
{
    public function __construct(
        public readonly Property $property,
        public readonly PropertyExposeVersion $version,
        public readonly ?User $broker,
        public readonly array $pages,
        public readonly string $hausTextMode,
        public readonly string $lageTextMode,
        public readonly ?string $claimText,
    ) {}

    public static function build(
        PropertyExposeVersion $version,
        ExposePaginationService $pagination,
    ): self {
        $property = $version->property;
        $property->loadMissing('images');
        $config = $version->config_json;

        // Broker-Priorität: (1) Property.broker_id, (2) Version.created_by.
        // So zeigt das Kontakt-Template immer die E-Mail des Maklers der
        // das Exposé angelegt hat — auch wenn die Property keinen Broker hat.
        $broker = null;
        if ($property->broker_id) {
            $broker = User::find($property->broker_id);
        }
        if (!$broker && $version->created_by) {
            $broker = User::find($version->created_by);
        }

        return new self(
            property: $property,
            version: $version,
            broker: $broker,
            pages: $config['pages'] ?? [],
            hausTextMode: $pagination->textFlowMode($property->realty_description ?? ''),
            lageTextMode: $pagination->textFlowMode($property->location_description ?? ''),
            claimText: $property->expose_claim ?: ($config['claim_text'] ?? null),
        );
    }

    /** Findet Bild-Datensatz anhand der image_id aus der Config. */
    public function image(?int $imageId): ?\App\Models\PropertyImage
    {
        if (!$imageId) return null;
        return $this->property->images->firstWhere('id', $imageId);
    }
}
