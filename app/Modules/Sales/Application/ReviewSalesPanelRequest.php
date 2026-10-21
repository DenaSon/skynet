<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ApproveSalesPanelMember;
use App\Modules\Identity\Application\ManageRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A trusted root can review only the original persisted, pending application. */
final readonly class ReviewSalesPanelRequest
{
    public function __construct(
        private ManageRoles $roles,
        private ApproveSalesPanelMember $promote,
    ) {}

    /** @return list<array{id:int,user_id:int,first_name:string,last_name:string,mobile:string,status:string}> */
    public function page(int $rootId, int $page): array
    {
        $this->roles->authorize($rootId);
        if ($page < 1 || $page > 1000) {
            throw ValidationException::withMessages(['page' => 'Invalid page.']);
        }

        return DB::table('sales_panel_requests')->where('status', 'pending')
            ->orderBy('id')->offset(($page - 1) * 8)->limit(9)->get()
            ->map(fn (object $row): array => $this->map($row))->all();
    }

    /** @return array{id:int,user_id:int,first_name:string,last_name:string,mobile:string,status:string} */
    public function find(int $rootId, int $id): array
    {
        $this->roles->authorize($rootId);
        $row = DB::table('sales_panel_requests')->where('id', $id)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['application' => 'Application not found.']);
        }

        return $this->map($row);
    }

    /** @return array{id:int,user_id:int,first_name:string,last_name:string,mobile:string,status:string} */
    public function decide(int $rootId, int $id, bool $approve): array
    {
        return DB::transaction(function () use ($rootId, $id, $approve): array {
            $this->roles->authorize($rootId);
            $row = DB::table('sales_panel_requests')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'pending') {
                throw ValidationException::withMessages(['application' => 'Application already reviewed or unavailable.']);
            }

            if ($approve) {
                $this->promote->handle($rootId, (int) $row->user_id);
            }

            DB::table('sales_panel_requests')->where('id', $id)->where('status', 'pending')->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by_user_id' => $rootId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->map(DB::table('sales_panel_requests')->where('id', $id)->first());
        });
    }

    /** @return array{id:int,user_id:int,first_name:string,last_name:string,mobile:string,status:string} */
    private function map(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'mobile' => (string) $row->mobile,
            'status' => (string) $row->status,
        ];
    }
}
