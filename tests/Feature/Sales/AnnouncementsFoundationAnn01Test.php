<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ManageAnnouncements;
use App\Modules\Sales\Application\ReadPublishedAnnouncements;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['994801', '994899']);
});

function ann01Root(int $telegramId = 994801): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

it('saves drafts and exposes only the three latest published announcements', function (): void {
    $root = ann01Root();
    $manage = app(ManageAnnouncements::class);
    $reader = app(ReadPublishedAnnouncements::class);

    $draft = $manage->create($root->id, '  خبر   آزمایشی ', "متن\r\nخبر");
    expect($reader->latest())->toBe([])
        ->and($reader->find($draft))->toBeNull();

    $ids = [];
    foreach (range(1, 4) as $index) {
        $ids[] = $manage->create($root->id, "اطلاعیه {$index}", "متن {$index}", true);
    }

    expect(array_map(static fn (object $row): int => $row->id, $reader->latest()))->toBe(array_reverse(array_slice($ids, -3)))
        ->and($reader->find($ids[0])?->title)->toBe('اطلاعیه 1')
        ->and(DB::table('announcements')->where('id', $draft)->value('title'))->toBe('خبر آزمایشی')
        ->and(DB::table('announcements')->where('id', $draft)->value('body'))->toBe("متن\nخبر");
});

it('updates a published announcement without moving its publication timestamp', function (): void {
    $root = ann01Root();
    $manage = app(ManageAnnouncements::class);
    $id = $manage->create($root->id, 'خبر قبلی', 'متن قبلی', true);
    $timestamp = DB::table('announcements')->where('id', $id)->value('published_at');

    $manage->update($root->id, $id, 1, 'خبر جدید', 'متن جدید');
    $row = $manage->find($root->id, $id);
    expect($row->revision)->toBe(2)
        ->and($row->status)->toBe('published')
        ->and($row->published_at)->toBe($timestamp)
        ->and(app(ReadPublishedAnnouncements::class)->find($id)?->title)->toBe('خبر جدید');

    expect(fn () => $manage->update($root->id, $id, 1, 'ویرایش قدیمی', 'نادرست'))
        ->toThrow(ValidationException::class);
    expect($manage->find($root->id, $id)->title)->toBe('خبر جدید');
});

it('unpublishes without deleting content and republishes with a new timestamp', function (): void {
    $root = ann01Root();
    $manage = app(ManageAnnouncements::class);
    $reader = app(ReadPublishedAnnouncements::class);
    $id = $manage->create($root->id, 'خبر', 'توضیح', true);

    $manage->changeStatus($root->id, $id, 1, 'unpublish');
    expect($reader->find($id))->toBeNull()
        ->and($manage->find($root->id, $id)->status)->toBe('draft')
        ->and($manage->find($root->id, $id)->body)->toBe('توضیح');

    $manage->changeStatus($root->id, $id, 2, 'publish');
    expect($reader->find($id))->not->toBeNull()
        ->and($manage->find($root->id, $id)->revision)->toBe(3);
    expect(fn () => $manage->changeStatus($root->id, $id, 2, 'unpublish'))
        ->toThrow(ValidationException::class);
});

it('rejects invalid content before database writes', function (): void {
    $root = ann01Root();
    $manage = app(ManageAnnouncements::class);
    foreach ([['', 'valid'], [str_repeat('x', 121), 'valid'], ['valid', ''], ['valid', str_repeat('x', 1501)]] as [$title, $body]) {
        expect(fn () => $manage->create($root->id, $title, $body))->toThrow(ValidationException::class);
    }
    expect(DB::table('announcements')->count())->toBe(0);
});

it('enforces trusted Super Admin at the Action boundary after revocation', function (): void {
    $root = ann01Root();
    $member = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 994805]);
    $manage = app(ManageAnnouncements::class);
    $id = $manage->create($root->id, 'خبر', 'متن');

    expect(fn () => $manage->create($member->id, 'غیرمجاز', 'محتوا'))->toThrow(AuthorizationException::class);
    expect(fn () => $manage->listing($member->id))->toThrow(AuthorizationException::class);
    expect(fn () => $manage->find($member->id, $id))->toThrow(AuthorizationException::class);
    expect(fn () => $manage->changeStatus($member->id, $id, 1, 'publish'))->toThrow(AuthorizationException::class);

    $root->syncRoles(['member']);
    expect(fn () => $manage->update($root->id, $id, 1, 'ویرایش', 'نادرست'))->toThrow(AuthorizationException::class);
    expect(DB::table('announcements')->where('status', 'published')->count())->toBe(0);
});
