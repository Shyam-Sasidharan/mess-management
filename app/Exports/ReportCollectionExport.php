<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ReportCollectionExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private Collection $rows, private array $columns) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => array_values($row));
    }

    public function headings(): array
    {
        return $this->columns;
    }
}
