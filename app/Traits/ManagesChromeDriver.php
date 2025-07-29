<?php

namespace App\Traits;

trait ManagesChromeDriver
{
    /**
     * Encontra uma porta disponível para o ChromeDriver
     *
     * @param int $startPort Porta inicial para buscar
     * @return int Porta disponível
     */
    protected function getAvailablePort(int $startPort = 9515): int
    {
        $maxAttempts = 100;
        $port = $startPort;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($this->isPortAvailable($port)) {
                return $port;
            }
            $port++;
        }

        // Se não encontrar uma porta disponível, retorna a porta padrão
        return $startPort;
    }

    /**
     * Verifica se uma porta está disponível
     *
     * @param int $port Porta para verificar
     * @return bool True se a porta estiver disponível
     */
    protected function isPortAvailable(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        
        if ($connection) {
            fclose($connection);
            return false; // Porta está em uso
        }
        
        return true; // Porta está disponível
    }

    /**
     * Mata processos ChromeDriver órfãos
     *
     * @return void
     */
    protected function killOrphanedChromeDrivers(): void
    {
        $osFamily = PHP_OS_FAMILY;

        try {
            if ($osFamily === 'Windows') {
                exec('taskkill /F /IM chromedriver.exe /T 2>NUL', $output, $returnCode);
            } elseif ($osFamily === 'Linux' || $osFamily === 'Darwin') {
                exec('pkill -f chromedriver 2>/dev/null', $output, $returnCode);
            }
        } catch (\Exception $e) {
            // Ignora erros na limpeza de processos
        }
    }

    /**
     * Verifica se um processo ChromeDriver está rodando em uma porta específica
     *
     * @param int $port Porta para verificar
     * @return bool True se houver um processo na porta
     */
    protected function isChromeDriverRunningOnPort(int $port): bool
    {
        $osFamily = PHP_OS_FAMILY;

        try {
            if ($osFamily === 'Windows') {
                exec("netstat -ano | findstr :{$port}", $output);
                return !empty($output);
            } elseif ($osFamily === 'Linux' || $osFamily === 'Darwin') {
                exec("lsof -i :{$port} 2>/dev/null", $output);
                return !empty($output);
            }
        } catch (\Exception $e) {
            // Em caso de erro, assume que não há processo rodando
        }

        return false;
    }

    /**
     * Limpa processos ChromeDriver específicos baseados no PID
     *
     * @param array $pids Array de PIDs para limpar
     * @return void
     */
    protected function cleanupChromeDriverProcesses(array $pids = []): void
    {
        if (empty($pids)) {
            return;
        }

        $osFamily = PHP_OS_FAMILY;

        foreach ($pids as $pid) {
            try {
                if ($osFamily === 'Windows') {
                    exec("taskkill /F /PID {$pid} 2>NUL");
                } elseif ($osFamily === 'Linux' || $osFamily === 'Darwin') {
                    exec("kill -9 {$pid} 2>/dev/null");
                }
            } catch (\Exception $e) {
                // Ignora erros individuais
            }
        }
    }

    /**
     * Obtém uma lista de processos ChromeDriver ativos
     *
     * @return array Array de PIDs de processos ChromeDriver
     */
    protected function getActiveChromeDriverProcesses(): array
    {
        $osFamily = PHP_OS_FAMILY;
        $pids = [];

        try {
            if ($osFamily === 'Windows') {
                exec('tasklist /FI "IMAGENAME eq chromedriver.exe" /FO CSV', $output);
                foreach ($output as $line) {
                    if (strpos($line, 'chromedriver.exe') !== false) {
                        $parts = str_getcsv($line);
                        if (isset($parts[1])) {
                            $pids[] = (int)$parts[1];
                        }
                    }
                }
            } elseif ($osFamily === 'Linux' || $osFamily === 'Darwin') {
                exec('pgrep -f chromedriver', $output);
                foreach ($output as $pid) {
                    if (is_numeric($pid)) {
                        $pids[] = (int)$pid;
                    }
                }
            }
        } catch (\Exception $e) {
            // Retorna array vazio em caso de erro
        }

        return $pids;
    }
}