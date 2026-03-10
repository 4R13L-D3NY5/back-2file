const fs = require('fs');

const data = JSON.parse(fs.readFileSync('asignatura_dump.json', 'utf8'));

function calcularProgresoTema(tema) {
  if (!tema) return 0;

  const getDeep = (obj, path) => {
    return path.split('.').reduce((acc, part) => acc && acc[part], obj);
  };
  const getAny = (obj, paths) => {
    for (const path of paths) {
      const val = getDeep(obj, path);
      if (val !== undefined && val !== null) {
        return val;
      }
    }
    return undefined;
  };

  let pResultados = 0;
  let totalCamposRes = 3;
  let camposLlenosRes = 0;
  if (tema.resultado_aprendizaje?.trim()) camposLlenosRes++;

  const logros = tema.logros_esperados || tema.logros || [];
  if (logros.length > 0 && logros.some(l => l.descripcion?.trim())) camposLlenosRes++;
  const tieneIndicador = logros.some(l => l.indicadores?.some(i => i.descripcion?.trim()));
  if (tieneIndicador) camposLlenosRes++;

  if (logros.length > 1) {
    logros.slice(1).forEach(logro => {
      totalCamposRes++;
      if (logro.descripcion?.trim()) camposLlenosRes++;
    });
  }
  logros.forEach(logro => {
    const indicadores = logro.indicadores || [];
    if (indicadores.length > 1) {
      indicadores.slice(1).forEach(ind => {
        totalCamposRes++;
        if (ind.descripcion?.trim()) camposLlenosRes++;
      });
    }
  });
  pResultados = Math.round((camposLlenosRes / totalCamposRes) * 100);

  let pContenidos = 0;
  const conceptual = getAny(tema, ['contenidos.conceptual', 'contenido_conceptual']) || [];
  const procedimental = getAny(tema, ['contenidos.procedimental', 'contenido_procedimental']) || [];
  const actitudinal = getAny(tema, ['contenidos.actitudinal', 'contenido_actitudinal']) || [];

  let totalCamposCont = 3;
  let camposLlenosCont = 0;
  if (conceptual.length > 0 && conceptual.some(item => item?.trim())) camposLlenosCont++;
  if (conceptual.length > 1) conceptual.slice(1).forEach(item => { totalCamposCont++; if (item?.trim()) camposLlenosCont++; });
  if (procedimental.length > 0 && procedimental.some(item => item?.trim())) camposLlenosCont++;
  if (procedimental.length > 1) procedimental.slice(1).forEach(item => { totalCamposCont++; if (item?.trim()) camposLlenosCont++; });
  if (actitudinal.length > 0 && actitudinal.some(item => item?.trim())) camposLlenosCont++;
  if (actitudinal.length > 1) actitudinal.slice(1).forEach(item => { totalCamposCont++; if (item?.trim()) camposLlenosCont++; });
  pContenidos = Math.round((camposLlenosCont / totalCamposCont) * 100);

  let pEstrategias = 0;
  const metodologicas = getAny(tema, ['planificacionPersonal.estrategias_metodologicas', 'planificacion_personal.estrategias_metodologicas', 'estrategias.metodologicas', 'estrategias_metodologicas']) || '';
  const aprendizaje = getAny(tema, ['planificacionPersonal.estrategias_aprendizaje', 'planificacion_personal.estrategias_aprendizaje', 'estrategias.aprendizaje', 'estrategias_aprendizaje']) || '';
  const recursosEst = getAny(tema, ['planificacionPersonal.estrategias_recursos', 'planificacion_personal.estrategias_recursos', 'estrategias.recursos', 'estrategias_recursos']) || [];

  let totalCamposEst = 3;
  let camposLlenosEst = 0;
  if (metodologicas?.trim()) camposLlenosEst++;
  if (aprendizaje?.trim()) camposLlenosEst++;
  if (recursosEst.length > 0 && recursosEst.some(r => r?.trim())) camposLlenosEst++;
  if (recursosEst.length > 1) recursosEst.slice(1).forEach(rec => { totalCamposEst++; if (rec?.trim()) camposLlenosEst++; });
  pEstrategias = Math.round((camposLlenosEst / totalCamposEst) * 100);

  let pEvaluacion = 0;
  const fActividades = getAny(tema, ['planificacionPersonal.evaluacion_formativa.actividades', 'planificacion_personal.evaluacion_formativa.actividades', 'evaluacion.formativa.actividades', 'evaluacion_formativa.actividades']) || [];
  const fInstrumentos = getAny(tema, ['planificacionPersonal.evaluacion_formativa.instrumentos', 'planificacion_personal.evaluacion_formativa.instrumentos', 'evaluacion.formativa.instrumentos', 'evaluacion_formativa.instrumentos']) || [];
  const fEvidencias = getAny(tema, ['planificacionPersonal.evaluacion_formativa.evidencias', 'planificacion_personal.evaluacion_formativa.evidencias', 'evaluacion.formativa.evidencias', 'evaluacion_formativa.evidencias']) || [];
  const sActividades = getAny(tema, ['planificacionPersonal.evaluacion_sumativa.actividades', 'planificacion_personal.evaluacion_sumativa.actividades', 'evaluacion.sumativa.actividades', 'evaluacion_sumativa.actividades']) || [];
  const sInstrumentos = getAny(tema, ['planificacionPersonal.evaluacion_sumativa.instrumentos', 'planificacion_personal.evaluacion_sumativa.instrumentos', 'evaluacion.sumativa.instrumentos', 'evaluacion_sumativa.instrumentos']) || [];
  const sEvidencias = getAny(tema, ['planificacionPersonal.evaluacion_sumativa.evidencias', 'planificacion_personal.evaluacion_sumativa.evidencias', 'evaluacion.sumativa.evidencias', 'evaluacion_sumativa.evidencias']) || [];

  let totalCamposEval = 6;
  let camposLlenosEval = 0;
  if (fActividades.length > 0) camposLlenosEval++;
  if (fInstrumentos.length > 0) camposLlenosEval++;
  if (fEvidencias.length > 0) camposLlenosEval++;
  if (sActividades.length > 0) camposLlenosEval++;
  if (sInstrumentos.length > 0) camposLlenosEval++;
  if (sEvidencias.length > 0) camposLlenosEval++;
  pEvaluacion = Math.round((camposLlenosEval / totalCamposEval) * 100);

  let pSecuencia = 0;
  const secuencia = getAny(tema, ['planificacionPersonal.secuencia_didactica', 'planificacion_personal.secuencia_didactica', 'secuencia_didactica']) || [];
  let totalCamposSec = 3;
  let camposLlenosSec = 0;
  secuencia.forEach(momento => { if (momento.actividad?.trim()) camposLlenosSec++; });
  if (secuencia.length > 3) totalCamposSec = secuencia.length;
  pSecuencia = Math.min(100, Math.round((camposLlenosSec / totalCamposSec) * 100));

  console.log(`Tema ${tema.id}: Res=${pResultados} Cont=${pContenidos} Est=${pEstrategias} Eval=${pEvaluacion} Sec=${pSecuencia} -> Avg=${Math.round((pResultados + pContenidos + pEstrategias + pEvaluacion + pSecuencia) / 5)}`);

  return Math.round((pResultados + pContenidos + pEstrategias + pEvaluacion + pSecuencia) / 5);
}

function calcularProgresoUnidad(unidad) {
  if (!unidad.temas?.length) return 0;
  const total = unidad.temas.reduce((sum, t) => sum + calcularProgresoTema(t), 0);
  return Math.round(total / unidad.temas.length);
}

const unidades = data.unidades?.filter(u => u.temas?.length) ?? [];
if (!unidades.length) {
    console.log("Plan Clase: 0%");
    process.exit(0);
}
const suma = unidades.reduce((acc, u) => acc + calcularProgresoUnidad(u), 0);
const planClasePctLocal = Math.round(suma / unidades.length);

console.log(`Plan Clase Total: ${planClasePctLocal}%`);
