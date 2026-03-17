<?php

namespace App\Console\Commands;

use App\Services\FusionBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comando de rescate para fusiones de materias comunes.
 * Permite listar, restaurar y verificar backups creados automáticamente.
 */
class FusionRescueCommand extends Command
{
    protected $signature = 'fusion:rescue
                            {--list : Listar backups disponibles para un token}
                            {--restore= : ID del backup a restaurar}
                            {--token= : Token común para filtrar listado}
                            {--verify= : Verificar integridad de un backup específico}
                            {--force : Forzar restauración sin confirmación}';

    protected $description = 'Gestionar backups de seguridad de fusiones de materias comunes';

    protected FusionBackupService $backupService;

    public function __construct(FusionBackupService $backupService)
    {
        parent::__construct();
        $this->backupService = $backupService;
    }

    public function handle(): int
    {
        $listMode = $this->option('list');
        $restoreId = $this->option('restore');
        $verifyId = $this->option('verify');
        $token = $this->option('token');
        $force = $this->option('force');

        if ($listMode) {
            return $this->handleList($token);
        }

        if ($restoreId) {
            return $this->handleRestore($restoreId, $force);
        }

        if ($verifyId) {
            return $this->handleVerify($verifyId);
        }

        // Modo por defecto: mostrar ayuda
        $this->error('Debe especificar una acción: --list, --restore=ID o --verify=ID');
        $this->line('');
        $this->line('Uso:');
        $this->line('  php artisan fusion:rescue --list [--token=TOKEN]');
        $this->line('  php artisan fusion:rescue --restore=backup-id [--force]');
        $this->line('  php artisan fusion:rescue --verify=backup-id');
        return 1;
    }

    private function handleList(?string $token): int
    {
        if (!$token) {
            $token = $this->ask('Ingrese el token común (o presione Enter para listar todos)');
            if (empty($token)) {
                $token = null;
            }
        }

        try {
            if ($token) {
                $backups = $this->backupService->listBackups($token);
                $this->info("📚 Backups para token: {$token}");
            } else {
                // Listar todos los backups agrupados por token
                $allBackups = DB::table('fusion_backups')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('comun_token');
                
                $this->info("📚 Todos los backups disponibles:");
                foreach ($allBackups as $tokenGroup => $groupBackups) {
                    $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                    $this->line("Token: {$tokenGroup}");
                    foreach ($groupBackups as $backup) {
                        $snapshot = json_decode($backup->snapshot, true);
                        $this->line("  - {$backup->id}");
                        $this->line("    Creado: {$backup->created_at}");
                        $this->line("    Asignaturas: " . count($snapshot));
                    }
                }
                return 0;
            }

            if (empty($backups)) {
                $this->warn('No se encontraron backups para el token especificado.');
                return 0;
            }

            $this->table(
                ['ID', 'Creado', 'Asignaturas', 'Códigos'],
                array_map(function ($backup) {
                    return [
                        $backup['id'],
                        $backup['created_at'],
                        $backup['asignaturas_count'],
                        implode(', ', $backup['asignaturas'])
                    ];
                }, $backups)
            );

            return 0;

        } catch (\Exception $e) {
            $this->error('Error al listar backups: ' . $e->getMessage());
            Log::error('FusionRescue list error', ['error' => $e->getMessage()]);
            return 1;
        }
    }

    private function handleRestore(string $backupId, bool $force): int
    {
        if (!$force && !$this->confirm("¿Está seguro de restaurar el backup {$backupId}? Esto sobrescribirá datos actuales.")) {
            $this->info('Restauración cancelada.');
            return 0;
        }

        $this->info("Iniciando restauración del backup {$backupId}...");

        try {
            $success = $this->backupService->restoreBackup($backupId);

            if ($success) {
                $this->info('✅ Restauración completada exitosamente.');
                Log::info('FusionRescue restore completed', ['backup_id' => $backupId]);
                return 0;
            } else {
                $this->error('❌ La restauración falló (ver logs para detalles).');
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Error durante la restauración: ' . $e->getMessage());
            Log::error('FusionRescue restore error', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }

    private function handleVerify(string $backupId): int
    {
        $this->info("Verificando integridad del backup {$backupId}...");

        try {
            $report = $this->backupService->verifyIntegrity($backupId, ''); // token se obtiene del backup

            $this->info("📋 Reporte de integridad:");
            $this->line("  Estado: " . $report['status']);
            $this->line("  Backup ID: " . $report['backup_id']);
            $this->line("  Timestamp: " . $report['timestamp']);
            
            $comp = $report['comparison'];
            $this->line("  Comparación:");
            $this->line("    Asignaturas: antes={$comp['asignaturas']['before']}, después={$comp['asignaturas']['after']}, coincide=" . ($comp['asignaturas']['match'] ? '✅' : '❌'));
            $this->line("    Cronogramas: antes={$comp['cronogramas']['before']}, después={$comp['cronogramas']['after']}, coincide=" . ($comp['cronogramas']['match'] ? '✅' : '❌'));

            if (isset($report['message'])) {
                $this->line("  Mensaje: " . $report['message']);
            }

            if (isset($report['details'])) {
                $this->warn("  Detalles de advertencia:");
                foreach ($report['details'] as $detail) {
                    $this->line("    - {$detail}");
                }
            }

            return $report['status'] === 'ok' ? 0 : 1;

        } catch (\Exception $e) {
            $this->error('Error durante la verificación: ' . $e->getMessage());
            Log::error('FusionRescue verify error', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
}