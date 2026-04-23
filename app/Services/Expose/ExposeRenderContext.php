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

        return new self(
            property: $property,
            version: $version,
            broker: $property->broker_id ? User::find($property->broker_id) : null,
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
