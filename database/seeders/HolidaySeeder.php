<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    /**
     * Seed the national Indonesian public holidays (Hari Libur Nasional).
     *
     * Each is stored as a GLOBAL holiday (projects_id = null) so it shows on
     * every branch. Islamic/lunar dates follow the government SKB and may still
     * be adjusted by an admin once the final decree is issued.
     *
     * Idempotent: an existing global holiday on the same day is left untouched,
     * so this can be re-run safely (e.g. after adding a new year).
     */
    public function run(): void
    {
        $holidays = [
            // ---- 2026 ----
            ['2026-01-01', 'Tahun Baru Masehi'],
            ['2026-01-16', 'Isra Mikraj Nabi Muhammad SAW'],
            ['2026-02-17', 'Tahun Baru Imlek 2577 Kongzili'],
            ['2026-03-19', 'Hari Suci Nyepi Tahun Baru Saka 1948'],
            ['2026-03-21', 'Hari Raya Idul Fitri 1447 Hijriah'],
            ['2026-03-22', 'Hari Raya Idul Fitri 1447 Hijriah'],
            ['2026-04-03', 'Wafat Isa Almasih'],
            ['2026-05-01', 'Hari Buruh Internasional'],
            ['2026-05-14', 'Kenaikan Isa Almasih'],
            ['2026-05-27', 'Hari Raya Idul Adha 1447 Hijriah'],
            ['2026-05-31', 'Hari Raya Waisak 2570'],
            ['2026-06-01', 'Hari Lahir Pancasila'],
            ['2026-06-17', 'Tahun Baru Islam 1448 Hijriah'],
            ['2026-08-17', 'Hari Kemerdekaan Republik Indonesia'],
            ['2026-08-25', 'Maulid Nabi Muhammad SAW'],
            ['2026-12-25', 'Hari Raya Natal'],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($holidays as [$date, $name]) {
            $ts = strtotime($date);
            $dayStart = strtotime(date('Y-m-d', $ts));
            $dayEnd = strtotime(date('Y-m-d', $ts).' +1 day');

            // Skip if a global holiday already exists on this day (keep notDeleted
            // scope, drop the branch scope so we see the null-branch rows).
            $exists = Holiday::withoutBranchScope()
                ->where('date', '>=', $dayStart)
                ->where('date', '<', $dayEnd)
                ->whereNull('projects_id')
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            // withoutEvents skips BranchScoped's creating hook, which would
            // otherwise overwrite the intended null projects_id.
            Holiday::withoutEvents(fn () => Holiday::create([
                'date' => $ts,
                'name' => $name,
                'description' => 'Libur Nasional',
                'projects_id' => null,
                'created_by' => null,
            ]));

            $created++;
        }

        $this->command?->info("HolidaySeeder selesai: {$created} ditambahkan, {$skipped} dilewati (sudah ada).");
    }
}
