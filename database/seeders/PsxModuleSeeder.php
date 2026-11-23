<?php

namespace Database\Seeders;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PSX Trading module - permissions + starter master data.
 * Safe to run more than once on an existing database:
 *   php artisan db:seed --class=PsxModuleSeeder
 * Sample trades from the Excel (optional, for demos):
 *   php artisan db:seed --class=PsxSampleTradesSeeder
 */
class PsxModuleSeeder extends Seeder
{
    public function run(): void
    {
        // ── Permissions ─────────────────────────────────────────
        $names = [];
        foreach (['psx_stocks', 'psx_brokers', 'psx_trades'] as $module) {
            foreach (['index', 'create', 'edit', 'delete', 'print'] as $action) {
                $names[] = "$module.$action";
            }
        }
        $names[] = 'psx_trades.import';
        $names[] = 'reports.psx_dashboard';
        $names[] = 'reports.psx_holdings';
        $names[] = 'reports.psx_pl';
        $names[] = 'reports.psx_movement';
        $names[] = 'reports.psx_commission';

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['superadmin', 'admin'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ── Default broker (settings from the Excel "Settings" sheet) ──
        if (!PsxBroker::exists()) {
            PsxBroker::create([
                'name'                     => 'Default Broker',
                'min_commission_per_share' => 0.05,
                'commission_rate'          => 0.15,
                'intraday_buy_waiver'      => true,
                'is_default'               => true,
                'is_active'                => true,
                'remarks'                  => 'Rename and set the actual broker details.',
            ]);
        }

        // ── Stocks used in the existing Excel sheet ─────────────
        // Company names left blank where not confirmed; fill them in from the Stocks screen.
        $stocks = [
            'ANL' => 'Azgard Nine Ltd', 'ASTM' => null, 'BAPLR1' => null, 'BFBIO' => null,
            'CNERGY' => 'Cnergyico PK Ltd', 'CSIL' => null, 'DGKC' => 'D.G. Khan Cement Co. Ltd',
            'DSL' => 'Dost Steels Ltd', 'FCCL' => 'Fauji Cement Co. Ltd', 'FDPL' => null,
            'FECTC' => 'Fecto Cement Ltd', 'GCIL' => 'Ghani Chemical Industries Ltd',
            'HASCOL' => 'Hascol Petroleum Ltd', 'KEL' => 'K-Electric Ltd', 'KOSM' => null,
            'MACFL' => null, 'MLCF' => 'Maple Leaf Cement Factory Ltd', 'NCPL' => 'Nishat Chunian Power Ltd',
            'NETSOL' => 'NetSol Technologies Ltd', 'NGLEL' => null, 'NRL' => 'National Refinery Ltd',
            'OBOY' => null, 'PAEL' => 'Pak Elektron Ltd', 'PASL' => null,
            'PIBTL' => 'Pakistan International Bulk Terminal Ltd', 'PRL' => 'Pakistan Refinery Ltd',
            'PSO' => 'Pakistan State Oil Co. Ltd', 'SSGC' => 'Sui Southern Gas Co. Ltd',
            'TELE' => 'Telecard Ltd', 'THCCL' => 'Thatta Cement Co. Ltd', 'TISL' => null,
            'TPLP' => 'TPL Properties Ltd', 'TRG' => 'TRG Pakistan Ltd', 'WASL' => null, 'WAVESAPP' => null,
        ];

        foreach ($stocks as $symbol => $name) {
            PsxStock::firstOrCreate(['symbol' => $symbol], ['name' => $name, 'is_active' => true]);
        }
    }
}
