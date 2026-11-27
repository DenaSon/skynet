<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class ManageCrmSavedFilters
{
    private const int LIMIT = 30;

    private const array KEYS = ['period', 'from', 'until', 'registeredFrom', 'registeredUntil', 'status', 'telegram', 'purchase', 'segment', 'advancedMode', 'advancedConditions', 'sort', 'direction'];

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadCrmDirectory $directory,
    ) {}

    /** @return list<array{id:string,name:string}>|null */
    public function list(int $actorId): ?array
    {
        $this->access->handle($actorId);
        if (! Schema::hasTable('crm_saved_filters')) {
            return null;
        }

        return $this->owned($actorId)->orderBy('name')->limit(self::LIMIT)->get(['id', 'name'])
            ->map(static fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])->values()->all();
    }

    /** @param  array<string, mixed>  $criteria */
    public function save(int $actorId, string $name, array $criteria, ?int $id = null): int
    {
        $this->assertAvailable($actorId);
        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:80']])->validate();
        $criteria = $this->normalize($actorId, $criteria);

        return DB::transaction(function () use ($actorId, $name, $criteria, $id): int {
            // Serialize creates/renames for a single owner, including the first preset.
            DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
            $owned = $this->owned($actorId);
            if ($id !== null && ! (clone $owned)->where('id', $id)->exists()) {
                throw ValidationException::withMessages(['preset' => 'فیلتر انتخاب‌شده یافت نشد.']);
            }
            $duplicate = (clone $owned)->where('name', $name);
            if ($id !== null) {
                $duplicate->where('id', '!=', $id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['preset' => 'این نام قبلاً استفاده شده است.']);
            }
            $values = ['name' => $name, 'criteria' => json_encode($criteria, JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($id !== null) {
                $owned->where('id', $id)->update($values);

                return $id;
            }
            if ($owned->count() >= self::LIMIT) {
                throw ValidationException::withMessages(['preset' => 'حداکثر ۳۰ فیلتر قابل ذخیره است.']);
            }

            return (int) DB::table('crm_saved_filters')->insertGetId([...$values, 'owner_user_id' => $actorId, 'created_at' => now()]);
        });
    }

    /** @return array{name:string,criteria:array<string,mixed>} */
    public function load(int $actorId, int $id): array
    {
        $this->assertAvailable($actorId);
        $row = $this->owned($actorId)->where('id', $id)->first(['name', 'criteria']);
        if ($row === null) {
            throw ValidationException::withMessages(['preset' => 'فیلتر انتخاب‌شده یافت نشد.']);
        }
        try {
            $criteria = json_decode((string) $row->criteria, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $criteria = null;
        }
        if (! is_array($criteria)) {
            throw ValidationException::withMessages(['preset' => 'فیلتر ذخیره‌شده معتبر نیست.']);
        }

        return ['name' => (string) $row->name, 'criteria' => $this->normalize($actorId, $criteria)];
    }

    public function delete(int $actorId, int $id): void
    {
        $this->assertAvailable($actorId);
        DB::transaction(function () use ($actorId, $id): void {
            DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
            if ($this->owned($actorId)->where('id', $id)->delete() !== 1) {
                throw ValidationException::withMessages(['preset' => 'فیلتر انتخاب‌شده یافت نشد.']);
            }
        });
    }

    private function assertAvailable(int $actorId): void
    {
        $this->access->handle($actorId);
        if (! Schema::hasTable('crm_saved_filters')) {
            throw ValidationException::withMessages(['preset' => 'ذخیره فیلترها هنوز در دسترس نیست.']);
        }
    }

    private function owned(int $actorId): Builder
    {
        return DB::table('crm_saved_filters')->where('owner_user_id', $actorId);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function normalize(int $actorId, array $criteria): array
    {
        $criteria = array_intersect_key($criteria, array_flip(self::KEYS));
        Validator::make($criteria, [
            'sort' => ['required', 'string', Rule::in(['id', 'name', 'created_at', 'is_active', 'transactions', 'revenue_rials'])],
            'segment' => ['required', 'string'],
            'advancedMode' => ['required', 'string'],
            'advancedConditions' => ['present', 'array'],
        ])->validate();
        $validated = $this->directory->validateFilters($actorId, 'customers', [
            ...$criteria, 'phone' => 'all', 'orderStatus' => 'all', 'page' => 1,
        ]);
        if ($validated['period'] !== 'custom') {
            $validated['from'] = null;
            $validated['until'] = null;
        }
        $validated['advancedConditions'] = array_map(static fn (array $condition): array => [
            'field' => (string) $condition['field'],
            'operator' => (string) $condition['operator'],
            'value' => (string) $condition['value'],
        ], $validated['advancedConditions']);

        return array_intersect_key($validated, array_flip(self::KEYS));
    }
}
