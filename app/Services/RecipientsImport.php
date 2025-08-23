<?php

namespace App\Services;

use App\Models\Recipient;
use App\Models\Group;
use Illuminate\Contracts\Queue\ShouldQueue;

// Interfaces/concerns dari Laravel Excel
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\Importable;

// Trait yang HARUS dari namespace paket, bukan App\Services
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsErrors;

class RecipientsImport implements
    ToModel,
    WithHeadingRow,
    ShouldQueue,
    WithChunkReading,
    WithBatchInserts,
    WithValidation,
    SkipsOnFailure,
    SkipsOnError
{
    use Importable, SkipsFailures, SkipsErrors;

    public function model(array $row)
    {
        $recipient = Recipient::updateOrCreate(
            ['phone' => $row['phone'] ?? null],
            [
                'name'  => $row['name']  ?? null,
                'notes' => $row['notes'] ?? null,
            ]
        );

        if (!empty($row['groups'])) {
            $groupNames = array_map('trim', explode(',', $row['groups']));
            $groupIds = [];
            foreach ($groupNames as $groupName) {
                if ($groupName === '') continue;
                $group = Group::firstOrCreate(['name' => $groupName]);
                $groupIds[] = $group->id;
            }
            $recipient->groups()->syncWithoutDetaching($groupIds);
        }

        return $recipient;
    }

    // Validasi per-baris; baris invalid akan diskip (thanks to SkipsFailures/SkipsErrors)
    public function rules(): array
    {
        return [
            '*.phone' => ['required', 'regex:/^628[0-9]{7,12}$/'],
            '*.name'  => ['required', 'max:100'],
        ];
    }

    // Baca & insert per potongan untuk efisiensi
    public function chunkSize(): int { return 1000; }
    public function batchSize(): int { return 1000; }
}
