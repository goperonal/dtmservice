<?php

namespace App\Services;

use App\Models\Recipient;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class RecipientsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return Recipient::updateOrCreate(
            ['phone' => $row['phone']], // kolom unik
            [
                'name'   => $row['name'] ?? null,
                'group'  => $row['group'] ?? null,
                'notes'  => $row['notes'] ?? null,
            ]
        );
    }
}
