<?php

namespace App\DTOs;

class AcademicDataDTO
{
    public function __construct(
        public ?int $idHorario,
        public int $idSede,
        public string $nombreSede,
        public string $nomBloque,
        public string $nomAulaLab,
        public int $capacidadAula,
        public int $nroPupitres,
        public string $carrera, // Code: CARMED
        public string $materia, // Name: ANATOMIA...
        public string $siglaP,  // Code: MED-111
        public int $semestre,
        public string $docente, // Full Name
        public string $ci,      // ID
        public string $grupo,   // 1, A
        public string $tipoClase, // Teorico
        public string $dia,     // Lunes
        public string $horaInicio,
        public string $horaFin,
        public string $gestion, // 1-2026
        public ?string $planEst = null // N, A
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            idHorario: isset($data['idHorario']) ? (int) $data['idHorario'] : null,
            idSede: (int) ($data['idSede'] ?? 0),
            nombreSede: trim($data['nombreSede'] ?? ''),
            nomBloque: trim($data['nomBloque'] ?? 'Sin Bloque'),
            nomAulaLab: trim($data['nomAulaLab'] ?? 'Sin Aula'),
            capacidadAula: (int) ($data['capacidadAula'] ?? 0),
            nroPupitres: (int) ($data['nroPupitres'] ?? 0),
            carrera: trim($data['carrera'] ?? ''),
            materia: trim($data['materia'] ?? ''),
            siglaP: trim($data['siglaP'] ?? ''),
            semestre: (int) ($data['semestre'] ?? 1),
            docente: trim($data['docente'] ?? 'Sin Asignar'),
            ci: trim($data['ci'] ?? ''),
            grupo: trim($data['grupo'] ?? ''),
            tipoClase: trim($data['tipoClase'] ?? 'Teorico'),
            dia: trim($data['dia'] ?? ''),
            horaInicio: trim($data['horaInicio'] ?? '00:00'),
            horaFin: trim($data['horaFin'] ?? '00:00'),
            gestion: trim($data['gestion'] ?? ''),
            planEst: isset($data['planEst']) ? trim((string)$data['planEst']) : null
        );
    }
}
