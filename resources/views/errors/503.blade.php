<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema en Mantenimiento | UNITEPC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
            color: #e2e8f0;
            overflow-x: hidden;
            position: relative;
        }

        /* Animated background particles */
        .bg-particles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }
        .bg-particles::before,
        .bg-particles::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.08;
            animation: float 8s ease-in-out infinite;
        }
        .bg-particles::before {
            width: 400px; height: 400px;
            background: radial-gradient(circle, #3b82f6, transparent);
            top: -100px; left: -100px;
        }
        .bg-particles::after {
            width: 500px; height: 500px;
            background: radial-gradient(circle, #6366f1, transparent);
            bottom: -150px; right: -100px;
            animation-delay: -4s;
            animation-direction: reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(40px, -40px) scale(1.1); }
        }

        .container {
            position: relative;
            z-index: 1;
            text-align: center;
            padding: 2rem;
            max-width: 620px;
            width: 100%;
        }

        /* Gear Icon Animation */
        .icon-wrapper {
            margin-bottom: 2rem;
            position: relative;
            display: inline-block;
        }

        .gear-icon {
            width: 80px; height: 80px;
            animation: spin 6s linear infinite;
        }

        .gear-icon path { fill: #60a5fa; }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Glassmorphism card */
        .card {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 3rem 2.5rem;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.75rem;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 1rem;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 2rem;
            font-weight: 300;
        }

        /* Info cards */
        .info-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1rem 1.5rem;
            flex: 1;
            min-width: 150px;
        }

        .info-item .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 0.3rem;
        }

        .info-item .value {
            font-size: 1rem;
            font-weight: 600;
            color: #60a5fa;
        }

        /* Progress bar */
        .progress-container {
            margin-bottom: 2rem;
        }

        .progress-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.5rem;
        }

        .progress-bar {
            height: 4px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 99px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            width: 30%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #3b82f6);
            background-size: 200% 100%;
            border-radius: 99px;
            animation: shimmer 2s ease-in-out infinite;
        }

        @keyframes shimmer {
            0% { background-position: 200% 0; width: 20%; }
            50% { width: 60%; }
            100% { background-position: -200% 0; width: 20%; }
        }

        /* Divider */
        .divider {
            border: none;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            margin: 1.5rem 0;
        }

        /* Download section */
        .download-section {
            text-align: left;
            animation: fadeInUp 1s ease-out 0.2s both;
        }

        .download-section h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .download-instruction {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: #c7d2fe;
            line-height: 1.6;
        }

        .download-instruction strong {
            color: #a5b4fc;
        }

        .download-instruction ol {
            margin: 0.5rem 0 0 1.2rem;
            padding: 0;
        }

        .download-instruction li {
            margin-bottom: 0.3rem;
        }

        .download-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: linear-gradient(135deg, #4527a0, #7b1fa2);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            width: 100%;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(69, 39, 160, 0.4);
        }

        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(69, 39, 160, 0.5);
        }

        .download-btn:active {
            transform: translateY(0);
        }

        .download-btn svg {
            width: 22px;
            height: 22px;
            fill: currentColor;
        }

        .download-btn.loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Footer text */
        .footer-text {
            font-size: 0.8rem;
            color: #475569;
            margin-top: 1.5rem;
        }

        /* Pulse dot */
        .status-dot {
            display: inline-block;
            width: 8px; height: 8px;
            background: #f59e0b;
            border-radius: 50%;
            margin-right: 6px;
            animation: pulse 2s ease-in-out infinite;
            vertical-align: middle;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            50% { opacity: 0.7; box-shadow: 0 0 0 8px rgba(245, 158, 11, 0); }
        }

        @media (max-width: 480px) {
            .card { padding: 2rem 1.5rem; border-radius: 18px; }
            h1 { font-size: 1.4rem; }
            .info-row { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="bg-particles"></div>

    <div class="container">
        <div class="icon-wrapper">
            <svg class="gear-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1112 8.4a3.6 3.6 0 010 7.2z"/>
            </svg>
        </div>

        <div class="card">
            <h1>🔧 Sistema en Mantenimiento</h1>
            <p class="subtitle">
                Estamos realizando mejoras importantes para brindarte una mejor experiencia.
                El servicio se restablecerá en breve.
            </p>

            <div class="info-row">
                <div class="info-item">
                    <div class="label">Motivo</div>
                    <div class="value">Actualización del Sistema</div>
                </div>
                <div class="info-item">
                    <div class="label">Tiempo estimado</div>
                    <div class="value">~1 Hora</div>
                </div>
            </div>

            <div class="progress-container">
                <div class="progress-label">
                    <span class="status-dot"></span> Trabajo en progreso
                </div>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
            </div>

            <hr class="divider">

            <!-- Download Section -->
            <div class="download-section">
                <h2>
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="#a5b4fc"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM6 20V4h5v7h7v9H6z"/></svg>
                    Mientras tanto, sigue trabajando
                </h2>

                <div class="download-instruction">
                    <strong>📋 Banco de Preguntas:</strong>
                    <ol>
                        <li><strong>Descarga el formato Excel</strong> y procede a completarlo con las preguntas adecuadas según tu criterio (15 de dificultad fácil, 30 de dificultad media y 15 difíciles).</li>
                        <li>Una vez que el archivo esté completo, utiliza el botón <strong>"Subir Banco"</strong> para cargarlo al sistema cuando volvamos a estar en línea.</li>
                    </ol>
                </div>

                <button class="download-btn" id="downloadExcelBtn" onclick="descargarFormatoBanco()">
                    <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    Descargar Excel Base — Banco de Preguntas
                </button>
            </div>

            <p class="footer-text">
                Si necesitas asistencia urgente, contacta al equipo de soporte.<br>
                <strong>UNITEPC</strong> — Plataforma Académica
            </p>
        </div>
    </div>

    <script>
    async function descargarFormatoBanco() {
        const btn = document.getElementById('downloadExcelBtn');
        btn.classList.add('loading');
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" class="spin-icon"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1112 8.4a3.6 3.6 0 010 7.2z"/></svg> Generando...';

        try {
            const workbook = new ExcelJS.Workbook();

            // ── HOJA 1: INSTRUCCIONES ──
            const wsInst = workbook.addWorksheet('Instrucciones');
            wsInst.getColumn(1).width = 40;
            wsInst.getColumn(2).width = 80;

            const titleRow = wsInst.addRow(['BANCO DE PREGUNTAS — GUÍA OFICIAL']);
            titleRow.font = { bold: true, size: 16, color: { argb: 'FFFFFFFF' } };
            titleRow.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF4527A0' } };
            wsInst.addRow([]);

            function addGuideHeader(text) {
                const r = wsInst.addRow([text]);
                r.font = { bold: true, size: 12 };
                r.getCell(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE8EAF6' } };
            }

            const redFont = { color: { argb: 'FFFF0000' }, bold: true };

            addGuideHeader('1. CÓDIGOS DE PREGUNTA (Columna TIPO)');
            wsInst.addRow(['FV', 'Falso y Verdadero']);
            wsInst.addRow(['SS', 'Selección Simple - Solo 1 respuesta correcta']);
            const rowSM1 = wsInst.addRow(['SM', 'Selección Múltiple - SOLO 2 OPCIONES (Ambas deben ser correctas)']);
            rowSM1.getCell(2).font = redFont;
            const rowPR1 = wsInst.addRow(['PR', 'Problema o Caso Clínico (Solo llenar el Enunciado. NO LLEVA DIFICULTAD)']);
            rowPR1.getCell(2).font = redFont;
            wsInst.addRow(['SP', 'Subpregunta de un PR o EM (Llenar igual que Selección Simple)']);
            const rowEM1 = wsInst.addRow(['EM', 'Emparejamiento (Solo llenar el Enunciado. NO LLEVA DIFICULTAD)']);
            rowEM1.getCell(2).font = redFont;
            wsInst.addRow([]);

            addGuideHeader('2. RESPUESTAS VÁLIDAS');
            wsInst.addRow(['Opciones (SS)', 'Pon una sola letra: A, B, C, D o E.']);
            wsInst.addRow(['Emparejamiento (EM)', 'Déjalo completamente en blanco.']);
            const rowSM2 = wsInst.addRow(['Opciones (SM)', 'Debes poner exactamente las 2 letras correspondientes, por ejemplo: A,B.']);
            rowSM2.getCell(2).font = redFont;
            wsInst.addRow(['Falso/Verdadero (FV)', 'A para Verdadero, B para Falso.']);
            const rowPR2 = wsInst.addRow(['Problemas (PR)', 'Déjalo completamente en blanco.']);
            rowPR2.getCell(2).font = redFont;
            wsInst.addRow(['Opciones (SP)', 'Pon una sola letra: A, B, C, D o E.']);
            wsInst.addRow([]);

            addGuideHeader('3. CONFIGURACIÓN DEL EXAMEN');
            const rowDif = wsInst.addRow(['Dificultad (Nivel)', '1 (Fácil), 2 (Medio), 3 (Difícil) — PR y EM NO LLEVAN (DEJAR VACÍO)']);
            rowDif.getCell(1).font = redFont;
            rowDif.getCell(2).font = redFont;
            wsInst.addRow(['Parciales', '1P (1° Parcial), 2P (2° Parcial), EF (Final), 2I (2da Instancia)']);
            wsInst.addRow([]);

            addGuideHeader('⚠️ NOTAS IMPORTANTES');
            const note = wsInst.addRow(['- No elimine columnas ni cambie el nombre de la hoja "Banco".']);
            note.font = { italic: true, color: { argb: 'FFC62828' } };
            const noteSM = wsInst.addRow(['- IMPORTANTE: Las preguntas SM (Selección Múltiple) deben tener SOLAMENTE 2 OPCIONES y ambas deben ser marcadas como correctas (ej: A,B).']);
            noteSM.font = redFont;
            const notePREM = wsInst.addRow(['- IMPORTANTE: Los tipos PR y EM son encabezados y NO DEBEN TENER DIFICULTAD (dejar la celda de dificultad vacía).']);
            notePREM.font = redFont;

            // ── HOJA 2: BANCO ──
            const wsBanco = workbook.addWorksheet('Banco');
            const headers = ['tipo', 'grupo', 'enunciado', 'opcion_a', 'opcion_b', 'opcion_c', 'opcion_d', 'opcion_e', 'respuesta_correcta', 'dificultad', 'parcial'];
            const headerRow = wsBanco.addRow(headers);

            headerRow.eachCell(function(cell) {
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF4527A0' } };
                cell.font = { color: { argb: 'FFFFFFFF' }, bold: true };
                cell.alignment = { vertical: 'middle', horizontal: 'center' };
                cell.border = { top: { style: 'thin' }, left: { style: 'thin' }, bottom: { style: 'thin' }, right: { style: 'thin' } };
            });

            function addPreguntaRow(dif, parcial, color, grupo) {
                grupo = grupo || '';
                var row = wsBanco.addRow(['', grupo, '', '', '', '', '', '', '', dif, parcial]);
                row.eachCell(function(cell) {
                    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: color } };
                    cell.border = { top: { style: 'thin' }, left: { style: 'thin' }, bottom: { style: 'thin' }, right: { style: 'thin' } };
                });
            }

            // 15 Fáciles (Verde)
            for (var i = 0; i < 15; i++) addPreguntaRow('1', '1P', 'FFC6EFCE');
            // 30 Medias (Amarillo)
            for (var i = 0; i < 30; i++) addPreguntaRow('2', '1P', 'FFFFEB9C');
            // 15 Difíciles (Rojo)
            for (var i = 0; i < 15; i++) addPreguntaRow('3', '1P', 'FFFFC7CE');

            wsBanco.columns = [
                { width: 10 }, { width: 15 },
                { width: 60, style: { alignment: { wrapText: true, vertical: 'top' } } },
                { width: 25, style: { alignment: { wrapText: true, vertical: 'top' } } },
                { width: 25, style: { alignment: { wrapText: true, vertical: 'top' } } },
                { width: 25, style: { alignment: { wrapText: true, vertical: 'top' } } },
                { width: 25, style: { alignment: { wrapText: true, vertical: 'top' } } },
                { width: 25, style: { alignment: { wrapText: true, vertical: 'top' } } },
                { width: 20 }, { width: 12 }, { width: 12 }
            ];

            // Data Validation
            for (var i = 2; i <= 61; i++) {
                wsBanco.getCell('A' + i).dataValidation = { type: 'list', allowBlank: true, formulae: ['"FV,SS,SM,PR,SP,EM"'] };
                wsBanco.getCell('J' + i).dataValidation = { type: 'list', allowBlank: true, formulae: ['"1,2,3"'] };
                wsBanco.getCell('K' + i).dataValidation = { type: 'list', allowBlank: true, formulae: ['"1P,2P,EF,2I"'] };
            }

            // Totals
            var rowF = wsBanco.getRow(63);
            rowF.getCell(9).value = 'Total Fáciles:';
            rowF.getCell(9).font = { bold: true };
            rowF.getCell(10).value = { formula: 'COUNTIF(J2:J61, 1)' };

            var rowM = wsBanco.getRow(64);
            rowM.getCell(9).value = 'Total Medias:';
            rowM.getCell(9).font = { bold: true };
            rowM.getCell(10).value = { formula: 'COUNTIF(J2:J61, 2)' };

            var rowD = wsBanco.getRow(65);
            rowD.getCell(9).value = 'Total Difíciles:';
            rowD.getCell(9).font = { bold: true };
            rowD.getCell(10).value = { formula: 'COUNTIF(J2:J61, 3)' };

            // ── HOJA 3: EJEMPLO ──
            const wsEj = workbook.addWorksheet('Ejemplo');
            const ejHeaderRow = wsEj.addRow(headers);
            ejHeaderRow.eachCell(function(cell) {
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF006064' } };
                cell.font = { color: { argb: 'FFFFFFFF' }, bold: true };
                cell.alignment = { vertical: 'middle', horizontal: 'center' };
            });

            function addEjRow(tipo, dif, parc, enun, r, ops, grupo) {
                ops = ops || [];
                grupo = grupo || '';
                var rowData = [tipo, grupo, enun, '', '', '', '', '', r, dif, parc];
                for (var i = 0; i < Math.min(ops.length, 5); i++) {
                    rowData[3 + i] = ops[i];
                }
                var row = wsEj.addRow(rowData);
                row.getCell(1).font = { bold: true };
            }

            // Ejemplos FV
            addEjRow('FV', '1', '1P', 'Bolivia cuenta con una salida soberana al Océano Pacífico.', 'B', ['Verdadero', 'Falso']);
            addEjRow('FV', '1', '1P', 'La capital constitucional de Bolivia es Sucre.', 'A', ['Verdadero', 'Falso']);

            // Ejemplos SS
            addEjRow('SS', '2', '1P', '¿Cuál es el símbolo químico del Oro en la tabla periódica?', 'B', ['Ag', 'Au', 'Fe', 'Cu', 'Pb']);
            addEjRow('SS', '2', '1P', '¿Cuál es el planeta más grande de nuestro sistema solar?', 'C', ['Marte', 'Tierra', 'Júpiter', 'Saturno', 'Venus']);

            // Ejemplos SM
            addEjRow('SM', '3', '1P', '¿Cuáles de los siguientes son elementos que componen el núcleo atómico?', 'A,B', ['Protones', 'Neutrones']);
            addEjRow('SM', '3', '1P', '¿Cuáles son los principales componentes del agua pura?', 'A,B', ['Hidrógeno', 'Oxígeno']);

            // Ejemplos PR y SP
            addEjRow('PR', '', '1P', 'CASO CLINICO O PROBLEMA\nUna mujer de 58 años, inconsciente, es llevada al Servicio de Urgencia después de sufrir un colapso. Tiene antecedentes de hipertensión arterial y fibrilación auricular. Al examen físico: presión arterial 220/130 mmHg.', '', [], 'Caso 1');
            addEjRow('SP', '3', '1P', '¿Con cuál de las siguientes estructuras del lado izquierdo es más consistente la pupila izquierda no reactiva y dilatada?', 'D', ['Nervio óptico', 'Tracto óptico', 'Protuberancia', 'Nervio oculomotor', 'Colículo superior'], 'Caso 1');
            addEjRow('SP', '3', '1P', '¿Con una lesión en cuál de las siguientes áreas del cerebro izquierdo es más consistente la postura en extensión del brazo derecho?', 'E', ['Telencéfalo', 'Diencéfalo', 'Protuberancia', 'Bulbo raquídeo', 'Cerebro medio'], 'Caso 1');

            // Ejemplos EM y SP
            addEjRow('EM', '', '1P', 'EMPAREJAMIENTO: Relacione el concepto con su definición correcta:\nA. Metodología\nB. Método\nC. Técnica\nD. Ciencia\nE. Conocimiento Empírico', '', [], 'Emp 1');
            addEjRow('SP', '2', '1P', 'Herramientas y procedimientos prácticos para recolectar datos.', 'A', [], 'Emp 1');
            addEjRow('SP', '2', '1P', 'Conocimiento que se obtiene mediante la práctica diaria y la percepción personal.', 'B', [], 'Emp 1');
            addEjRow('SP', '2', '1P', 'El estudio de los pasos y estrategias que se siguen en una investigación.', 'C', [], 'Emp 1');
            addEjRow('SP', '2', '1P', 'El camino lógico o plan estructurado para alcanzar un objetivo de conocimiento.', 'D', [], 'Emp 1');
            addEjRow('SP', '2', '1P', 'Sistema de saberes organizados, objetivos y verificables sobre la realidad.', 'E', [], 'Emp 1');

            wsEj.columns = wsBanco.columns;

            // Download
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'formato_banco_preguntas_UNITEPC.xlsx';
            link.click();

            btn.classList.remove('loading');
            btn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg> ¡Descargado con éxito!';
            setTimeout(function() {
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg> Descargar Excel Base — Banco de Preguntas';
            }, 3000);
        } catch (e) {
            console.error(e);
            btn.classList.remove('loading');
            btn.innerHTML = '❌ Error al generar. Intenta de nuevo.';
            setTimeout(function() {
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg> Descargar Excel Base — Banco de Preguntas';
            }, 3000);
        }
    }
    </script>
</body>
</html>
