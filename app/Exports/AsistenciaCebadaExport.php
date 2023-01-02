<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Maatwebsite\Excel\Concerns\FromCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class AsistenciaCebadaExport implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithMapping, WithEvents
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $query = DB::select("SELECT 
                                r.idRoute,
                                r.folio, 
                                r.empresa, 
                                r.att_for,
                                u.clave, 
                                u.nombreCompleto,
                                MAX(CASE WHEN att.att_type = 'ENTRADA' THEN att.date_time END) AS entrada,
                                MAX(CASE WHEN att.att_type = 'ENTRADA_BASCULA' THEN att.date_time END) AS entrada_bascula,
                                MAX(CASE WHEN att.att_type = 'SALIDA_BASCULA' THEN att.date_time END) AS salida_bascula,
                                MAX(CASE WHEN att.att_type = 'INICIO_DESENLONE' THEN att.date_time END) AS inicio_desenlone,
                                MAX(CASE WHEN att.att_type = 'SALIDA_DESENLONE' THEN att.date_time END) AS salida_desenlone,
                                MAX(CASE WHEN att.att_type = 'INICIO_MUESTREO' THEN att.date_time END) AS inicio_muestreo,
                                MAX(CASE WHEN att.att_type = 'SALIDA_MUESTREO' THEN att.date_time END) AS salida_muestreo,
                                MAX(CASE WHEN att.att_type = 'INICIO_DESCARGA' THEN att.date_time END) AS inicio_descarga,
                                MAX(CASE WHEN att.att_type = 'SALIDA_DESCARGA' THEN att.date_time END) AS salida_descarga,
                                MAX(CASE WHEN att.att_type = 'ENTRADA_BASCULA_2' THEN att.date_time END) AS entrada_bascula_2,
                                MAX(CASE WHEN att.att_type = 'SALIDA_BASCULA_2' THEN att.date_time END) AS salida_bascula_2,
                                MAX(CASE WHEN att.att_type = 'SALIDA' THEN att.date_time END) AS salida
                            FROM route r
                            INNER JOIN attendance att ON r.idRoute = att.idRoute
                            INNER JOIN user u ON u.idUser = r.FK_idUser
                            WHERE r.att_for = 'CEBADA'
                            GROUP BY r.idRoute
                            ORDER BY r.date_created DESC");
                      
        return collect($query);
    }

    //Mapear los datos para devolver los tiempos entre cada actividad
    public function map($row): array
    {
        return [
            $row->folio,
            $row->empresa,
            $row->att_for,
            $row->clave,
            $row->nombreCompleto,
            $row->entrada,
            $row->entrada_bascula,
            $row->salida_bascula,
            $row->inicio_desenlone,
            $row->salida_desenlone,
            $row->inicio_muestreo,
            $row->salida_muestreo,
            $row->inicio_descarga,
            $row->salida_descarga,
            $row->entrada_bascula_2,
            $row->salida_bascula_2,
            $row->salida,

            //Tiempo total que se toma en bascula
            $row->entrada_bascula != null && $row->salida_bascula != null ? CarbonInterval::seconds(Carbon::parse($row->entrada_bascula)->diffInSeconds(Carbon::parse($row->salida_bascula)))->cascade()->forHumans(null, true) : 'No disponible',
            
            //Tiempo que se toma en desenlone
            $row->inicio_desenlone != null && $row->salida_desenlone != null ? CarbonInterval::seconds(Carbon::parse($row->inicio_desenlone)->diffInSeconds(Carbon::parse($row->salida_desenlone)))->cascade()->forHumans(null, true) : 'No disponible',
            
            //Tiempo que se toma en muestreo
            $row->inicio_muestreo != null && $row->salida_muestreo != null ? CarbonInterval::seconds(Carbon::parse($row->inicio_muestreo)->diffInSeconds(Carbon::parse($row->salida_muestreo)))->cascade()->forHumans(null, true) : 'No disponible',
            
            //Tiempo que se toma en la descarga
            $row->inicio_descarga != null && $row->salida_descarga != null ? CarbonInterval::seconds(Carbon::parse($row->inicio_descarga)->diffInSeconds(Carbon::parse($row->salida_descarga)))->cascade()->forHumans(null, true) : 'No disponible',
            
            //Tiempo que se toman en la segunda vuelta a báscula
            $row->entrada_bascula_2 != null && $row->salida_bascula_2 != null ? CarbonInterval::seconds(Carbon::parse($row->entrada_bascula_2)->diffInSeconds(Carbon::parse($row->salida_bascula_2)))->cascade()->forHumans(null, true) : 'No disponible',
            
            //Tiempo total dentro de planta
            $row->entrada != null && $row->salida != null ? CarbonInterval::seconds(Carbon::parse($row->entrada)->diffInSeconds(Carbon::parse($row->salida)))->cascade()->forHumans(null, true) : 'No disponible'

        ];
    }

    public function headings(): array
    {
        return [
            "FOLIO",
            "EMPRESA TRANSPORTISTA",
            "PARA",
            "CLAVE DEL CHÓFER",
            "NOMBRE DEL CHÓFER",
            "ENTRADA A PLANTA",
            "ENTRADA A BÁSCULA",
            "SALIDA DE BÁSCULA",
            "INICIO DE DESENLONE",
            "SALIDA DEL DESENLONE",
            "INICIO DE MUESTREO",
            "SALIDA DE MUESTREO",
            "INICIO DE DESCARGA",
            "SALIDA DE DESCARGA",
            "SEGUNDA ENTRADA A BÁSCULA",
            "SEGUNDA SALIDA DE BÁSCULA",
            "SALIDA DE PLANTA",
            "TIEMPO EN BÁSCULA",
            "TIEMPO EN DESENLONE",
            "TIEMPO EN MUESTREO",
            "TIEMPO EN DESCARGA",
            "TIEMPO EN SEGUNDA VUELTA A BÁSCULA",
            "TIEMPO TOTAL EN PLANTA"
        ];
    }

    public function title(): string
    {
        return 'CEBADA';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $event->sheet->getDelegate()->getStyle('A1:W1')
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('DD4B39');
            }
        ];
    }
}
