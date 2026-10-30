<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Presentation-only shell; reseller data is intentionally deferred to Phase 2.
 * No duplicate Billing, Sales, or Provisioning business queries live here.
 */
#[Layout('live.layout')]
final class Dashboard extends Component
{
    public string $section = 'overview';

    private const SECTIONS = ['overview', 'services', 'credit', 'reports'];

    public function showSection(string $section): void
    {
        if (in_array($section, self::SECTIONS, true)) {
            $this->section = $section;
        }
    }

    public function render(): View
    {
        return view('live.dashboard', [
            'name' => Auth::user()?->name ?? 'نماینده',
        ]);
    }
}
