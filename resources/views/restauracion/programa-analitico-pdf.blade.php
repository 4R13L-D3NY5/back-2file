<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Programa Analitico - {{ $asignatura['codigo'] ?? '' }}</title>
    <style>
        @page {
            margin: 90px 50px 70px 50px;
            header: pdf-header;
            footer: pdf-footer;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }

        .pdf-header {
            position: fixed;
            top: -70px;
            left: 0;
            right: 0;
            height: 60px;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 6px;
        }
        .pdf-header .left {
            font-size: 9pt;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .pdf-header .right {
            font-size: 9pt;
            color: #1e40af;
            font-weight: bold;
            text-align: right;
        }
        .pdf-header .title {
            font-size: 13pt;
            color: #1e3a8a;
            font-weight: bold;
            margin-top: 2px;
        }

        .pdf-footer {
            position: fixed;
            bottom: -50px;
            left: 0;
            right: 0;
            height: 30px;
            border-top: 1px solid #d1d5db;
            padding-top: 4px;
            font-size: 8.5pt;
            color: #6b7280;
        }
        .pdf-footer .left { float: left; }
        .pdf-footer .right { float: right; }
        .pdf-footer .center { display: block; text-align: center; }
        .pdf-footer .page-num:after { content: counter(page) " / " counter(pages); }

        .cover {
            page-break-after: always;
            padding-top: 80px;
            text-align: center;
        }
        .cover .badge {
            display: inline-block;
            background: #1e40af;
            color: white;
            padding: 8px 20px;
            font-size: 11pt;
            font-weight: bold;
            border-radius: 4px;
            letter-spacing: 2px;
            margin-bottom: 30px;
        }
        .cover .label {
            font-size: 10pt;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 8px;
        }
        .cover h1 {
            font-size: 26pt;
            color: #1e3a8a;
            margin: 0 0 12px 0;
            font-weight: bold;
        }
        .cover .subtitle {
            font-size: 14pt;
            color: #374151;
            margin-bottom: 50px;
        }
        .cover .info-box {
            border: 2px solid #1e40af;
            border-radius: 6px;
            padding: 18px 24px;
            margin: 0 auto 30px auto;
            width: 75%;
            background: #eff6ff;
        }
        .cover .info-box table { width: 100%; border-collapse: collapse; }
        .cover .info-box td {
            padding: 6px 8px;
            text-align: left;
            font-size: 10.5pt;
            color: #1f2937;
        }
        .cover .info-box td.label {
            font-weight: bold;
            color: #1e3a8a;
            width: 38%;
            text-transform: uppercase;
            font-size: 8.5pt;
            letter-spacing: 0.5px;
        }
        .cover .footer-note {
            margin-top: 60px;
            font-size: 9pt;
            color: #6b7280;
        }

        .index { page-break-after: always; padding-top: 10px; }
        .index h2 {
            color: #1e3a8a;
            font-size: 18pt;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 8px;
            margin-bottom: 18px;
        }
        .index ol { font-size: 11pt; line-height: 1.9; }
        .index ol li { margin-bottom: 4px; }
        .index ol li b { color: #1e3a8a; }
        .index .indice-tema {
            margin-left: 25px;
            font-size: 10pt;
            color: #4b5563;
        }
        .index .indice-tema-2 {
            margin-left: 50px;
            font-size: 9.5pt;
            color: #6b7280;
        }

        .section { margin-top: 22px; page-break-inside: avoid; }
        .section h2 {
            color: #1e3a8a;
            font-size: 16pt;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 6px;
            margin-bottom: 14px;
        }
        .section h3 {
            color: #166534;
            font-size: 13pt;
            border-left: 6px solid #16a34a;
            padding-left: 10px;
            margin-top: 20px;
            margin-bottom: 10px;
            background: #f0fdf4;
            padding-top: 6px;
            padding-bottom: 6px;
        }
        .section h4 {
            color: #0e7490;
            font-size: 11.5pt;
            margin-top: 14px;
            margin-bottom: 6px;
        }
        .section h4 .badge-tema {
            background: #0891b2;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 9pt;
            margin-right: 8px;
        }
        .section h5 {
            color: #7c3aed;
            font-size: 10.5pt;
            margin: 10px 0 4px 0;
            padding-left: 14px;
            border-left: 3px solid #c4b5fd;
        }

        .unidad-block {
            border: 1.5px solid #16a34a;
            border-radius: 6px;
            padding: 14px 18px;
            margin-top: 22px;
            background: #f7fee7;
            page-break-inside: avoid;
        }
        .unidad-block h3 {
            background: transparent;
            color: #14532d;
            font-size: 14pt;
            border-left: 8px solid #15803d;
            padding: 4px 0 4px 12px;
            margin: 0 0 10px 0;
        }
        .tema-block {
            border: 1px solid #0891b2;
            border-radius: 5px;
            padding: 12px 16px;
            margin: 12px 0 12px 18px;
            background: #ecfeff;
            page-break-inside: avoid;
        }
        .tema-block h4 {
            color: #155e75;
            font-size: 11.5pt;
            margin: 0 0 6px 0;
            padding-bottom: 4px;
            border-bottom: 1px dashed #67e8f9;
        }
        .logro-block {
            border: 1px solid #a78bfa;
            border-radius: 4px;
            padding: 8px 12px;
            margin: 6px 0 6px 18px;
            background: #f5f3ff;
        }
        .logro-block h5 {
            color: #5b21b6;
            font-size: 10pt;
            margin: 0 0 4px 0;
            border-left: 3px solid #7c3aed;
            padding-left: 8px;
        }
        .indicador-item {
            margin-left: 16px;
            font-size: 9.5pt;
            color: #4b5563;
        }
        .sub-bloque {
            border: 1px dashed #d1d5db;
            border-radius: 4px;
            padding: 8px 12px;
            margin: 8px 0 8px 18px;
            background: #fafafa;
        }
        .sub-bloque .titulo {
            font-size: 9.5pt;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
            font-size: 10pt;
        }
        .info-table th, .info-table td {
            border: 1px solid #d1d5db;
            padding: 6px 9px;
            text-align: left;
            vertical-align: top;
        }
        .info-table th {
            background: #1e40af;
            color: white;
            font-weight: bold;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .info-table tr:nth-child(even) td { background: #f9fafb; }

        .kv-table {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0 10px 0;
        }
        .kv-table td {
            padding: 4px 6px;
            vertical-align: top;
            font-size: 10pt;
        }
        .kv-table td.k {
            font-weight: bold;
            color: #1e3a8a;
            width: 35%;
            text-transform: uppercase;
            font-size: 8.5pt;
            letter-spacing: 0.4px;
        }

        .grid-3 { width: 100%; border-collapse: collapse; }
        .grid-3 td.col { width: 33.3%; vertical-align: top; padding: 0 6px; }
        .grid-3 td.col-first { padding-left: 0; }
        .grid-3 td.col-last { padding-right: 0; }
        .col-label {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0e7490;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 4px;
            border-bottom: 1px solid #67e8f9;
            padding-bottom: 2px;
        }

        .value-list {
            margin: 4px 0 8px 0;
            padding-left: 20px;
        }
        .value-list li {
            margin-bottom: 3px;
            font-size: 10pt;
        }

        .tag {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 3px;
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .tag-tipo { background: #dbeafe; color: #1e3a8a; }
        .tag-periodo { background: #fef3c7; color: #78350f; }
        .tag-bloque { background: #e0e7ff; color: #3730a3; }

        .empty-value { color: #9ca3af; font-style: italic; }
        .page-break { page-break-before: always; }

        .value-paragraph { margin: 4px 0 8px 0; font-size: 10pt; }

        .mixed-block {
            margin: 6px 0;
        }
        .mixed-block .text-item {
            margin: 4px 0 8px 0;
            font-size: 10pt;
            color: #1f2937;
        }
        .mixed-block .object-item {
            margin: 6px 0;
            padding: 6px 10px;
            background: #f9fafb;
            border-left: 3px solid #9ca3af;
            font-size: 9.5pt;
        }
        .mixed-block .object-item .k {
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            font-size: 8pt;
            margin-right: 4px;
        }
    </style>
</head>
<body>
    @php
        $asig = $asignatura ?? [];
        $unidades = $asig['unidades'] ?? [];
        $bibliografias = $asig['bibliografias'] ?? [];
        $docentes = $asig['docentes'] ?? [];
        $progreso = $asig['progreso'] ?? null;

        $unidadesCount = count($unidades);
        $temasCount = collect($unidades)->sum(fn($u) => count($u['temas'] ?? []));
        $logrosCount = collect($unidades)->sum(function ($u) {
            return collect($u['temas'] ?? [])->sum(fn($t) => count($t['logros_esperados'] ?? []));
        });
        $indicadoresCount = collect($unidades)->sum(function ($u) {
            return collect($u['temas'] ?? [])->sum(function ($t) {
                return collect($t['logros_esperados'] ?? [])->sum(fn($l) => count($l['indicadores'] ?? []));
            });
        });

        // =================================================================
        // PARSER: convierte cualquier valor en una estructura normalizada
        // =================================================================
        $parseValue = function ($value) use (&$parseValue) {
            if ($value === null || $value === '') {
                return ['type' => 'empty'];
            }
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return ['type' => 'empty'];
                }
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $parseValue($decoded);
                }
                return ['type' => 'text', 'value' => $value];
            }
            if (is_object($value)) {
                return $parseValue((array) $value);
            }
            if (is_array($value)) {
                $arr = array_filter($value, fn($v) => $v !== null && $v !== '');
                if (empty($arr)) {
                    return ['type' => 'empty'];
                }
                $values = array_values($arr);

                if (!array_is_list($arr)) {
                    $kvItems = [];
                    foreach ($arr as $k => $v) {
                        $kvItems[] = ['key' => (string) $k, 'value' => $v];
                    }
                    return ['type' => 'kv', 'items' => $kvItems];
                }

                $isAllScalar = true;
                foreach ($values as $item) {
                    if (is_array($item) || is_object($item)) { $isAllScalar = false; break; }
                }
                if ($isAllScalar) {
                    return ['type' => 'list', 'items' => $values];
                }

                $looksLikeSecuencia = false;
                $firstItem = $values[0];
                if (is_array($firstItem) || is_object($firstItem)) {
                    $firstArr = is_object($firstItem) ? (array) $firstItem : $firstItem;
                    if (isset($firstArr['momento']) || isset($firstArr['actividad']) || isset($firstArr['duracion']) || isset($firstArr['duracion_minutos'])) {
                        $looksLikeSecuencia = true;
                    }
                }
                if ($looksLikeSecuencia) {
                    $items = array_map(function ($i) use ($parseValue) {
                        if (is_object($i)) $i = (array) $i;
                        return [
                            'momento' => $i['momento'] ?? 'Desarrollo',
                            'actividad' => $i['actividad'] ?? $i['descripcion'] ?? '',
                            'duracion' => $i['duracion_minutos'] ?? $i['duracion'] ?? null,
                        ];
                    }, $values);
                    return ['type' => 'secuencias', 'items' => $items];
                }

                $items = array_map(function ($i) use ($parseValue) {
                    if (is_object($i)) $i = (array) $i;
                    return $parseValue($i);
                }, $values);
                return ['type' => 'mixed', 'items' => $items];
            }
            return ['type' => 'text', 'value' => (string) $value];
        };

        // =================================================================
        // RENDERER: convierte estructura normalizada a HTML
        // =================================================================
        $renderValue = function ($value) use (&$renderValue, $parseValue) {
            $parsed = $parseValue($value);
            switch ($parsed['type']) {
                case 'empty':
                    return '<span class="empty-value">Sin dato</span>';

                case 'text':
                    $text = str_replace(["\r\n", '\n', '\r\n'], ["\n", "\n", "\n"], $parsed['value']);
                    return '<div class="value-paragraph">' . nl2br(e($text)) . '</div>';

                case 'list':
                    $html = '<ul class="value-list">';
                    foreach ($parsed['items'] as $item) {
                        $text = str_replace(["\r\n", '\n', '\r\n'], ["\n", "\n", "\n"], (string) $item);
                        $html .= '<li>' . nl2br(e($text)) . '</li>';
                    }
                    $html .= '</ul>';
                    return $html;

                case 'secuencias':
                    $html = '<table class="info-table"><thead><tr>'
                          . '<th style="width:22%;">Momento</th>'
                          . '<th>Actividad</th>'
                          . '<th style="width:80px;">Duracion (min)</th>'
                          . '</tr></thead><tbody>';
                    foreach ($parsed['items'] as $sec) {
                        $momento = $sec['momento'] ?? 'Desarrollo';
                        $actividad = str_replace(["\r\n", '\n', '\r\n'], ["\n", "\n", "\n"], (string) ($sec['actividad'] ?? ''));
                        $dur = $sec['duracion'];
                        $durStr = ($dur === null || $dur === '') ? '-' : (string) $dur;
                        $html .= '<tr>'
                              . '<td><b>' . e($momento) . '</b></td>'
                              . '<td>' . nl2br(e($actividad)) . '</td>'
                              . '<td style="text-align:center;">' . e($durStr) . '</td>'
                              . '</tr>';
                    }
                    $html .= '</tbody></table>';
                    return $html;

                case 'kv':
                    $html = '<table class="kv-table">';
                    foreach ($parsed['items'] as $pair) {
                        $key = ucwords(str_replace('_', ' ', $pair['key']));
                        $val = $renderValue($pair['value']);
                        $html .= '<tr><td class="k">' . e($key) . '</td><td>' . $val . '</td></tr>';
                    }
                    $html .= '</table>';
                    return $html;

                case 'mixed':
                    $html = '<div class="mixed-block">';
                    foreach ($parsed['items'] as $sub) {
                        if ($sub['type'] === 'text') {
                            $text = str_replace(["\r\n", '\n', '\r\n'], ["\n", "\n", "\n"], $sub['value']);
                            $html .= '<div class="text-item">' . nl2br(e($text)) . '</div>';
                        } elseif ($sub['type'] === 'secuencias') {
                            $html .= $renderValue(['__raw' => $sub]);
                        } else {
                            $html .= '<div class="object-item">';
                            if ($sub['type'] === 'kv') {
                                $html .= '<table class="kv-table" style="margin:0;">';
                                foreach ($sub['items'] as $pair) {
                                    $key = ucwords(str_replace('_', ' ', $pair['key']));
                                    $html .= '<tr><td class="k" style="width:30%;">' . e($key) . '</td><td>' . $renderValue($pair['value']) . '</td></tr>';
                                }
                                $html .= '</table>';
                            } else {
                                $html .= $renderValue(['__raw' => $sub]);
                            }
                            $html .= '</div>';
                        }
                    }
                    $html .= '</div>';
                    return $html;

                case 'raw':
                    return $renderValue($parsed['value'] ?? null);
            }

            if (isset($parsed['__raw'])) {
                return $renderValue(['__raw' => $parsed['__raw']]);
            }

            return '<span class="empty-value">Sin dato</span>';
        };
    @endphp

    <htmlpageheader name="pdf-header">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="text-align:left; vertical-align:bottom;">
                    <div class="left">DOCUMENTACION ACADEMICA</div>
                    <div class="title">{{ $asig['codigo'] ?? '' }} - {{ Str::limit($asig['nombre'] ?? '', 60) }}</div>
                </td>
                <td style="text-align:right; vertical-align:bottom;">
                    <div class="right">PROGRAMA ANALITICO</div>
                </td>
            </tr>
        </table>
    </htmlpageheader>

    <htmlpagefooter name="pdf-footer">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td class="left" style="text-align:left;">Generado: {{ $generado_en ?? now()->format('d/m/Y H:i') }}</td>
                <td class="center" style="text-align:center;">Programa Analitico - {{ $asig['codigo'] ?? '' }}</td>
                <td class="right page-num" style="text-align:right;"></td>
            </tr>
        </table>
    </htmlpagefooter>

    {{-- =================== PORTADA =================== --}}
    <div class="cover">
        <div class="badge">PROGRAMA ANALITICO</div>

        <div class="label">Materia</div>
        <h1>{{ $asig['codigo'] ?? 'Sin codigo' }}</h1>
        <div class="subtitle">{{ $asig['nombre'] ?? 'Sin nombre' }}</div>

        <div class="info-box">
            <table>
                <tr><td class="label">Carrera</td><td>{{ $asig['carrera']['nombre'] ?? 'N/D' }}</td></tr>
                <tr><td class="label">Sede</td><td>{{ $asig['carrera']['sede'] ?? 'N/D' }}</td></tr>
                <tr><td class="label">Plan de estudios</td><td>{{ $asig['plan_estudios'] ?? 'N/D' }}</td></tr>
                <tr><td class="label">Semestre</td><td>{{ $asig['semestre'] ?? 'N/D' }}</td></tr>
                <tr><td class="label">Creditos</td><td>{{ $asig['creditos'] ?? 'N/D' }}</td></tr>
                <tr>
                    <td class="label">Contenido</td>
                    <td>
                        <b>{{ $unidadesCount }}</b> unidades |
                        <b>{{ $temasCount }}</b> temas |
                        <b>{{ $logrosCount }}</b> logros |
                        <b>{{ $indicadoresCount }}</b> indicadores
                    </td>
                </tr>
            </table>
        </div>

        <div class="footer-note">
            Documento generado desde el modulo de Restauracion Academica.<br>
            Estructura jerarquica:<br>
            <b>Asignatura</b> -&gt; <b>Unidad</b> -&gt; <b>Tema</b> -&gt; <b>Logro</b> -&gt; <b>Indicador</b>
        </div>
    </div>

    {{-- =================== INDICE =================== --}}
    <div class="index">
        <h2>Indice</h2>
        <ol>
            <li><b>Datos generales</b> de la asignatura</li>
            <li><b>Unidades, temas y estructura</b>
                <ol style="list-style-type: lower-alpha;">
                    @foreach ($unidades as $unidad)
                        <li>
                            <b>Unidad {{ $unidad['numero'] ?? '' }}:</b>
                            {{ Str::limit($unidad['titulo'] ?? 'Sin titulo', 70) }}
                            <ul class="indice-tema" style="list-style: none; padding-left: 14px;">
                                @foreach (($unidad['temas'] ?? []) as $tema)
                                    <li class="indice-tema-2">
                                        -&gt; Tema {{ $unidad['numero'] }}.{{ $tema['orden'] ?? '' }}:
                                        {{ Str::limit($tema['titulo'] ?? 'Sin titulo', 60) }}
                                        @if (!empty($tema['logros_esperados']))
                                            <span style="color:#7c3aed;">
                                                [{{ count($tema['logros_esperados']) }} logro(s)]
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
            </li>
            <li><b>Bibliografia general</b> de la asignatura</li>
            <li><b>Docentes</b> asignados</li>
            @if (!empty($progreso) && is_array($progreso))
                <li><b>Progreso</b> de documentacion</li>
            @endif
        </ol>
    </div>

    {{-- =================== 1. DATOS GENERALES =================== --}}
    <div class="section">
        <h2>1. Datos generales de la asignatura</h2>
        <p style="color:#6b7280; font-size:9.5pt; margin-top:-6px;">
            Informacion base que define el programa. Se aplica a toda la asignatura.
        </p>

        <table class="kv-table">
            <tr><td class="k">Codigo</td><td>{{ $asig['codigo'] ?? 'N/D' }}</td></tr>
            <tr><td class="k">Nombre</td><td>{{ $asig['nombre'] ?? 'N/D' }}</td></tr>
            <tr><td class="k">Sigla</td><td>{{ $asig['sigla'] ?? ($asig['codigo'] ?? 'N/D') }}</td></tr>
            <tr>
                <td class="k">Carrera</td>
                <td>{{ $asig['carrera']['nombre'] ?? 'N/D' }} @if(!empty($asig['carrera']['sede'])) - Sede {{ $asig['carrera']['sede'] }} @endif</td>
            </tr>
            <tr>
                <td class="k">Plan de estudios / Semestre</td>
                <td>{{ $asig['plan_estudios'] ?? 'N/D' }} / Semestre {{ $asig['semestre'] ?? 'N/D' }}</td>
            </tr>
            <tr><td class="k">Creditos</td><td>{{ $asig['creditos'] ?? 'N/D' }}</td></tr>
        </table>

        <h4>Descripcion</h4>
        {!! $renderValue($asig['descripcion'] ?? null) !!}

        <h4>Justificacion</h4>
        {!! $renderValue($asig['justificacion'] ?? null) !!}

        <h4>Proposito general</h4>
        {!! $renderValue($asig['proposito_general'] ?? null) !!}

        <h4>Competencia de la asignatura</h4>
        {!! $renderValue($asig['competencia_asignatura'] ?? null) !!}

        <h4>Competencia global / especifica</h4>
        {!! $renderValue($asig['competencia_global_especifica'] ?? null) !!}

        <h4>Elementos de competencia</h4>
        {!! $renderValue($asig['elementos_competencia'] ?? null) !!}

        <h4>Contenido minimo</h4>
        {!! $renderValue($asig['contenido_minimo'] ?? null) !!}

        <h4>Metodologia general</h4>
        {!! $renderValue($asig['metodologia_general'] ?? null) !!}

        <h4>Sistema de evaluacion</h4>
        {!! $renderValue($asig['sistema_evaluacion'] ?? null) !!}

        <h4>Requisitos</h4>
        {!! $renderValue($asig['requisitos'] ?? null) !!}
    </div>

    {{-- =================== 2. UNIDADES Y TEMAS =================== --}}
    <div class="section page-break">
        <h2>2. Unidades, temas y estructura</h2>
        <p style="color:#6b7280; font-size:9.5pt; margin-top:-6px;">
            Cada <b>unidad</b> contiene uno o mas <b>temas</b>. Cada tema contiene
            <b>logros esperados</b> con sus <b>indicadores</b>, mas datos opcionales
            (estrategias, evaluacion, secuencias, planificaciones personales, bibliografia especifica).
        </p>

        @forelse ($unidades as $idxU => $unidad)
            @if ($idxU > 0)
                <div class="page-break"></div>
            @endif
            <div class="unidad-block">
                <h3>
                    Unidad {{ $unidad['numero'] ?? ($idxU + 1) }} - {{ $unidad['titulo'] ?? 'Sin titulo' }}
                    @if (!empty($unidad['tipo']))
                        <span class="tag tag-tipo">{{ $unidad['tipo'] }}</span>
                    @endif
                </h3>

                <table class="kv-table">
                    <tr><td class="k">Numero</td><td>{{ $unidad['numero'] ?? 'N/D' }}</td></tr>
                    @if (!empty($unidad['elemento_competencia']))
                    <tr>
                        <td class="k">Elemento de competencia</td>
                        <td>{!! $renderValue($unidad['elemento_competencia']) !!}</td>
                    </tr>
                    @endif
                    @if (!empty($unidad['objetivo']))
                    <tr>
                        <td class="k">Objetivo</td>
                        <td>{!! $renderValue($unidad['objetivo']) !!}</td>
                    </tr>
                    @endif
                    @if (!empty($unidad['contenido_minimo']))
                    <tr>
                        <td class="k">Contenido minimo</td>
                        <td>{!! $renderValue($unidad['contenido_minimo']) !!}</td>
                    </tr>
                    @endif
                </table>

                <h4 style="margin-top:14px; color:#0e7490;">
                    Temas de esta unidad
                    <span style="color:#6b7280; font-weight:normal; font-size:9pt;">
                        ({{ count($unidad['temas'] ?? []) }} tema(s))
                    </span>
                </h4>

                @forelse (($unidad['temas'] ?? []) as $idxT => $tema)
                    <div class="tema-block">
                        <h4>
                            <span class="badge-tema">
                                U{{ $unidad['numero'] ?? '' }}.T{{ $tema['orden'] ?? ($idxT + 1) }}
                            </span>
                            {{ $tema['titulo'] ?? 'Sin titulo' }}
                            @if (!empty($tema['tipo']))
                                <span class="tag tag-bloque">{{ $tema['tipo'] }}</span>
                            @endif
                        </h4>

                        <table class="kv-table">
                            <tr><td class="k">Orden</td><td>{{ $tema['orden'] ?? 'N/D' }}</td></tr>
                            <tr>
                                <td class="k">Horas (T / P)</td>
                                <td>{{ $tema['horas_teoricas'] ?? 0 }}h teoricas | {{ $tema['horas_practicas'] ?? 0 }}h practicas</td>
                            </tr>
                        </table>

                        @if (!empty($tema['resultado_aprendizaje']))
                            <h5>Resultado de aprendizaje</h5>
                            {!! $renderValue($tema['resultado_aprendizaje']) !!}
                        @endif

                        <table class="grid-3" style="margin: 8px 0;">
                            <tr>
                                <td class="col col-first">
                                    <div class="col-label">Contenido conceptual</div>
                                    {!! $renderValue($tema['contenido_conceptual'] ?? null) !!}
                                </td>
                                <td class="col">
                                    <div class="col-label">Contenido procedimental</div>
                                    {!! $renderValue($tema['contenido_procedimental'] ?? null) !!}
                                </td>
                                <td class="col col-last">
                                    <div class="col-label">Contenido actitudinal</div>
                                    {!! $renderValue($tema['contenido_actitudinal'] ?? null) !!}
                                </td>
                            </tr>
                        </table>

                        @if (!empty($tema['contenido_items']))
                            <h5>Items de contenido</h5>
                            {!! $renderValue($tema['contenido_items']) !!}
                        @endif

                        @if (!empty($tema['estrategias_metodologicas']))
                            <h5>Estrategias metodologicas</h5>
                            {!! $renderValue($tema['estrategias_metodologicas']) !!}
                        @endif

                        @if (!empty($tema['estrategias_aprendizaje']))
                            <h5>Estrategias de aprendizaje</h5>
                            {!! $renderValue($tema['estrategias_aprendizaje']) !!}
                        @endif

                        @if (!empty($tema['estrategias_recursos']))
                            <h5>Recursos didacticos</h5>
                            {!! $renderValue($tema['estrategias_recursos']) !!}
                        @endif

                        @if (!empty($tema['evaluacion_formativa']))
                            <h5>Evaluacion formativa</h5>
                            {!! $renderValue($tema['evaluacion_formativa']) !!}
                        @endif

                        @if (!empty($tema['evaluacion_sumativa']))
                            <h5>Evaluacion sumativa</h5>
                            {!! $renderValue($tema['evaluacion_sumativa']) !!}
                        @endif

                        {{-- Logros esperados --}}
                        @if (!empty($tema['logros_esperados']))
                            <div class="sub-bloque">
                                <div class="titulo">
                                    Logros esperados del tema
                                    ({{ count($tema['logros_esperados']) }})
                                </div>
                                @foreach ($tema['logros_esperados'] as $idxL => $logro)
                                    <div class="logro-block">
                                        <h5>
                                            Logro {{ $idxL + 1 }}
                                            @if (!empty($logro['tipo_logro']))
                                                <span class="tag tag-tipo">{{ $logro['tipo_logro'] }}</span>
                                            @endif
                                            @if (!empty($logro['periodo']))
                                                <span class="tag tag-periodo">{{ $logro['periodo'] }}</span>
                                            @endif
                                        </h5>
                                        <div style="margin: 2px 0 6px 0;">{!! $renderValue($logro['descripcion'] ?? null) !!}</div>
                                        @if (!empty($logro['indicadores']))
                                            <div style="font-size:8.5pt; color:#5b21b6; font-weight:bold; text-transform:uppercase; margin-top:4px;">
                                                Indicadores ({{ count($logro['indicadores'] ?? []) }})
                                            </div>
                                            <ul style="margin: 2px 0 0 0; padding-left: 20px;">
                                                @foreach ($logro['indicadores'] as $indicador)
                                                    <li class="indicador-item">{!! $renderValue($indicador['descripcion'] ?? null) !!}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Secuencias didacticas --}}
                        @php
                            $secParsed = $parseValue($tema['secuencias'] ?? null);
                        @endphp
                        @if ($secParsed['type'] === 'secuencias' || $secParsed['type'] === 'list' || ($secParsed['type'] === 'mixed'))
                            <div class="sub-bloque">
                                <div class="titulo">Secuencias didacticas</div>
                                {!! $renderValue($tema['secuencias'] ?? null) !!}
                            </div>
                        @endif

                        {{-- Planificaciones personales (por docente) --}}
                        @if (!empty($tema['planificaciones_personales']))
                            <div class="sub-bloque">
                                <div class="titulo">
                                    Planificaciones personales
                                    ({{ count($tema['planificaciones_personales']) }} docente(s))
                                </div>
                                @foreach ($tema['planificaciones_personales'] as $planif)
                                    <div style="margin: 6px 0 6px 8px; padding: 6px 10px; border-left: 3px solid #7c3aed; background: #faf5ff;">
                                        <div style="font-size:9.5pt; font-weight:bold; color:#5b21b6;">
                                            {{ $planif['docente_nombre'] ?? 'Docente' }}
                                            @if (!empty($planif['docente_ci']))
                                                <span style="color:#6b7280; font-weight:normal;">(CI: {{ $planif['docente_ci'] }})</span>
                                            @endif
                                        </div>
                                        @if (!empty($planif['secuencia_didactica']))
                                            <div style="margin-top: 3px;">
                                                <div style="font-size:8.5pt; font-weight:bold; color:#5b21b6; text-transform:uppercase;">Secuencia didactica:</div>
                                                {!! $renderValue($planif['secuencia_didactica']) !!}
                                            </div>
                                        @endif
                                        @if (!empty($planif['evaluacion_formativa']))
                                            <div style="margin-top: 3px;">
                                                <span style="font-size:8.5pt; font-weight:bold; color:#0e7490; text-transform:uppercase;">Eval. formativa:</span>
                                                {!! $renderValue($planif['evaluacion_formativa']) !!}
                                            </div>
                                        @endif
                                        @if (!empty($planif['evaluacion_sumativa']))
                                            <div style="margin-top: 3px;">
                                                <span style="font-size:8.5pt; font-weight:bold; color:#0e7490; text-transform:uppercase;">Eval. sumativa:</span>
                                                {!! $renderValue($planif['evaluacion_sumativa']) !!}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Bibliografia especifica del tema --}}
                        @if (!empty($tema['bibliografias']))
                            <div class="sub-bloque">
                                <div class="titulo">
                                    Bibliografia referenciada en este tema
                                    ({{ count($tema['bibliografias']) }})
                                </div>
                                <table class="info-table">
                                    <thead>
                                        <tr>
                                            <th>Titulo</th>
                                            <th>Autor</th>
                                            <th style="width:12%;">Tipo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($tema['bibliografias'] as $bib)
                                            <tr>
                                                <td>{!! $renderValue($bib['titulo'] ?? null) !!}</td>
                                                <td>{!! $renderValue($bib['autor'] ?? null) !!}</td>
                                                <td><span class="tag tag-bloque">{{ $bib['tipo'] ?? 'BASICA' }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="empty-value">Esta unidad no tiene temas registrados.</p>
                @endforelse
            </div>
        @empty
            <p class="empty-value">Esta asignatura no tiene unidades registradas en el origen.</p>
        @endforelse
    </div>

    {{-- =================== 3. BIBLIOGRAFIA GENERAL =================== --}}
    <div class="section page-break">
        <h2>3. Bibliografia general de la asignatura</h2>
        <p style="color:#6b7280; font-size:9.5pt; margin-top:-6px;">
            Listado maestro de fuentes bibliograficas asociadas a la asignatura completa
            (no son las referenciadas especificamente por un tema).
        </p>

        @if (empty($bibliografias))
            <p class="empty-value">Sin bibliografia general registrada.</p>
        @else
            <table class="info-table">
                <thead>
                    <tr>
                        <th style="width:38%;">Titulo</th>
                        <th style="width:22%;">Autor</th>
                        <th style="width:12%;">Tipo</th>
                        <th style="width:10%;">Anio</th>
                        <th>Editorial / Edicion</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bibliografias as $bib)
                        <tr>
                            <td>
                                {!! $renderValue($bib['titulo'] ?? null) !!}
                                @if (!empty($bib['isbn']))
                                    <div style="font-size:8pt; color:#6b7280;">ISBN: {{ $bib['isbn'] }}</div>
                                @endif
                            </td>
                            <td>{!! $renderValue($bib['autor'] ?? null) !!}</td>
                            <td><span class="tag tag-bloque">{{ $bib['tipo'] ?? 'BASICA' }}</span></td>
                            <td>{!! $renderValue($bib['anio'] ?? null) !!}</td>
                            <td>
                                {!! $renderValue($bib['editorial'] ?? null) !!}
                                @if (!empty($bib['edicion']))
                                    <div style="font-size:8pt; color:#6b7280;">Edicion: {{ $bib['edicion'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- =================== 4. DOCENTES =================== --}}
    <div class="section">
        <h2>4. Docentes asignados</h2>
        <p style="color:#6b7280; font-size:9.5pt; margin-top:-6px;">
            Docentes que actualmente estan asociados a la asignatura a traves de sus grupos.
        </p>

        @if (empty($docentes))
            <p class="empty-value">Sin docentes asignados en el origen.</p>
        @else
            <table class="info-table">
                <thead>
                    <tr>
                        <th>Nombre completo</th>
                        <th style="width:18%;">CI</th>
                        <th style="width:30%;">Email</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($docentes as $docente)
                        <tr>
                            <td><b>{{ $docente['nombre_completo'] ?? 'N/D' }}</b></td>
                            <td>{!! $renderValue($docente['ci'] ?? null) !!}</td>
                            <td>{!! $renderValue($docente['email'] ?? null) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- =================== 5. PROGRESO =================== --}}
    @if (!empty($progreso) && is_array($progreso))
        <div class="section">
            <h2>5. Progreso de documentacion</h2>
            <p style="color:#6b7280; font-size:9.5pt; margin-top:-6px;">
                Indicadores calculados por el origen sobre el nivel de completitud del programa.
            </p>
            <table class="kv-table">
                @foreach ($progreso as $key => $value)
                    <tr>
                        <td class="k">{{ ucwords(str_replace('_', ' ', (string) $key)) }}</td>
                        <td>{!! $renderValue($value) !!}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

</body>
</html>
