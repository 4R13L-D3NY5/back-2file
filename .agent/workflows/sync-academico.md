---
description: Sync de datos academicos y manejo de carreras faltantes
---

# Workflow: Sincronización Académica

## Sincronización Automática

El sync se ejecuta **automáticamente todos los días a las 5:00 AM** via cron.

Log de sincronización: `/var/log/academic-sync.log`

## Comandos Útiles

### Sync completo (todas las sedes y carreras)

```bash
// turbo
ssh camus@192.168.50.22 "cd /var/www/academico-backend && sudo -u www-data php artisan academic:sync 1-2026"
```

### Sync específico por sede y carrera

```bash
ssh camus@192.168.50.22 "cd /var/www/academico-backend && sudo -u www-data php artisan academic:sync 1-2026 --sede=1 --carrera=CARDER"
```

Cambiar `--sede=X` (1=Cochabamba, 4=El Alto, etc.) y `--carrera=CODIGO`.

### Ver log del último sync

```bash
// turbo
ssh camus@192.168.50.22 "tail -50 /var/log/academic-sync.log"
```

## Agregar Carreras Faltantes al Fallback

Si una carrera no aparece en el sync pero existe en la API de Planning:

1. Abrir `app/Console/Commands/SyncAcademicData.php`
2. Buscar el array `$fallbackCarreras` (línea ~87)
3. Agregar la sigla de la carrera faltante:

```php
$fallbackCarreras = [
    'CARDER', // Derecho
    'CARSON', // Sonido
    'CARMED', // Medicina
    'CARVET', // Veterinaria
    'CARENL', // Enfermería La Paz
    'NUEVA_SIGLA', // Nueva carrera a agregar
];
```

4. Commit, push y deploy.

## Verificar si un Docente Tiene Grupos

```bash
ssh camus@192.168.50.22 "echo 'SELECT g.id, g.nombre, g.tipo, a.codigo FROM grupos g JOIN asignaturas a ON g.asignatura_id = a.id JOIN docentes d ON g.docente_id = d.id WHERE d.ci = CI_DEL_DOCENTE;' | sudo mysql -u root academico"
```

Reemplazar `CI_DEL_DOCENTE` con el carnet de identidad.
