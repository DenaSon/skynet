<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Settings;

it('installs Spatie settings with a database repository that can hold long Persian multiline text', function (): void {
    expect(class_exists(Settings::class))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeTrue();

    $message = str_repeat("به دناگیت خوش آمدید!\n", 80);

    DB::table('settings')->insert([
        'group' => 'sales',
        'name' => 'long_text_storage_smoke',
        'locked' => false,
        'payload' => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = DB::table('settings')->where('group', 'sales')
        ->where('name', 'long_text_storage_smoke')->value('payload');

    expect(is_string($payload) ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : $payload)
        ->toBe($message);
});
