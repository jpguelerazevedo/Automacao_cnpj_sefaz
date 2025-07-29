<?php

namespace App\Services\Scrapers\Clients;

use App\Services\Proxies\WebshareProxyService;
use Illuminate\Support\Facades\Log;
use App\Services\Scrapers\Contracts\WebClientInterface;
use App\Traits\ManagesChromeDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Panther\Client;
use Facebook\WebDriver\Remote\RemoteWebDriver;

/**
 * Cliente web que utiliza o Symfony Panther para controlar um navegador real (Chrome).
 *
 * Esta classe é a implementação principal para scrapers que necessitam de uma
 * interação complexa com o navegador, como a execução de JavaScript, o preenchimento
 * de formulários dinâmicos e a manipulação de elementos que são carregados
 * de forma assíncrona.
 *
 * Principais responsabilidades:
 * - Gerenciar o ciclo de vida completo do `Panther\Client`.
 * - Integrar-se com o `WebshareProxyService` para configurar proxies com autenticação.
 * - Utilizar o trait `ManagesChromeDriver` para encontrar portas de debug disponíveis
 *   e garantir que os processos do ChromeDriver sejam limpos corretamente, evitando conflitos.
 * - Lidar com o download de arquivos e a manipulação do DOM através do `Crawler`.
 */
class PantherClient implements WebClientInterface
{
    use ManagesChromeDriver;

    private ?string $proxyUrl = null;
    private WebshareProxyService $proxyService;

    /**
     * Estratégia de carregamento da página (normal, eager, none).
     * @var string
     */
    private string $pageLoadStrategy = 'normal';

    /**
     * Tamanho da janela do navegador (largura x altura)
     * @var string
     */
    private string $windowSize = '1024,800';

    /**
     * Instância do Panther Client para controle do navegador.
     * @var Client|null
     */
    private ?Client $client = null;

    /**
     * Instância do Crawler para manipular o DOM da página.
     * @var Crawler|null
     */
    private ?Crawler $crawler = null;

    /**
     * Diretório de download padrão
     * @var string
     */
    private string $downloadDir;

    /**
     * Define o tamanho da janela do navegador
     * @param int $width Largura da janela
     * @param int $height Altura da janela
     * @return void
     */
    public function setWindowSize(int $width, int $height): void
    {
        $this->windowSize = $width . ',' . $height;

        // Se o cliente já estiver inicializado, atualiza o tamanho da janela
        if ($this->client !== null) {
            try {
                $this->client->getWebDriver()->manage()->window()->setSize(
                    new \Facebook\WebDriver\WebDriverDimension($width, $height)
                );
            } catch (\Throwable $e) {
                // Ignora erros ao tentar redimensionar a janela
                // Isso pode acontecer se o cliente não estiver totalmente inicializado
            }
        }
    }

    /**
     * Define a estratégia de carregamento da página.
     *
     * @param string $strategy 'normal', 'eager' ou 'none'
     * @return void
     */
    public function setPageLoadStrategy(string $strategy): void
    {
        if (in_array($strategy, ['normal', 'eager', 'none'])) {
            $this->pageLoadStrategy = $strategy;

            // Se o cliente já estiver inicializado, reinicia com a nova estratégia
            if ($this->client !== null) {
                try {
                    $this->client->quit();
                } catch (\Exception $e) {
                    $this->cleanupChromeProcesses();
                }
                $this->initializeClient();
            }
        }
    }

    /**
     * Configura um proxy brasileiro para o cliente
     *
     * @return void
     */
    public function setProxyBR(WebshareProxyService $proxyService): void
    {
        $this->proxyService = $proxyService;
        $proxy = $this->proxyService->getRandomProxy('BR');

        if ($proxy) {
            // Usa o IP resolvido se disponível, senão usa o endereço DNS
            $proxyAddress = $proxy['proxy_ip'] ?? $proxy['proxy_address'];
            $proxyPort = $proxy['port'];
            $proxyUsername = $proxy['username'];
            $proxyPassword = $proxy['password'];

            Log::info('Configurando proxy com IP resolvido', [
                'proxy_address' => $proxy['proxy_address'],
                'proxy_ip' => $proxyAddress
            ]);

            $this->proxyUrl = "http://{$proxyUsername}:{$proxyPassword}@{$proxyAddress}:{$proxyPort}";

            // Reinicializa o cliente com o novo proxy
            if ($this->client !== null) {
                try {
                    $this->client->quit();
                } catch (\Exception $e) {
                    $this->cleanupChromeProcesses();
                }
                $this->initializeClient();
            }
        }
    }

    /**
     * Configura um proxy
     *
     * @return void
     */
    public function setProxy(WebshareProxyService $proxyService, string $countryCode): void
    {
        $this->proxyService = $proxyService;
        $proxy = $this->proxyService->getRandomProxy($countryCode);

        if ($proxy) {
            // Usa o IP resolvido se disponível, senão usa o endereço DNS
            $proxyAddress = $proxy['proxy_ip'] ?? $proxy['proxy_address'];
            $proxyPort = $proxy['port'];
            $proxyUsername = $proxy['username'];
            $proxyPassword = $proxy['password'];

            Log::info('Configurando proxy com IP resolvido', [
                'proxy_address' => $proxy['proxy_address'],
                'proxy_ip' => $proxyAddress
            ]);

            $this->proxyUrl = "http://{$proxyUsername}:{$proxyPassword}@{$proxyAddress}:{$proxyPort}";

            // Reinicializa o cliente com o novo proxy
            if ($this->client !== null) {
                try {
                    $this->client->quit();
                } catch (\Exception $e) {
                    $this->cleanupChromeProcesses();
                }
                $this->initializeClient();
            }
        }
    }

    /**
     * Maximiza a janela do navegador para tela cheia
     * @return void
     */
    public function maximizeWindow(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->getWebDriver()->manage()->window()->maximize();
            } catch (\Throwable $e) {
                // Ignora erros ao tentar maximizar a janela
                // Isso pode acontecer se o cliente não estiver totalmente inicializado
            }
        }
    }

    /**
     * Construtor da classe.
     * Configura o cliente Panther com as opções do Chrome.
     */
    public function __construct()
    {
        $this->downloadDir = base_path() . '/tmp';

        // Executa limpeza periódica com base em probabilidade
        // Isso evita que todos os processos tentem limpar ao mesmo tempo
        if (rand(1, 10) === 1) { // 10% de chance de executar a limpeza
            $this->periodicCleanup();
        }

        $this->initializeClient();
    }

    /**
     * Executa limpeza periódica de recursos
     */
    private function periodicCleanup(): void
    {
        try {
            // Limpa diretórios temporários antigos (mais de 1 hora)
            $this->cleanupOldTempDirectories();

            // Limpa processos do Chrome que podem estar travados
            $this->cleanupChromeProcesses();
        } catch (\Throwable $e) {
            // Erro silencioso para não interromper o fluxo principal
        }
    }

    /**
     * Limpa diretórios temporários antigos (mais de 1 hora)
     */
    private function cleanupOldTempDirectories(): void
    {
        try {
            $lockFile = sys_get_temp_dir() . '/chrome_cleanup_old.lock';

            // Tenta obter lock exclusivo
            $fp = fopen($lockFile, 'w+');
            if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
                // Se não conseguir o lock, outro worker já está limpando
                if ($fp) {
                    fclose($fp);
                }
                return;
            }

            $tempDir = sys_get_temp_dir();
            $pattern = $tempDir . '/chrome_user_data_*';
            $thirtyMinutesAgo = time() - 1800; // 30 minutos atrás

            foreach (glob($pattern) as $dir) {
                if (is_dir($dir)) {
                    $dirTime = filemtime($dir);
                    $lockFile = $dir . '.lock';

                    // Só remove se:
                    // 1. O diretório é antigo (>30min)
                    // 2. Não tem arquivo de lock OU
                    // 3. Tem arquivo de lock mas o processo não existe mais
                    if ($dirTime < $thirtyMinutesAgo &&
                        (!file_exists($lockFile) ||
                        (($pid = @file_get_contents($lockFile)) && !$this->isProcessRunning($pid)))) {

                        $this->recursiveRemoveDirectory($dir);
                    }
                }
            }

            // Também remove arquivos de bloqueio antigos
            foreach (glob($pattern . '.lock') as $lockFile) {
                if (file_exists($lockFile)) {
                    $fileTime = filemtime($lockFile);
                    if ($fileTime < $thirtyMinutesAgo) {

                        @unlink($lockFile);
                    }
                }
            }
        } catch (\Exception $e) {
            // Erro silencioso para não interromper o fluxo principal
        }
    }

    /**
     * Remove um diretório e todo seu conteúdo recursivamente
     *
     * @param string $dir Caminho para o diretório a ser removido
     * @return bool True se o diretório foi removido com sucesso, False caso contrário
     */
    private function recursiveRemoveDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $path = $file->getRealPath();
                if ($path && file_exists($path)) {
                    if ($file->isDir()) {
                        @rmdir($path);
                    } else {
                        @unlink($path);
                    }
                }
            }

            return @rmdir($dir);
        } catch (\Exception $e) {
            echo "Erro ao remover diretório {$dir}: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Define o diretório onde os arquivos serão baixados
     *
     * @param string $directory Caminho completo para o diretório de download
     * @return void
     */
    public function setDownloadDirectory(string $directory): void
    {
        try {
            $this->downloadDir = $directory;

            // Se o diretório não existir, cria-o
            if (!is_dir($this->downloadDir)) {
                if (!mkdir($this->downloadDir, 0755, true)) {
                    echo 'PantherClient: Falha ao criar diretório de download';
                    throw new \RuntimeException('Não foi possível criar o diretório de download: ' . $this->downloadDir);
                }

                echo 'PantherClient: Diretório de download criado com sucesso';
            }

            // Se o cliente já estiver inicializado, reinicializa-o com o novo diretório
            if ($this->client !== null) {
                // Fecha o cliente atual
                try {
                    $this->client->quit();
                    echo 'PantherClient: Cliente fechado com sucesso para reinicialização';
                } catch (\Exception $e) {
                    // Se falhar ao fechar o cliente, limpa os processos manualmente
                    echo 'PantherClient: Erro ao fechar cliente, limpando processos manualmente';
                    $this->cleanupChromeProcesses();
                }

                // Reinicializa o cliente com o novo diretório
                $this->initializeClient();
                echo 'PantherClient: Cliente reinicializado com novo diretório de download';
            }
        } catch (\Throwable $e) {
            echo 'PantherClient: Erro ao configurar diretório de download';
            throw $e;
        }
    }

    /**
     * Obtém o diretório de download atual
     *
     * @return string Caminho completo para o diretório de download
     */
    public function getDownloadDirectory(): string
    {
        return $this->downloadDir;
    }

    /**
     * Inicializa o cliente Panther com tratamento de portas e processos.
     */
    private function initializeClient(): void
    {
        try {
            // Verifica se estamos em ambiente de produção
            $isProduction = app()->environment('production');

            // Limpa processos antigos do Chrome
            $this->cleanupChromeProcesses();

            // Limpa diretórios temporários antigos
            $this->cleanupTempDirectories();

            $downloadDir = $this->downloadDir;



            // Cria um diretório temporário único para os dados do usuário do Chrome
            // Em produção, usamos um caminho mais específico para evitar conflitos
            if ($isProduction && getenv('PANTHER_CHROME_DATA_DIR')) {
                $tempBase = getenv('PANTHER_CHROME_DATA_DIR');
            } else {
                $tempBase = sys_get_temp_dir();
            }

            // Usa o ID do processo, timestamp, um identificador único e um número aleatório para garantir que seja único
            $userDataDir = $tempBase . '/chrome_user_data_' . getmypid() . '_' . time() . '_' . uniqid('', true) . '_' . rand(1000, 9999);

            // Adiciona um arquivo de bloqueio para garantir exclusividade
            $lockFile = $userDataDir . '.lock';

            // Verifica se já existe um diretório com o mesmo nome (muito improvavel, mas por segurança)
            if (is_dir($userDataDir)) {
                // Se existir, adiciona um sufixo aleatório adicional
                $userDataDir .= '_' . rand(10000, 99999);
                $lockFile = $userDataDir . '.lock';
            }

            // Tenta criar o diretório de dados
            if (!is_dir($userDataDir)) {
                if (!mkdir($userDataDir, 0755, true)) {
                    echo "Erro ao criar diretório de dados do Chrome: {$userDataDir}";
                    // Tentar um diretório alternativo
                    $userDataDir = sys_get_temp_dir() . '/chrome_user_data_alt_' . getmypid() . '_' . time() . '_' . uniqid('', true) . '_' . rand(1000, 9999);
                    $lockFile = $userDataDir . '.lock';

                    if (!mkdir($userDataDir, 0755, true)) {
                        throw new \RuntimeException("Não foi possível criar diretório de dados do Chrome: {$userDataDir}");
                    }
                }
            }

            // Cria um arquivo de bloqueio para este diretório
            file_put_contents($lockFile, getmypid());

            // Garante que o diretório exista e tenha permissões corretas
            chmod($userDataDir, 0755);

            // Opções básicas do Chrome
            $chromeOptions = [
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-blink-features=AutomationControlled',
                '--disable-web-security',
                '--allow-insecure-localhost',
                '--allow-running-insecure-content',
                '--ignore-certificate-errors',
                '--ignore-ssl-errors',
                '--safebrowsing-disable-download-protection',
                '--window-size=' . $this->windowSize,
                '--start-maximized',
                '--ignore-certificate-errors-spki-list',
                '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.140 Safari/537.36',
                '--user-data-dir=' . $userDataDir,
                '--disable-popup-blocking',
                '--disable-notifications',
                '--disable-dev-shm-usage',
                '--no-sandbox',
                //'--headless=new'
                
            ];

            // Adiciona a opção headless apenas se não estiver em ambiente de desenvolvimento
            if (app()->environment('production')) {
                $chromeOptions[] = '--headless=new';
            }

            $capabilities = DesiredCapabilities::chrome();
            $capabilities->setCapability('pageLoadStrategy', $this->pageLoadStrategy);

            if ($this->proxyUrl) {
                Log::info('Configurando proxy: ' . $this->proxyUrl);

                // Extrai as informações do proxy da URL
                $proxyInfo = parse_url($this->proxyUrl);
                $proxyAddress = $proxyInfo['host'] ?? '';
                $proxyPort = $proxyInfo['port'] ?? '';
                $proxyUsername = urldecode($proxyInfo['user'] ?? '');
                $proxyPassword = urldecode($proxyInfo['pass'] ?? '');

                if ($proxyUsername && $proxyPassword) {
                    // Cria uma extensão Chrome para autenticar o proxy
                    $manifestJson = json_encode([
                        'manifest_version' => 3,
                        'name' => 'Chrome Proxy',
                        'version' => '1.0.0',
                        'permissions' => [
                            'proxy',
                            'webRequest',
                            'webRequestAuthProvider',
                            '<all_urls>'
                        ],
                        'host_permissions' => ['<all_urls>'],
                        'background' => [
                            'service_worker' => 'background.js',
                        ],
                    ], JSON_PRETTY_PRINT);

                    $backgroundJs = sprintf(
                        'chrome.runtime.onInstalled.addListener(() => {
                            console.log("Extension installed");
                        });

                        chrome.webRequest.onAuthRequired.addListener(
                            function(details, callbackFn) {
                                console.log("Auth required for: ", details.url);
                                callbackFn({
                                    authCredentials: {
                                        username: "%s",
                                        password: "%s"
                                    }
                                });
                            },
                            {urls: ["<all_urls>"]},
                            ["asyncBlocking"]
                        );

                        chrome.proxy.settings.set({
                            value: {
                                mode: "fixed_servers",
                                rules: {
                                    singleProxy: {
                                        scheme: "http",
                                        host: "%s",
                                        port: %d
                                    }
                                }
                            },
                            scope: "regular"
                        }, function() {
                            console.log("Proxy set");
                        });',
                        $proxyUsername,
                        $proxyPassword,
                        $proxyAddress,
                        (int)$proxyPort
                    );

                    // Cria um diretório temporário para a extensão
                    $extensionDir = sys_get_temp_dir() . '/proxy_auth_' . uniqid();
                    if (!is_dir($extensionDir)) {
                        mkdir($extensionDir, 0755, true);
                    }

                    // Salva os arquivos da extensão
                    if (!file_put_contents($extensionDir . '/manifest.json', $manifestJson)) {
                        Log::error('Falha ao criar manifest.json');
                        return;
                    }
                    if (!file_put_contents($extensionDir . '/background.js', $backgroundJs)) {
                        Log::error('Falha ao criar background.js');
                        return;
                    }

                    // Cria o arquivo ZIP
                    $zip = new \ZipArchive();
                    $zipFile = $extensionDir . '.zip';
                    if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                        Log::error('Falha ao criar arquivo ZIP');
                        return;
                    }

                    // Adiciona os arquivos ao ZIP
                    if (!$zip->addFile($extensionDir . '/manifest.json', 'manifest.json')) {
                        Log::error('Falha ao adicionar manifest.json ao ZIP');
                        $zip->close();
                        return;
                    }
                    if (!$zip->addFile($extensionDir . '/background.js', 'background.js')) {
                        Log::error('Falha ao adicionar background.js ao ZIP');
                        $zip->close();
                        return;
                    }

                    // Fecha o ZIP antes de usar
                    $zip->close();

                    // Verifica se os arquivos foram criados corretamente
                    if (!file_exists($extensionDir . '/manifest.json') || !file_exists($extensionDir . '/background.js')) {
                        Log::error('Arquivos da extensão não foram criados corretamente');
                        return;
                    }

                    // Adiciona a extensão ao Chrome usando o caminho absoluto
                    $chromeOptions[] = '--load-extension=' . realpath($extensionDir);
                    Log::info('Extensão do proxy criada em: ' . realpath($extensionDir));

                    // Configura o proxy direto também como fallback
                    $chromeOptions[] = '--proxy-server=http://' . $proxyAddress . ':' . $proxyPort;
                } else {
                    // Se não tiver credenciais, usa proxy simples
                    $chromeOptions[] = '--proxy-server=http://' . $proxyAddress . ':' . $proxyPort;
                }
            }

            $chromePrefs = [
                'download.default_directory' => $downloadDir,
                'download.prompt_for_download' => false,
                'plugins.always_open_pdf_externally' => true,
                'safebrowsing.enabled' => true,
                'excludeSwitches' => ['enable-automation'],
                'useAutomationExtension' => false,
                'download.default_directory_for_http' => $downloadDir,
                'download.directory_upgrade' => true,
                'download.manager.showWhenStarting' => false,
                'profile.default_content_settings.popups' => 0,
                'profile.content_settings.exceptions.automatic_downloads.*.setting' => 1,
                'safebrowsing.disable_download_protection' => true,
            ];

            $chromeExperimentalOptions = [
                'args' => $chromeOptions,
                'prefs' => $chromePrefs,
            ];
            $capabilities->setCapability('goog:chromeOptions', $chromeExperimentalOptions);

            $maxRetries = 5; // Aumentando o número de tentativas
            $retry = 0;
            $lastException = null;

            while ($retry < $maxRetries) {
                try {
                    // Usa uma porta diferente a cada tentativa para evitar conflitos
                    $port = $this->getAvailablePort();

                    // Se não for a primeira tentativa, cria um novo diretório de dados
                    if ($retry > 0) {
                        // Limpa os processos antes de tentar novamente
                        $this->cleanupChromeProcesses();

                        // Cria um novo diretório de dados para a próxima tentativa
                        $userDataDir = sys_get_temp_dir() . '/chrome_user_data_' . getmypid() . '_' . time() . '_' . uniqid() . '_retry' . $retry . '_' . rand(1000, 9999);
                        $lockFile = $userDataDir . '.lock';

                        if (!is_dir($userDataDir)) {
                            mkdir($userDataDir, 0755, true);
                        }

                        // Cria um arquivo de bloqueio para este diretório
                        file_put_contents($lockFile, getmypid());

                        // Atualiza as opções do Chrome com o novo diretório
                        foreach ($chromeOptions as $key => $option) {
                            if (strpos($option, '--user-data-dir=') === 0) {
                                $chromeOptions[$key] = '--user-data-dir=' . $userDataDir;
                                break;
                            }
                        }

                        // Atualiza as capacidades
                        $chromeExperimentalOptions = [
                            'args' => $chromeOptions,
                            'prefs' => $chromePrefs,
                        ];
                        $capabilities->setCapability('goog:chromeOptions', $chromeExperimentalOptions);

                        echo "Usando novo diretório de dados: {$userDataDir}\n";
                    }

                    // Configurações para o cliente Chrome
                    $clientOptions = [
                        'capabilities' => $capabilities->toArray(),
                        'port' => $port,
                        'connection_timeout_in_ms' => 120000, // 2 minutos
                        'request_timeout_in_ms' => 120000, // 2 minutos
                        'chromedriver_version' => 'latest',
                    ];

                    // Verifica se há variáveis de ambiente definidas para o Panther
                    if (getenv('PANTHER_CHROME_BINARY')) {
                        $clientOptions['chrome_binary'] = getenv('PANTHER_CHROME_BINARY');
                    }

                    if (getenv('PANTHER_CHROME_DRIVER_BINARY')) {
                        $clientOptions['chromedriver_binary'] = getenv('PANTHER_CHROME_DRIVER_BINARY');
                    }

                    // Verifica se o diretório de dados está bloqueado por outro processo
                    if (file_exists($lockFile)) {
                        $lockPid = @file_get_contents($lockFile);
                        if ($lockPid && $lockPid != getmypid() && $this->isProcessRunning($lockPid)) {
                            // Tenta criar um novo diretório
                            $userDataDir = $tempBase . '/chrome_user_data_' . getmypid() . '_' . time() . '_' . uniqid('', true) . '_' . rand(10000, 99999);
                            $lockFile = $userDataDir . '.lock';

                            if (!mkdir($userDataDir, 0755, true)) {
                                throw new \RuntimeException("Não foi possível criar diretório alternativo: {$userDataDir}");
                            }
                            file_put_contents($lockFile, getmypid());

                            // Atualiza a opção de diretório de dados
                            foreach ($chromeOptions as $key => $option) {
                                if (strpos($option, '--user-data-dir=') === 0) {
                                    $chromeOptions[$key] = '--user-data-dir=' . $userDataDir;
                                    break;
                                }
                            }

                            // Atualiza as capabilities
                            $chromeExperimentalOptions['args'] = $chromeOptions;
                            $capabilities->setCapability('goog:chromeOptions', $chromeExperimentalOptions);
                            $clientOptions['capabilities'] = $capabilities->toArray();
                        }
                    }

                    // Tenta criar o cliente Chrome
                    $this->client = Client::createChromeClient(null, [], $clientOptions);

                    // Se chegou aqui, a inicialização foi bem-sucedida
                    echo "Cliente Chrome inicializado com sucesso!\n";
                    return;

                } catch (\Exception $e) {
                    $lastException = $e;
                    $retry++;

                    echo "Erro ao inicializar Chrome (tentativa {$retry} de {$maxRetries}): " . $e->getMessage() . "\n";

                    // Verifica se o erro é relacionado ao diretório de dados em uso
                    if (strpos($e->getMessage(), 'user data directory is already in use') !== false) {
                        echo "Diretório de dados já em uso. Tentando com um novo diretório...\n";
                        // Força a limpeza de processos e diretórios
                        $this->cleanupChromeProcesses();
                        $this->cleanupTempDirectories();
                    }

                    // Aguarda um pouco antes de tentar novamente, aumentando o tempo a cada tentativa
                    $sleepTime = 2 + $retry;
                    echo "Aguardando {$sleepTime} segundos antes da próxima tentativa...\n";
                    sleep($sleepTime);
                }
            }

            // Se chegou aqui, todas as tentativas falharam
            echo "Falha ao inicializar o cliente Chrome após {$maxRetries} tentativas\n";
            throw $lastException ?? new \Exception('Falha ao inicializar o cliente Chrome após ' . $maxRetries . ' tentativas');
        } catch (\Throwable $e) {
            // Se ocorrer um erro durante a inicialização, limpa recursos e lança a exceção
            if ($this->client !== null) {
                try {
                    $this->client->quit();
                } catch (\Throwable $quitException) {
                    // Ignora erros ao fechar o cliente
                }
                $this->client = null;
            }

            // Limpa processos e diretórios temporários
            $this->cleanupChromeProcesses();
            $this->cleanupTempDirectories();

            throw $e;
        }
    }

    /**
     * Faz uma requisição HTTP para uma URL específica.
     *
     * @param string $url URL para fazer a requisição.
     * @param string $method Método HTTP (GET por padrão).
     * @param array $data Dados para requisições POST.
     * @return Crawler Retorna o DOM da página como um Crawler.
     */
    public function request(string $url, string $method = 'GET', array $data = []): Crawler
    {
        // Verifica se o cliente está inicializado, caso contrário reinicializa
        if ($this->client === null) {
            $this->initializeClient();
        }

        // Verifica novamente se o cliente foi inicializado com sucesso
        if ($this->client === null) {
            throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
        }

        try {
            if ($method === 'GET') {
                // Navegar para a URL usando o WebDriver.
                $this->client->getWebDriver()->navigate()->to($url);

                // Aguardar até que o elemento 'body' esteja visível.
                $this->client->waitForVisibility('body');

                // Capturar o DOM da página atual.
                $this->crawler = $this->client->getCrawler();
            } else {
                // Fazer uma requisição HTTP (POST, PUT, etc.) diretamente pelo Panther.
                $this->client->request($method, $url, $data);
                $this->crawler = $this->client->getCrawler();
            }

            return $this->crawler;
        } catch (\Exception $e) {
            // Se ocorrer um erro relacionado ao diretório de dados, tenta reinicializar o cliente
            if (strpos($e->getMessage(), 'user data directory is already in use') !== false) {
                // Limpa recursos e reinicializa o cliente
                if ($this->client !== null) {
                    try {
                        $this->client->quit();
                    } catch (\Throwable $quitException) {
                        // Ignora erros ao fechar o cliente
                    }
                    $this->client = null;
                }

                // Limpa processos e diretórios temporários
                $this->cleanupChromeProcesses();
                $this->cleanupTempDirectories();

                // Reinicializa o cliente
                $this->initializeClient();

                // Verifica novamente se o cliente foi inicializado com sucesso
                if ($this->client === null) {
                    throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
                }

                // Tenta novamente a requisição
                if ($method === 'GET') {
                    $this->client->getWebDriver()->navigate()->to($url);
                    $this->client->waitForVisibility('body');
                    $this->crawler = $this->client->getCrawler();
                } else {
                    $this->client->request($method, $url, $data);
                    $this->crawler = $this->client->getCrawler();
                }

                return $this->crawler;
            }

            // Se for outro tipo de erro, propaga a exceção
            throw $e;
        }
    }

    /**
     * Obtém o Crawler atual.
     *
     * @return Crawler
     * @throws \RuntimeException Caso ocorra erro ao capturar o DOM da página.
     */
    public function getCrawler(): Crawler
    {
        // Verifica se o cliente está inicializado, caso contrário reinicializa
        if ($this->client === null) {
            $this->initializeClient();
        }

        // Verifica novamente se o cliente foi inicializado com sucesso
        if ($this->client === null) {
            throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
        }

        try {
            // Capturar o DOM da página atual.
            $this->crawler = $this->client->getCrawler();

            // Aguardar que a página esteja carregada completamente.
            $this->client->waitForVisibility('body');

            return $this->crawler;
        } catch (\Exception $e) {
            // Se ocorrer um erro relacionado ao diretório de dados, tenta reinicializar o cliente
            if (strpos($e->getMessage(), 'user data directory is already in use') !== false) {
                // Limpa recursos e reinicializa o cliente
                if ($this->client !== null) {
                    try {
                        $this->client->quit();
                    } catch (\Throwable $quitException) {
                        // Ignora erros ao fechar o cliente
                    }
                    $this->client = null;
                }

                // Limpa processos e diretórios temporários
                $this->cleanupChromeProcesses();
                $this->cleanupTempDirectories();

                // Reinicializa o cliente
                $this->initializeClient();

                // Verifica novamente se o cliente foi inicializado com sucesso
                if ($this->client === null) {
                    throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
                }

                // Tenta novamente
                $this->crawler = $this->client->getCrawler();
                $this->client->waitForVisibility('body');
                return $this->crawler;
            }

            // Lançar exceção caso algo dê errado.
            throw new \RuntimeException('Erro ao obter crawler: ' . $e->getMessage());
        }
    }

    /**
     * Executa um script JavaScript na página atual.
     *
     * @param string $script Código JavaScript a ser executado.
     * @return mixed Resultado do script executado.
     */
    public function executeScript(string $script): mixed
    {
        // Verifica se o cliente está inicializado, caso contrário reinicializa
        if ($this->client === null) {
            $this->initializeClient();
        }

        // Verifica novamente se o cliente foi inicializado com sucesso
        if ($this->client === null) {
            throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
        }

        return $this->client->executeScript($script);
    }

    /**
     * Aguarda até que um elemento específico esteja visível na página.
     *
     * @param string $selector Seletor CSS do elemento a ser aguardado.
     * @param int $timeout Tempo máximo de espera (em segundos).
     * @return void
     */
    public function waitFor(string $selector, int $timeout = 30): void
    {
        // Verifica se o cliente está inicializado, caso contrário reinicializa
        if ($this->client === null) {
            $this->initializeClient();
        }

        // Verifica novamente se o cliente foi inicializado com sucesso
        if ($this->client === null) {
            throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
        }

        $this->client->waitFor($selector, $timeout);
    }

    /**
     * Obtém o WebDriver associado ao cliente Panther.
     *
     * @return RemoteWebDriver Instância do WebDriver.
     */
    public function getWebDriver(): RemoteWebDriver
    {
        // Verifica se o cliente está inicializado, caso contrário reinicializa
        if ($this->client === null) {
            $this->initializeClient();
        }

        // Verifica novamente se o cliente foi inicializado com sucesso
        if ($this->client === null) {
            throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
        }

        try {
            return $this->client->getWebDriver();
        } catch (\Exception $e) {
            // Se ocorrer um erro relacionado ao diretório de dados, tenta reinicializar o cliente
            if (strpos($e->getMessage(), 'user data directory is already in use') !== false) {
                // Limpa recursos e reinicializa o cliente
                if ($this->client !== null) {
                    try {
                        $this->client->quit();
                    } catch (\Throwable $quitException) {
                        // Ignora erros ao fechar o cliente
                    }
                    $this->client = null;
                }

                // Limpa processos e diretórios temporários
                $this->cleanupChromeProcesses();
                $this->cleanupTempDirectories();

                // Reinicializa o cliente
                $this->initializeClient();

                // Verifica novamente se o cliente foi inicializado com sucesso
                if ($this->client === null) {
                    throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
                }

                // Tenta novamente
                return $this->client->getWebDriver();
            }

            // Se for outro tipo de erro, propaga a exceção
            throw $e;
        }
    }

    /**
     * Obtém a URL atual carregada no navegador.
     *
     * @return string URL atual.
     */
    public function getCurrentURL(): string
    {
        // Verifica se o cliente está inicializado, caso contrário reinicializa
        if ($this->client === null) {
            $this->initializeClient();
        }

        // Verifica novamente se o cliente foi inicializado com sucesso
        if ($this->client === null) {
            throw new \RuntimeException('Não foi possível inicializar o cliente Chrome após múltiplas tentativas.');
        }

        return $this->client->getCurrentURL();
    }

    /**
     * Destrutor da classe.
     * Garante que o cliente Chrome seja fechado corretamente.
     */
    public function __destruct()
    {
        try {
            // Limpa recursos antes de destruir
            $this->cleanupChromeProcesses();
            $this->cleanupTempDirectories();
            if ($this->client !== null) {
                try {
                    $this->client->quit();
                } catch (\Throwable $e) {
                    // Se falhar ao fechar o cliente, limpa os processos manualmente
                    $this->cleanupChromeProcesses();
                }

                // Limpa diretórios temporários de dados do Chrome
                $this->cleanupTempDirectories();

                // Limpa o cliente para liberar recursos
                $this->client = null;
            } else {
                // Mesmo que o cliente seja null, tenta limpar processos e diretórios
                $this->cleanupChromeProcesses();
                $this->cleanupTempDirectories();
            }

            // Força a coleta de lixo para garantir que os recursos sejam liberados
            gc_collect_cycles();
        } catch (\Throwable $e) {
            // Ignora qualquer erro durante a limpeza
            // Não queremos que falhas na limpeza causem problemas no sistema
        }
    }

    /**
     * Limpa processos antigos do Chrome que possam estar interferindo.
     */
    private function cleanupChromeProcesses(): void
    {
        try {
            // Primeiro tenta fechar o cliente suavemente
            if ($this->client !== null) {
                try {
                    $this->client->quit();
                } catch (\Throwable $e) {
                    // Ignora erros ao fechar o cliente
                }
            }

            // Aguarda um momento para o cliente fechar
            usleep(500000); // 0.5 segundos

            // Detectar o sistema operacional usando PHP_OS_FAMILY
            $osFamily = PHP_OS_FAMILY;

            if ($osFamily === 'Linux') {
                // Primeiro tenta matar processos associados ao PID atual
                $currentPid = getmypid();
                exec("pkill -P $currentPid -f '(chrome)|(chromedriver)' 2>/dev/null");

                // Como fallback, procura por processos zumbis ou órfãos
                exec('ps aux | grep -i chrome | grep defunct | awk \'{print $2}\' | xargs -r kill -9 2>/dev/null');
                exec('ps aux | grep -i chromedriver | grep defunct | awk \'{print $2}\' | xargs -r kill -9 2>/dev/null');
            } elseif ($osFamily === 'Darwin') {
                // No macOS (Darwin)
                $currentPid = getmypid();
                exec("pkill -P $currentPid -f '(chrome)|(chromedriver)' 2>/dev/null");

                // Como fallback, tenta outros métodos
                exec('pkill -f "(chrome)|(chromedriver)" 2>/dev/null');
                exec('killall chrome chromedriver 2>/dev/null');
            } elseif ($osFamily === 'Windows') {
                exec('taskkill /F /IM chrome.exe /T 2>NUL');
                exec('taskkill /F /IM chromedriver.exe /T 2>NUL');
            } else {
                // Sistema operacional não reconhecido, tenta comandos genéricos
                exec('pkill -f "(chrome)|(chromedriver)" 2>/dev/null');
                exec('killall chrome chromedriver 2>/dev/null');
            }

            // Aguarda um pouco para garantir que os processos foram encerrados
            usleep(500000); // 0.5 segundos

            echo "Processos antigos do Chrome foram limpos\n";
        } catch (\Throwable $e) {
            // Ignora erros na limpeza de processos
            echo "Erro ao limpar processos do Chrome: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Limpa diretórios temporários de dados do Chrome
     */
    private function cleanupTempDirectories(): void
    {
        try {
            $lockFile = sys_get_temp_dir() . '/chrome_cleanup.lock';

            // Tenta obter lock exclusivo
            $fp = fopen($lockFile, 'w+');
            if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
                // Se não conseguir o lock, outro worker já está limpando
                if ($fp) {
                    fclose($fp);
                }
                return;
            }
            // Força o fechamento do cliente antes de limpar
            if ($this->client !== null) {
                $this->client->quit();
                $this->client = null;
            }

            // Encontra diretórios temporários de dados do Chrome e os remove
            $tempDir = sys_get_temp_dir();
            $pattern = $tempDir . '/chrome_user_data_*';

            // Verifica se o diretório temporário existe e é acessível
            if (!is_dir($tempDir) || !is_readable($tempDir)) {
                return;
            }

            // Verifica se a função glob está disponível
            if (!function_exists('glob')) {
                return;
            }

            // Primeiro remove os arquivos de bloqueio
            $lockFiles = glob($pattern . '.lock');

            foreach ($lockFiles as $lockFile) {
                if (file_exists($lockFile)) {
                    $pid = @file_get_contents($lockFile);
                    // Verifica se o processo ainda está em execução
                    if (!$this->isProcessRunning($pid)) {
                        echo "Removendo arquivo de bloqueio de processo inativo: {$lockFile}\n";
                        @unlink($lockFile);
                    } else {
                        echo "Arquivo de bloqueio pertence a um processo ativo (PID: {$pid}): {$lockFile}\n";
                    }
                }
            }

            // Depois remove os diretórios
            $directories = glob($pattern);
            $currentPid = getmypid();

            foreach ($directories as $dir) {
                if (is_dir($dir) && strpos($dir, 'chrome_user_data_') !== false) {
                    try {
                        // Extrai o PID do nome do diretório
                        preg_match('/chrome_user_data_([0-9]+)/', $dir, $matches);
                        $dirPid = isset($matches[1]) ? $matches[1] : 'desconhecido';

                        // Verifica se o diretório pertence ao processo atual ou a um processo inativo
                        $isCurrentProcess = ($dirPid == $currentPid);
                        $isActiveProcess = $this->isProcessRunning($dirPid);

                        // Determina se deve remover o diretório
                        $shouldRemove = !$isActiveProcess || $isCurrentProcess;

                        if ($shouldRemove) {

                            // Remove arquivos dentro do diretório
                            $files = new \RecursiveIteratorIterator(
                                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                                \RecursiveIteratorIterator::CHILD_FIRST
                            );

                            foreach ($files as $file) {
                                $path = $file->getRealPath();
                                if ($path && file_exists($path)) {
                                    if ($file->isDir()) {
                                        @rmdir($path);
                                    } else {
                                        @unlink($path);
                                    }
                                }
                            }

                            // Remove o diretório
                            if (is_dir($dir)) {
                                @rmdir($dir);
                                echo "Diretório removido com sucesso: {$dir}\n";
                            }
                        } else {
                            echo "Mantendo diretório em uso por outro processo ativo: {$dir}\n";
                        }
                    } catch (\Exception $e) {
                        // Ignora erros ao remover arquivos individuais
                        echo "Erro ao remover diretório {$dir}: " . $e->getMessage() . "\n";
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignora erros na limpeza de diretórios temporários
            echo "Erro ao limpar diretórios temporários: " . $e->getMessage() . "\n";
            // Não queremos que falhas na limpeza afetem o funcionamento principal
        }
    }

    /**
     * Verifica se um processo com o PID especificado ainda está em execução.
     *
     * @param int|string $pid ID do processo a verificar
     * @return bool True se o processo estiver em execução, False caso contrário
     */
    private function isProcessRunning($pid): bool
    {
        // Validação básica do PID
        if (empty($pid)) {
            echo "PID vazio, considerando processo como inativo\n";
            return false;
        }

        $pid = (int) $pid;
        if ($pid <= 0) {
            echo "PID inválido ({$pid}), considerando processo como inativo\n";
            return false;
        }

        // Detectar o sistema operacional usando PHP_OS_FAMILY para maior confiabilidade
        $osFamily = PHP_OS_FAMILY;

        // No macOS (Darwin)
        if ($osFamily === 'Darwin') {
            // Verifica se é um processo do Chrome/Chromium
            exec("ps -p {$pid} -o command=", $output, $result);
            if ($result === 0 && !empty($output)) {
                $command = strtolower(implode(' ', $output));
                return str_contains($command, 'chrome') || str_contains($command, 'chromium');
            }
            return false;
        }
        // No Linux
        else if ($osFamily === 'Linux') {
            // Tenta primeiro com ps (mais comum)
            if (exec('which ps')) {
                exec("ps -p {$pid} -o comm=", $output, $result);
                if ($result === 0 && !empty($output)) {
                    $command = strtolower(implode(' ', $output));
                    return str_contains($command, 'chrome') || str_contains($command, 'chromium');
                }
            }
            // Alternativa usando /proc (específico do Linux)
            else if (file_exists("/proc/{$pid}")) {
                // Lê o nome do comando do arquivo cmdline
                $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
                if ($cmdline !== false) {
                    $command = strtolower($cmdline);
                    return str_contains($command, 'chrome') || str_contains($command, 'chromium');
                }
            }
            return false;
        }
        // No Windows
        else if ($osFamily === 'Windows') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
            $isRunning = (count($output) > 1 && strpos($output[1], (string)$pid) !== false);
            return $isRunning;
        }
        // Sistema operacional desconhecido
        else {
            // Sistema operacional não reconhecido, considerando processo inativo
            return false;
        }
    }

    /**
     * Verifica se um diretório está sendo usado por outro processo
     */
    private function isDirectoryInUse(string $dir): bool
    {
        $lockFile = $dir . '.lock';
        if (!file_exists($lockFile)) {
            return false;
        }

        $pid = (int) file_get_contents($lockFile);
        if ($pid === getmypid()) {
            return false; // Este é o nosso processo
        }

        // Verifica se o processo ainda está em execução
        return $this->isProcessRunning($pid);
    }
}
