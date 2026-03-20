<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema en Mantenimiento | UNITEPC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            overflow: hidden;
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
            max-width: 580px;
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
            margin-bottom: 1.5rem;
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

        /* Footer text */
        .footer-text {
            font-size: 0.8rem;
            color: #475569;
            margin-top: 1.5rem;
        }

        .footer-text a {
            color: #60a5fa;
            text-decoration: none;
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

            <p class="footer-text">
                Si necesitas asistencia urgente, contacta al equipo de soporte.<br>
                <strong>UNITEPC</strong> — Plataforma Académica
            </p>
        </div>
    </div>
</body>
</html>
