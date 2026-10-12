<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

final readonly class PlanSummary
{
    public function __construct(
        public int $id,
        public string $productSlug,
        public string $slug,
        public string $name,
        public string $kind,
        public string $mode,
        public string $audience,
        public string $status,
        public ?int $trafficGb,
        public ?int $durationDays,
        public ?int $minGb,
        public ?int $maxGb,
        public int $minDays,
        public int $maxDays,
        public int $sortOrder,
        public bool $featured,
        public int $revision,
    ) {}

    public static function fromRecord(object $row): self
    {
        return new self(
            id: (int) $row->id,
            productSlug: (string) $row->product_slug,
            slug: (string) $row->slug,
            name: (string) $row->name,
            kind: (string) $row->kind,
            mode: (string) $row->mode,
            audience: (string) $row->audience,
            status: (string) $row->status,
            trafficGb: $row->traffic_gb === null ? null : (int) $row->traffic_gb,
            durationDays: $row->duration_days === null ? null : (int) $row->duration_days,
            minGb: $row->min_gb === null ? null : (int) $row->min_gb,
            maxGb: $row->max_gb === null ? null : (int) $row->max_gb,
            minDays: (int) $row->min_days,
            maxDays: (int) $row->max_days,
            sortOrder: (int) $row->sort_order,
            featured: (bool) $row->is_featured,
            revision: (int) $row->revision,
        );
    }
}
