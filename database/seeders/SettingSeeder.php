<?php

namespace Database\Seeders;

use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);

        foreach (SettingsService::DEFAULTS as $group => $values) {
            foreach ($values as $key => $value) {
                $settings->set($group, $key, $value, 'string', null);
            }
        }
    }
}
