<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Validation\ValidationException;

final readonly class ExportCrmDirectory
{
    public function __construct(private ReadCrmDirectory $directory, private AssertCatalogManagementAccess $access) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filename:string,mime:string,content:string}
     */
    public function handle(int $actorId, string $section, array $filters, string $format): array
    {
        $this->access->handle($actorId);
        if (! in_array($format, ['csv', 'json', 'md'], true)) {
            throw ValidationException::withMessages(['export' => 'نوع خروجی معتبر نیست.']);
        }
        $page = $this->directory->exportRows($actorId, $section, $filters);
        $grouped = $section === 'contacts' && (bool) ($filters['groupContacts'] ?? false);
        $rfm = $section === 'customers' && ($filters['segment'] ?? 'off') !== 'off';
        $columns = $this->columns($section, $grouped, $rfm);
        $rows = [];
        foreach ($page->items() as $row) {
            $rows[] = $this->project($row, $columns);
        }
        $content = match ($format) {
            'csv' => $this->csv($columns, $rows),
            'json' => json_encode(['columns' => $columns, 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'md' => $this->markdown($columns, $rows),
        };
        if (strlen($content) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['export' => 'حجم فایل بیش از حد مجاز است؛ فیلترها را محدودتر کنید.']);
        }

        return [
            'filename' => 'crm-'.$section.'-'.now('UTC')->format('Ymd-His').'.'.$format,
            'mime' => match ($format) {
                'csv' => 'text/csv; charset=UTF-8',
                'json' => 'application/json; charset=UTF-8',
                'md' => 'text/markdown; charset=UTF-8',
            },
            'content' => $content,
        ];
    }

    /** @return list<string> */
    private function columns(string $section, bool $grouped, bool $rfm): array
    {
        if ($section === 'contacts') {
            return $grouped
                ? ['reseller_id', 'reseller_name', 'order_id', 'created_at_utc', 'phone_present', 'records_count', 'purchased_count']
                : ['reseller_id', 'reseller_name', 'order_id', 'created_at_utc', 'phone_present', 'product_name', 'status', 'purchased'];
        }
        $base = ['id', 'name', 'is_active', 'created_at_utc', 'transactions', 'order_count', 'revenue_rials'];
        if ($section === 'resellers') {
            return [...$base, 'sales_rank', 'performance_rank', 'performance_score', 'performance_tier', 'performance_state', 'phones', 'name_only', 'purchased_phones', 'purchased_name_only'];
        }

        return [...$base, 'telegram_linked', ...($rfm ? ['r_score', 'f_score', 'm_score', 'lifetime_orders', 'lifetime_rials', 'last_purchase_at_utc'] : [])];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string|int|float|bool|null>
     */
    private function project(object $row, array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $result[$column] = match ($column) {
                'name', 'reseller_name', 'product_name', 'status' => (string) $row->{$column},
                'created_at_utc' => (string) $row->created_at,
                'last_purchase_at_utc' => isset($row->last_purchase_at) ? (string) $row->last_purchase_at : null,
                'is_active', 'telegram_linked', 'purchased' => (bool) $row->{$column},
                'phone_present' => isset($row->phone) && $row->phone !== '',
                'performance_rank' => isset($row->performance['rank']) ? (int) $row->performance['rank'] : null,
                'performance_score' => isset($row->performance['score']) ? (float) $row->performance['score'] : null,
                'performance_tier' => $row->performance['tier'] ?? null,
                'performance_state' => $row->performance['state'] ?? null,
                default => isset($row->{$column}) ? (int) $row->{$column} : null,
            };
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string|int|float|bool|null>>  $rows
     */
    private function csv(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to allocate export stream.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(static function (mixed $value): mixed {
                    if (is_string($value) && preg_match('/\A[\p{Z}\p{C}\s]*[=+\-@]|\A[\t\r\n]/u', $value) === 1) {
                        return "'".$value;
                    }

                    return is_bool($value) ? (int) $value : $value;
                }, array_values($row)), ',', '"', '');
            }
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string|int|float|bool|null>>  $rows
     */
    private function markdown(array $columns, array $rows): string
    {
        $lines = ['| '.implode(' | ', $columns).' |', '| '.implode(' | ', array_fill(0, count($columns), '---')).' |'];
        foreach ($rows as $row) {
            $cells = array_map(static function (mixed $value): string {
                $text = is_bool($value) ? (string) (int) $value : (string) $value;
                $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);

                return strtr(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), [
                    '|' => '&#124;', '\\' => '&#92;', '[' => '&#91;', ']' => '&#93;',
                    '(' => '&#40;', ')' => '&#41;', '*' => '&#42;', '_' => '&#95;',
                    '`' => '&#96;', '~' => '&#126;', '!' => '&#33;',
                ]);
            }, array_values($row));
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines)."\n";
    }
}
