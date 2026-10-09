<?php

namespace Keel\App\Services\Geocoding;

/**
 * One place a geocoder found. label is what to show first ("Park City", "123 Main St");
 * secondary_label places it ("Summit County, Utah"). source_ref names the provider's record
 * ("osm:N123") so the same place can be recognised later; null when there is none.
 */
readonly class PlaceResult
{
    public function __construct(
        public string $label,
        public ?string $secondaryLabel,
        public float $lat,
        public float $lng,
        public string $category = PlaceCategory::ADDRESS,
        public ?string $sourceRef = null,
    ) {
    }

    /** A spot with no name: what reverse() returns when nothing is near. */
    public static function unnamed(float $lat, float $lng): self
    {
        return new self(sprintf('%.5f, %.5f', $lat, $lng), null, $lat, $lng);
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'secondary_label' => $this->secondaryLabel,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'category' => $this->category,
            'source_ref' => $this->sourceRef,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) $data['label'],
            secondaryLabel: isset($data['secondary_label']) ? (string) $data['secondary_label'] : null,
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            category: PlaceCategory::exists($data['category'] ?? null) ? $data['category'] : PlaceCategory::ADDRESS,
            sourceRef: isset($data['source_ref']) ? (string) $data['source_ref'] : null,
        );
    }
}
