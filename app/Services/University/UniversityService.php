<?php

namespace App\Services\University;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;

class UniversityService
{
    protected string $authUrl;
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct()
    {
        // Se cargan desde el .env
        $this->authUrl = config('services.university.auth_url');
        $this->baseUrl = config('services.university.base_url');
        $this->clientId = config('services.university.client_id');
        $this->clientSecret = config('services.university.client_secret');
    }

    /**
     * Obtiene el token de acceso (Cacheado por 50 min).
     */
    public function getToken(): string
    {
        return Cache::remember('university_token', 3000, function () { // 50 minutos
            $response = Http::asForm()->post($this->authUrl, [
                'client_id' => $this->clientId,
                'grant_type' => 'client_credentials',
                'client_secret' => $this->clientSecret,
            ]);

            if ($response->failed()) {
                throw new \Exception('Error al autenticar con University API: ' . $response->body());
            }

            // Asumiendo que la respuesta es JSON { "access_token": "..." }
            return $response->json('access_token');
        });
    }

    /**
     * Realiza una petición GET autenticada.
     */
    protected function get(string $endpoint, array $queryParams = [])
    {
        $token = $this->getToken();
        
        // Debug: Log URL
        $url = "{$this->baseUrl}/{$endpoint}";
        // Manual query string build to be 100% sure
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        // echo "Requesting: $url \n"; // Uncomment for CLI debug if needed

        $response = Http::acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'clientId' => $this->clientId, // Header requerido según Postman
            ]) 
            ->withToken($token)
            ->get($url); // Send URL directly without second arg params

        if ($response->status() === 401) {
            Cache::forget('university_token');
            // Reintento simple
            $token = $this->getToken();
            $response = Http::acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'clientId' => $this->clientId,
                ])
                ->withToken($token)
                ->get($url);
        }

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json();
    }

    // --- Endpoints de Negocio ---

    /**
     * Listar Carreras por Sede.
     * Endpoint: /careers
     */
    public function getCareers(string $branchCode)
    {
        return $this->get('careers', [
            'branchOfficeCode' => $branchCode
        ]);
    }

    /**
     * Listar Asignaturas (Courses) por Sede y Carrera.
     * Endpoint: /courses
     */
    public function getCourses(string $branchCode, string $careerCode)
    {
        return $this->get('courses', [
            'branchOfficeCode' => $branchCode,
            'careerCode' => $careerCode,
        ]);
    }

    /**
     * Obtener Programa Analítico.
     * Endpoint: /analyticalProgram
     */
    public function getAnalyticalProgram(string $courseCode, string $branchCode, string $careerCode)
    {
        return $this->get('analyticalProgram', [
            'courseCode' => $courseCode,
            'branchOfficeCode' => $branchCode,
            'careerCode' => $careerCode,
        ]);
    }
}
