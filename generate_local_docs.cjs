const fs = require('fs');
const path = require('path');

// 1. Target Directories
const docsDir = path.join(__dirname, 'docs');
const outputDir = path.join(__dirname, 'docs_html');

// Create output dir if it doesn't exist
if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir, { recursive: true });
}

// 2. Navigation items
const navItems = [
  { file: 'README.html', label: '🚀 Portal Principal', source: 'README.md' },
  { file: '01_autenticacion_seguridad.html', label: '🔒 01. Autenticación y Seguridad', source: 'docs/01_autenticacion_seguridad.md' },
  { file: '02_estructura_academica.html', label: '🏫 02. Estructura Académica', source: 'docs/02_estructura_academica.md' },
  { file: '03_pac_y_bibliografia.html', label: '📚 03. PAC y Bibliografía', source: 'docs/03_pac_y_bibliografia.md' },
  { file: '04_materias_comunes.html', label: '🔗 04. Materias Comunes', source: 'docs/04_materias_comunes.md' },
  { file: '05_planificacion_semestral.html', label: '📅 05. Planificación Semestral', source: 'docs/05_planificacion_semestral.md' },
  { file: '06_control_clase_seguimiento.html', label: '📝 06. Control y Seguimiento', source: 'docs/06_control_clase_seguimiento.md' },
  { file: '07_banco_preguntas_evaluaciones.html', label: '🎯 07. Banco y Evaluaciones', source: 'docs/07_banco_preguntas_evaluaciones.md' },
  { file: 'diagrama_er_completo.html', label: '🗄️ Diagrama ER Completo', source: 'docs/diagrama_er_completo.md' },
  { file: 'endpoints.html', label: '🔌 Endpoints de la API', source: 'docs/endpoints.md' }
];

console.log('Iniciando compilación de documentación HTML local...');

// Let's generate each HTML file
navItems.forEach(item => {
  const sourcePath = item.source === 'README.md' 
    ? path.join(__dirname, 'README.md') 
    : path.join(__dirname, item.source);
    
  if (!fs.existsSync(sourcePath)) {
    console.warn(`[WARN] Archivo origen no encontrado: ${sourcePath}`);
    return;
  }
  
  const markdown = fs.readFileSync(sourcePath, 'utf8');
  
  // Build sidebar HTML
  const sidebarHtml = navItems.map(nav => {
    const isActive = nav.file === item.file;
    const activeClass = isActive 
      ? 'bg-indigo-950/70 text-indigo-300 border-l-4 border-indigo-500 pl-3 font-semibold shadow-inner' 
      : 'text-slate-400 hover:bg-slate-800/40 hover:text-slate-200 pl-4';
      
    return `<a href="${nav.file}" class="flex items-center py-2.5 px-3 rounded-md transition duration-200 text-sm ${activeClass}">
      ${nav.label}
    </a>`;
  }).join('\n');
  
  // HTML Template
  const htmlTemplate = `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SISA - ${item.label.substring(3)}</title>
  <!-- Tailwind CSS Play CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Marked.js for markdown rendering -->
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <!-- Mermaid.js for visual flows -->
  <script src="https://cdn.jsdelivr.net/npm/mermaid/dist/mermaid.min.js"></script>
  <!-- Google Fonts: Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background-color: #020617; }
    /* Custom markdown classes */
    .markdown-body h1 { font-size: 2rem; font-weight: 800; border-bottom: 1px solid #1e293b; padding-bottom: 0.5rem; margin-top: 2rem; margin-bottom: 1.25rem; color: #f8fafc; }
    .markdown-body h2 { font-size: 1.5rem; font-weight: 700; border-bottom: 1px solid #1e293b; padding-bottom: 0.35rem; margin-top: 1.75rem; margin-bottom: 1rem; color: #f1f5f9; }
    .markdown-body h3 { font-size: 1.25rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem; color: #e2e8f0; }
    .markdown-body h4 { font-size: 1.1rem; font-weight: 600; margin-top: 1.25rem; margin-bottom: 0.5rem; color: #cbd5e1; }
    .markdown-body p { margin-top: 0; margin-bottom: 1rem; line-height: 1.75; color: #cbd5e1; }
    .markdown-body ul, .markdown-body ol { margin-top: 0; margin-bottom: 1rem; padding-left: 1.75rem; }
    .markdown-body ul { list-style-type: disc; }
    .markdown-body ol { list-style-type: decimal; }
    .markdown-body li { margin-top: 0.25rem; margin-bottom: 0.25rem; line-height: 1.625; color: #cbd5e1; }
    .markdown-body table { width: 100%; border-collapse: collapse; margin-top: 1.25rem; margin-bottom: 1.25rem; display: block; overflow-x: auto; }
    .markdown-body th, .markdown-body td { border: 1px solid #334155; padding: 0.6rem 0.8rem; text-align: left; min-width: 120px; }
    .markdown-body th { background-color: #1e293b; font-weight: 600; color: #f8fafc; }
    .markdown-body td { color: #cbd5e1; }
    .markdown-body tr:nth-child(even) td { background-color: rgba(30, 41, 59, 0.25); }
    .markdown-body code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.9em; background-color: rgba(30, 41, 59, 0.6); padding: 0.2rem 0.4rem; border-radius: 0.25rem; color: #fb7185; }
    .markdown-body pre { background-color: #0f172a; border: 1px solid #1e293b; border-radius: 0.5rem; padding: 1rem; overflow-x: auto; margin-top: 1.25rem; margin-bottom: 1.25rem; }
    .markdown-body pre code { background-color: transparent; padding: 0; border-radius: 0; color: #e2e8f0; font-size: 0.95em; }
    .markdown-body hr { border: 0; border-top: 1px solid #1e293b; margin: 1.75rem 0; }
    .markdown-body a { color: #818cf8; text-decoration: none; font-weight: 500; transition: color 0.2s; }
    .markdown-body a:hover { color: #c084fc; text-decoration: underline; }
    
    /* Mermaid styling */
    .mermaid {
      background: #0f172a;
      border: 1px solid #1e293b;
      padding: 1.25rem;
      border-radius: 0.5rem;
      margin: 1.25rem 0;
      display: flex;
      justify-content: center;
      overflow-x: auto;
    }
  </style>
</head>
<body class="bg-slate-950 text-slate-100 flex min-h-screen">

  <!-- Mobile Toggle Button -->
  <button id="menu-toggle" class="lg:hidden fixed bottom-5 right-5 z-50 bg-indigo-600 text-white p-3.5 rounded-full shadow-lg hover:bg-indigo-500 focus:outline-none transition">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path id="menu-icon" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
  </button>

  <!-- Sidebar -->
  <aside id="sidebar" class="w-80 bg-slate-900 border-r border-slate-800 flex flex-col fixed inset-y-0 left-0 z-40 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out">
    <div class="p-6 border-b border-slate-800 bg-slate-950/80">
      <div class="flex items-center gap-2.5">
        <div class="w-8.5 h-8.5 rounded-lg bg-indigo-600 flex items-center justify-center font-bold text-white shadow-md shadow-indigo-500/20">S</div>
        <div>
          <h1 class="text-base font-bold text-slate-100 leading-tight">SISA 2.0 Docs</h1>
          <p class="text-[10px] text-slate-500">Documentación Técnica Local</p>
        </div>
      </div>
    </div>
    
    <!-- Sidebar Navigation -->
    <nav class="flex-1 overflow-y-auto p-4 space-y-1">
      ${sidebarHtml}
    </nav>
    
    <div class="p-4 border-t border-slate-800 text-center text-xs text-slate-500 bg-slate-950/40">
      Desarrollado para UNITEPC
    </div>
  </aside>

  <!-- Main Content Area -->
  <main class="flex-1 lg:pl-80 min-h-screen flex flex-col bg-slate-950">
    <div class="flex-1 max-w-4xl w-full mx-auto px-6 py-12 lg:px-12">
      <article id="content" class="markdown-body">
        <!-- Rendered markdown goes here -->
      </article>
    </div>
  </main>

  <!-- Raw Markdown Content Injected Safely -->
  <script type="text/markdown" id="markdown-content">${markdown.replace(/<\/script>/g, '<\\/script>')}</script>

  <script>
    // 1. Mobile Menu Toggle Logic
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    let isSidebarOpen = false;
    
    menuToggle.addEventListener('click', () => {
      isSidebarOpen = !isSidebarOpen;
      if (isSidebarOpen) {
        sidebar.classList.remove('-translate-x-full');
        document.getElementById('menu-icon').setAttribute('d', 'M6 18L18 6M6 6l12 12');
      } else {
        sidebar.classList.add('-translate-x-full');
        document.getElementById('menu-icon').setAttribute('d', 'M4 6h16M4 12h16M4 18h16');
      }
    });

    // 2. Parse Markdown
    const rawMarkdown = document.getElementById('markdown-content').textContent;
    let parsedHtml = marked.parse(rawMarkdown);
    
    // Rewrite Markdown links (.md -> .html)
    // Links in docs folder are like 'docs/01_...' or '../docs/01_...' or '01_...'
    parsedHtml = parsedHtml.replace(/href="([^"]+)\\.md(#.*)?"/g, (match, path, hash) => {
      const basename = path.split('/').pop();
      const newPath = basename + '.html' + (hash || '');
      return \`href="\${newPath}"\`;
    });
    
    // Also rewrite README.md links to README.html
    parsedHtml = parsedHtml.replace(/href="README\\.md"/g, 'href="README.html"');
    
    const contentEl = document.getElementById('content');
    contentEl.innerHTML = parsedHtml;

    // 3. Convert language-mermaid blocks to raw divs for Mermaid.js
    document.querySelectorAll('pre code.language-mermaid').forEach((block) => {
      const pre = block.parentElement;
      const mermaidCode = block.textContent.trim();
      const div = document.createElement('div');
      div.className = 'mermaid';
      div.textContent = mermaidCode;
      pre.replaceWith(div);
    });

    // 4. Style Alert Callouts (GitHub style blockquotes)
    document.querySelectorAll('blockquote').forEach((bq) => {
      let html = bq.innerHTML;
      const alerts = {
        'NOTE': {
          title: 'Nota',
          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
          bg: 'bg-blue-950/40 border-blue-500 text-blue-200',
          titleColor: 'text-blue-400'
        },
        'TIP': {
          title: 'Consejo',
          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
          bg: 'bg-emerald-950/40 border-emerald-500 text-emerald-200',
          titleColor: 'text-emerald-400'
        },
        'IMPORTANT': {
          title: 'Importante',
          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
          bg: 'bg-violet-950/40 border-violet-500 text-violet-200',
          titleColor: 'text-violet-400'
        },
        'WARNING': {
          title: 'Advertencia',
          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
          bg: 'bg-amber-950/40 border-amber-500 text-amber-200',
          titleColor: 'text-amber-400'
        },
        'CAUTION': {
          title: 'Precaución',
          icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
          bg: 'bg-rose-950/40 border-rose-500 text-rose-200',
          titleColor: 'text-rose-400'
        }
      };
      
      for (const [key, val] of Object.entries(alerts)) {
        const pattern = new RegExp(\`\\\\[!\\\${key}\\\\]\`, 'g');
        if (pattern.test(html)) {
          let cleaned = html.replace(pattern, '').replace(/^\\s*<br\\s*\\/?>\\s*/i, '');
          const container = document.createElement('div');
          container.className = \`my-6 p-4 rounded-r-lg border-l-4 \${val.bg}\`;
          container.innerHTML = \`
            <div class="flex items-center gap-2 font-bold mb-2 \${val.titleColor}">
              \${val.icon}
              <span>\${val.title}</span>
            </div>
            <div class="alert-content text-slate-300 font-normal">\${cleaned}</div>
          \`;
          bq.replaceWith(container);
          break;
        }
      }
    });

    // 5. Initialize Mermaid
    mermaid.initialize({
      startOnLoad: true,
      theme: 'dark',
      securityLevel: 'loose',
      themeVariables: {
        background: '#0f172a',
        primaryColor: '#6366f1',
        primaryTextColor: '#f8fafc',
        lineColor: '#334155'
      }
    });
  </script>
</body>
</html>`;

  const outputPath = path.join(outputDir, item.file);
  fs.writeFileSync(outputPath, htmlTemplate, 'utf8');
  console.log(`Compilado con éxito: ${item.file}`);
});

console.log('Compilación completada exitosamente.');
