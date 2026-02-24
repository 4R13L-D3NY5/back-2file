<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Informe de Cumplimiento Micro-curricular</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; line-height: 1.4; margin: 0; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #1976d2; padding-bottom: 10px; margin-bottom: 20px; position: relative; }
        .logo { position: absolute; left: 0; top: 0; width: 120px; }
        .title { font-size: 18px; font-weight: bold; color: #1976d2; text-transform: uppercase; }
        .subtitle { font-size: 14px; color: #666; }
        
        .meta-container { background: #f5f5f5; border: 1px solid #ddd; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .meta-row { display: flex; flex-wrap: wrap; margin-bottom: 5px; }
        .meta-item { flex: 1; min-width: 200px; }
        .meta-label { font-size: 10px; color: #777; text-transform: uppercase; font-weight: bold; }
        .meta-value { font-size: 14px; font-weight: 500; }

        .alert-badge { 
            float: right; padding: 5px 15px; border-radius: 20px; color: white; font-weight: bold; font-size: 12px;
            margin-top: -5px;
        }
        .bg-verde { background-color: #4caf50; }
        .bg-amarillo { background-color: #fb8c00; }
        .bg-rojo { background-color: #f44336; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; }
        th { background: #eee; padding: 10px; text-align: left; border: 1px solid #ddd; }
        td { padding: 8px 10px; border: 1px solid #ddd; vertical-align: top; }
        .text-center { text-align: center; }
        .text-weight-bold { font-weight: bold; }
        
        .obs-box { background: #fff; padding: 5px; border: 1px solid #eee; min-height: 40px; font-style: italic; color: #555; }
        
        .footer { margin-top: 50px; }
        .signature-container { display: flex; justify-content: space-around; margin-top: 80px; }
        .signature-line { border-top: 1px solid #000; width: 200px; text-align: center; padding-top: 5px; font-size: 12px; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
            .print-btn { display: none; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
        }
        
        .print-btn { 
            background: #1976d2; color: white; border: none; padding: 10px 20px; 
            border-radius: 4px; cursor: pointer; margin-bottom: 10px; font-weight: bold;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">Imprimir Informe</button>

    <div class="header">
        <div class="title">Informe de Cumplimiento Micro-curricular</div>
        <div class="subtitle">Verificación técnica de cumplimiento semanal - NIVEL 2</div>
    </div>

    <div class="meta-container">
        <div class="alert-badge bg-{{ strtolower($report['escala_alerta']) }}">
            {{ $report['escala_alerta'] }} ({{ $report['cumplimiento_porcentaje'] }}%)
        </div>
        <div class="meta-row">
            <div class="meta-item">
                <div class="meta-label">Docente</div>
                <div class="meta-value">{{ $report['docente_nombre'] }}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Asignatura</div>
                <div class="meta-value">{{ $report['asignatura_nombre'] }}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Semana de Control</div>
                <div class="meta-value">
                    {{ \Carbon\Carbon::parse($report['semana_inicio'])->format('d/m/Y') }} al 
                    {{ \Carbon\Carbon::parse($report['semana_fin'])->format('d/m/Y') }}
                </div>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 40%">Criterio de Verificación</th>
                <th style="width: 15%" class="text-center">Cumple</th>
                <th>Observaciones / Hallazgos</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['criterios'] as $nombre => $data)
            <tr>
                <td>{{ $nombre }}</td>
                <td class="text-center">
                    <strong>{{ $data['cumple'] ? 'SÍ' : 'NO' }}</strong>
                </td>
                <td>
                    <div class="obs-box">{{ $data['obs'] ?? 'Sin observaciones' }}</div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="meta-container" style="background: white;">
        <div class="meta-label">Conclusiones y Recomendaciones del Director de Carrera</div>
        <div style="margin-top: 10px; min-height: 60px; border: 1px solid #eee; padding: 10px;">
            {{ $report['observaciones'] ?: 'Sin conclusiones registradas.' }}
        </div>
    </div>

    <div class="footer">
        <div class="signature-container">
            <div class="signature-line">
                Docente<br>
                <small>{{ $report['docente_nombre'] }}</small>
            </div>
            <div class="signature-line">
                Director de Carrera<br>
                <small>Firma y Sello</small>
            </div>
        </div>
        <div style="text-align: center; margin-top: 40px; font-size: 10px; color: #999;">
            Documento generado digitalmente por el Sistema de Planificación UNITEPC - {{ date('d/m/Y H:i') }}
        </div>
    </div>
</body>
</html>
