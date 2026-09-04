<?php

namespace App\Providers;

use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\Pdf\Contracts\PdfGenerator;
use App\Services\Pdf\DompdfPdfGenerator;
use App\Services\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);

        $this->app->bind(PdfGenerator::class, DompdfPdfGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureMorphMap();
    }

    /**
     * Maps the `reference_entity_type` string stored on generated_documents
     * (.ai/14-PDF.md) to its model class, instead of leaking the fully
     * qualified class name into the database. Only `payroll_entry` exists
     * today; POST-MVP document types extend this map, they don't replace it.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'payroll_entry' => PayrollEntry::class,
        ]);
    }

    /**
     * Route every ability check through the RBAC permission catalog
     * (.ai/06-AUTHORIZATION.md) instead of defining a gate per permission.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ?: null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
