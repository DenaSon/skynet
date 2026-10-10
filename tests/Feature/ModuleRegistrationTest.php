<?php

namespace Tests\Feature;

use App\Modules\Administration\AdministrationServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use Tests\TestCase;

class ModuleRegistrationTest extends TestCase
{
    public function test_identity_module_provider_is_registered(): void
    {
        $this->assertInstanceOf(
            IdentityServiceProvider::class,
            $this->app->getProvider(IdentityServiceProvider::class),
        );
    }

    public function test_administration_module_provider_is_registered(): void
    {
        $this->assertInstanceOf(
            AdministrationServiceProvider::class,
            $this->app->getProvider(AdministrationServiceProvider::class),
        );
    }
}
