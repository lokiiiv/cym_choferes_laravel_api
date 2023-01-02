<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AsistenciaExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @return array
     */

     public function sheets(): array {
        $sheets = [];

        array_push($sheets, new AsistenciaCebadaExport());
        array_push($sheets, new AsistenciaMaltaExport());
        array_push($sheets, new AsistenciaCoproductoExport());
        return $sheets;
     }
}
