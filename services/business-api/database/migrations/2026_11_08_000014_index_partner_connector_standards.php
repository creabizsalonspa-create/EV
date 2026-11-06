<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add a derived public search field, preserving raw source hashes and timestamps.
        DB::table('ocpi_records')->where('module', 'locations')->select(['id', 'normalized'])->orderBy('id')->chunkById(100, function ($records) {
            foreach ($records as $record) {
                $n = json_decode($record->normalized, true, flags: JSON_THROW_ON_ERROR);
                $standards = [];
                foreach ($n['evses'] ?? [] as $evse) {
                    foreach ($evse['connectors'] ?? [] as $connector) {
                        if (is_string($connector['standard'] ?? null)) {
                            $standards[] = $connector['standard'];
                        }
                    }
                }
                $n['connector_standards'] = array_values(array_unique($standards));
                sort($n['connector_standards'], SORT_STRING);
                DB::table('ocpi_records')->where('id', $record->id)->update(['normalized' => json_encode($n, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        // The additive derived field remains compatible with older readers.
    }
};
