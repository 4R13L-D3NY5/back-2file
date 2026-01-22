<?php

namespace App\Console\Commands;

use App\Services\SigaApiClient;
use Illuminate\Console\Command;

class TestSigaConnection extends Command
{
    protected $signature = 'siga:test {branchOffice=cba}';
    protected $description = 'Test SIGA API connection and list careers';

    public function handle(SigaApiClient $client)
    {
        $branchOffice = $this->argument('branchOffice');

        $this->info("Testing SIGA API connection...");
        $this->newLine();

        try {
            // Test token retrieval
            $this->info("1. Getting access token...");
            $token = $client->getToken();
            $this->line("   ✓ Token obtained: " . substr($token, 0, 50) . "...");

            // Test careers endpoint
            $this->newLine();
            $this->info("2. Fetching careers for sede: {$branchOffice}");
            $careers = $client->getCareers($branchOffice);

            if (empty($careers)) {
                $this->warn("   No careers found for {$branchOffice}");
                return 1;
            }

            $this->table(
                ['Code', 'Name'],
                collect($careers)->map(fn($c) => [
                    $c['careerCode'] ?? 'N/A',
                    $c['careerName'] ?? 'N/A'
                ])->toArray()
            );

            $this->info("   ✓ Found " . count($careers) . " careers");

            // Test courses endpoint (first career)
            $this->newLine();
            $firstCareer = $careers[0]['careerCode'] ?? null;
            if ($firstCareer) {
                $this->info("3. Fetching courses for {$firstCareer}...");
                $courses = $client->getCourses($branchOffice, $firstCareer);

                $this->table(
                    ['Code', 'Name', 'Credits', 'Semester'],
                    collect($courses)->take(10)->map(fn($c) => [
                        $c['courseCode'] ?? 'N/A',
                        substr($c['courseName'] ?? 'N/A', 0, 40),
                        $c['credits'] ?? 0,
                        $c['semester'] ?? 0
                    ])->toArray()
                );

                $this->info("   ✓ Found " . count($courses) . " courses (showing first 10)");
            }

            $this->newLine();
            $this->info("✅ SIGA API connection successful!");

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return 1;
        }
    }
}
