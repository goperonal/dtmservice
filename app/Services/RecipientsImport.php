<?php

namespace App\Services;

use App\Models\Recipient;
use App\Models\Group;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class RecipientsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        // Simpan atau update recipient
        $recipient = Recipient::updateOrCreate(
            ['phone' => $row['phone']], // unik
            [
                'name'  => $row['name'] ?? null,
                'notes' => $row['notes'] ?? null,
            ]
        );

        // Kalau ada kolom groups
        if (!empty($row['groups'])) {
            // Pisahkan dengan koma → array
            $groupNames = array_map('trim', explode(',', $row['groups']));

            $groupIds = [];

            foreach ($groupNames as $groupName) {
                if ($groupName === '') continue;

                // Buat group kalau belum ada
                $group = Group::firstOrCreate(['name' => $groupName]);

                $groupIds[] = $group->id;
            }

            // Gabungkan dengan group lama, jangan overwrite
            $recipient->groups()->syncWithoutDetaching($groupIds);
        }

        return $recipient;
    }
}
