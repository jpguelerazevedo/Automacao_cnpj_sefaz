<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scrapers\Clients\PantherClient;

class DownloadCertidaoCommand extends Command
{
    protected $signature = 'certidao:download {cnpj : O CNPJ para gerar a certidão} {--skip-validation : Pular validação do CNPJ} {--debug : Modo debug com mais informações}';
    protected $description = 'Baixa a certidão da Receita Federal para o CNPJ informado';
    protected PantherClient $client;
    protected $downloadDirectory;

    public function handle()
    {
        $cnpj = $this->argument('cnpj');
        $skipValidation = $this->option('skip-validation');
        $debug = $this->option('debug');
        
        if (!$skipValidation && !$this->isValidCnpj($cnpj)) {
            $this->error('CNPJ inválido. Use um CNPJ válido ou adicione --skip-validation para pular a validação.');
            $this->info('Exemplo de CNPJ válido: 11.222.333/0001-81');
            return 1;
        }

        $this->info("Iniciando download da certidão para CNPJ: {$cnpj}");

        try {
            
            $this->client = new PantherClient();
            $this->downloadDirectory = storage_path('app\private\certidao');
            $this->client->setDownloadDirectory($this->downloadDirectory);
            $this->client->setPageLoadStrategy('normal');
            $this->client->setWindowSize(1280, 900);

            $this->info("Diretório configurado: {$this->client->getDownloadDirectory()}");
            $this->info("PantherClient configurado com sucesso.");

            
            $this->info('Acessando página da SEFAZ...');
            $this->client->request('https://www.sefaz.go.gov.br/certidao/emissao/');
            $this->info('Página carregada.');

            $this->info('Aguardando carregamento completo...');
            $this->client->waitFor('body', 15);

            $this->client->waitFor('form', 10);
            $this->info('Formulário carregado.');

            $this->info('Selecionando tipo de documento CNPJ...');
            $radioCnpj = $this->client->getCrawler()->filter('input[name="Certidao.TipoDocumento"][value="2"]')->first();
            if ($radioCnpj->count()) {
                $radioCnpj->click();
                $this->info('Radio CNPJ selecionado.');
                $radioResult = 'success';
            } else {
                $this->error('Radio CNPJ não encontrado');
                $radioResult = 'not_found';
            }
            sleep(2);
            if ($radioResult !== 'success') {
                $this->error('Radio CNPJ não encontrado');
                return 1;
            }

            $this->info("Aguardando campo CNPJ...");
            $this->client->waitFor('input[name="Certidao.NumeroDocumentoCNPJ"]', 10);

            $this->info("Preenchendo CNPJ...");
            $cleanCnpj = $this->cleanCnpj($cnpj);
            $form = $this->client->getCrawler()->filter('form')->first();
            if ($form->count()) {
                $form = $form->form([
                    'Certidao.NumeroDocumentoCNPJ' => $cleanCnpj
                ]);
                $this->info('CNPJ preenchido no formulário.');
                $fillResult = 'filled_' . $cleanCnpj;
            } else {
                $this->error('Formulário não encontrado');
                $fillResult = 'field_not_found';
            }
            
            if (strpos($fillResult, 'filled_') === 0) {
                $this->info('CNPJ preenchido: ' . substr($fillResult, 7));
            } else {
                $this->error('Campo CNPJ não encontrado');
                return 1;
            }
            sleep(2);

            $this->info('Aguardando botão Emitir...');

            $this->client->waitFor('input[type="submit"][value="Emitir"]', 10);

            $this->info('Clicando no botão Emitir...');
            $button = $this->client->getCrawler()->filter('input[type="submit"][value="Emitir"]')->first();
            if ($button->count()) {
                $button->click();

                $this->info('Botão clicado.');
                $clickResult = 'clicked';
                
            } else {
                $this->error('Botão Emitir não encontrado');
                return 1;
            }

            $filesBefore = $this->client->getDownloadDirectory();
            $downloadedFile = $this->waitForDownloadUsingPantherMethods($filesBefore, 30);
            if ($downloadedFile) {
                $novoNome = $this->downloadDirectory . '/certidao_' . $this->cleanCnpj($cnpj) . '.pdf';
                rename($downloadedFile, $novoNome);
                $this->info("Arquivo renomeado para: {$novoNome}");
            }   

            
        } catch (\Exception $e) {
            $this->error("Erro: " . $e->getMessage());
            return 1;
        }
    }

    private function isValidCnpj(string $cnpj): bool
    {
        $cnpj = $this->cleanCnpj($cnpj);
        
        if (strlen($cnpj) !== 14 || !ctype_digit($cnpj)) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;
        if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) {
            return false;
        }

        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;
        return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
    }

    private function cleanCnpj(string $cnpj): string
    {
        return preg_replace('/[^0-9]/', '', $cnpj);
    }

    private function waitForDownloadUsingPantherMethods($filesBefore, int $timeout = 30): ?string
    {
    // Se $filesBefore for string (diretório), converte para array de arquivos
        if (is_string($filesBefore)) {
            $filesBefore = scandir($filesBefore);
        }

        $downloadDir = $this->downloadDirectory;
        $start = time();

        while ((time() - $start) < $timeout) {
            $filesNow = scandir($downloadDir);

            // Procura por novos arquivos
            $newFiles = array_diff($filesNow, $filesBefore);

            // Ignora . e ..
            $newFiles = array_filter($newFiles, function ($file) {
                return $file !== '.' && $file !== '..';
            });

            // Se encontrar novo arquivo, retorna o caminho completo
            if (!empty($newFiles)) {
                // Se houver mais de um, pega o mais recente
                $newest = null;
                $newestTime = 0;
                foreach ($newFiles as $file) {
                    $filePath = $downloadDir . DIRECTORY_SEPARATOR . $file;
                    $mtime = filemtime($filePath);
                    if ($mtime > $newestTime) {
                        $newest = $filePath;
                        $newestTime = $mtime;
                    }
                }
                // Aguarda o arquivo terminar de ser escrito (caso esteja incompleto)
                sleep(1);
                return $newest;
            }

            // Aguarda 1 segundo antes de checar novamente
            sleep(1);
        }

        // Timeout: nenhum arquivo novo encontrado
        return null;
    }
} 