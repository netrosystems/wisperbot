<?php

namespace Database\Seeders;

use App\Models\Locale;
use Illuminate\Database\Seeder;

class LocaleSeeder extends Seeder
{
    /**
     * Seed default 5 languages: English (default), Bangla, Arabic (RTL), Hindi, French.
     * Idempotent: updateOrCreate by code.
     */
    public function run(): void
    {
        $defaultCode = 'en';

        $locales = [
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'flag' => null,
                'enabled' => true,
                'is_default' => true,
                'is_rtl' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'bn',
                'name' => 'Bangla',
                'native_name' => 'বাংলা',
                'flag' => null,
                'enabled' => true,
                'is_default' => false,
                'is_rtl' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'ar',
                'name' => 'Arabic',
                'native_name' => 'العربية',
                'flag' => null,
                'enabled' => true,
                'is_default' => false,
                'is_rtl' => true,
                'sort_order' => 3,
            ],
            [
                'code' => 'hi',
                'name' => 'Hindi',
                'native_name' => 'हिन्दी',
                'flag' => null,
                'enabled' => true,
                'is_default' => false,
                'is_rtl' => false,
                'sort_order' => 4,
            ],
            [
                'code' => 'fr',
                'name' => 'French',
                'native_name' => 'Français',
                'flag' => null,
                'enabled' => true,
                'is_default' => false,
                'is_rtl' => false,
                'sort_order' => 5,
            ],
        ];

        // Ensure only one default
        Locale::where('is_default', true)->update(['is_default' => false]);

        foreach ($locales as $row) {
            Locale::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}
