<?php

namespace App\Services\Proxies;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebshareProxyService
{
    private string $apiKey;
    private string $baseUrl = 'https://proxy.webshare.io/api/v2/';

    public function __construct()
    {
        $this->apiKey = config('services.webshare.api_key', env('WEBSHARE_API_KEY'));
    }

    /**
     * Obtém um proxy aleatório para um país específico
     *
     * @param string $countryCode Código do país (ex: 'BR', 'US')
     * @return array|null Dados do proxy ou null se não encontrado
     */
    public function getRandomProxy(string $countryCode): ?array
    {
        $proxies = $this->getProxiesByCountry($countryCode);
        
        if (empty($proxies)) {
            Log::warning("Nenhum proxy encontrado para o país: {$countryCode}");
            return null;
        }

        $proxy = $proxies[array_rand($proxies)];
        
        // Resolve o IP se necessário
        $proxy['proxy_ip'] = $this->resolveProxyIp($proxy['proxy_address']);
        
        return $proxy;
    }

    /**
     * Obtém lista de proxies por país
     *
     * @param string $countryCode Código do país
     * @return array Lista de proxies
     */
    public function getProxiesByCountry(string $countryCode): array
    {
        $cacheKey = "webshare_proxies_{$countryCode}";
        
        return Cache::remember($cacheKey, 300, function () use ($countryCode) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Token ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->get($this->baseUrl . 'proxy/list/', [
                    'mode' => 'direct',
                    'page' => 1,
                    'page_size' => 100,
                    'country_code' => strtoupper($countryCode)
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['results'] ?? [];
                }

                Log::error('Erro ao buscar proxies: ' . $response->body());
                return [];

            } catch (\Exception $e) {
                Log::error('Exceção ao buscar proxies: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Resolve o IP de um endereço de proxy
     *
     * @param string $proxyAddress Endereço do proxy
     * @return string IP resolvido ou endereço original
     */
    private function resolveProxyIp(string $proxyAddress): string
    {
        try {
            $ip = gethostbyname($proxyAddress);
            
            // Se gethostbyname retornar o mesmo valor, significa que não conseguiu resolver
            if ($ip !== $proxyAddress && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
            
            return $proxyAddress;
        } catch (\Exception $e) {
            Log::warning("Não foi possível resolver IP para {$proxyAddress}: " . $e->getMessage());
            return $proxyAddress;
        }
    }

    /**
     * Testa a conectividade de um proxy
     *
     * @param array $proxy Dados do proxy
     * @return bool True se o proxy estiver funcionando
     */
    public function testProxy(array $proxy): bool
    {
        try {
            $proxyUrl = "http://{$proxy['username']}:{$proxy['password']}@{$proxy['proxy_address']}:{$proxy['port']}";
            
            $response = Http::timeout(10)
                ->withOptions([
                    'proxy' => $proxyUrl,
                    'verify' => false,
                ])
                ->get('http://httpbin.org/ip');

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning("Proxy {$proxy['proxy_address']} falhou no teste: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtém estatísticas de uso dos proxies
     *
     * @return array Estatísticas de uso
     */
    public function getUsageStats(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . 'proxy/config/');

            if ($response->successful()) {
                return $response->json();
            }

            return [];
        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas: ' . $e->getMessage());
            return [];
        }
    }
}