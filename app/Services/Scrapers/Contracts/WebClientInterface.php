<?php

namespace App\Services\Scrapers\Contracts;

use Symfony\Component\DomCrawler\Crawler;

interface WebClientInterface
{
    /**
     * Faz uma requisição HTTP para uma URL específica.
     *
     * @param string $url URL para fazer a requisição.
     * @param string $method Método HTTP (GET por padrão).
     * @param array $data Dados para requisições POST.
     * @return Crawler Retorna o DOM da página como um Crawler.
     */
    public function request(string $url, string $method = 'GET', array $data = []): Crawler;

    /**
     * Obtém o Crawler atual.
     *
     * @return Crawler
     */
    public function getCrawler(): Crawler;

    /**
     * Executa um script JavaScript na página atual.
     *
     * @param string $script Código JavaScript a ser executado.
     * @return mixed Resultado do script executado.
     */
    public function executeScript(string $script): mixed;

    /**
     * Aguarda até que um elemento específico esteja visível na página.
     *
     * @param string $selector Seletor CSS do elemento a ser aguardado.
     * @param int $timeout Tempo máximo de espera (em segundos).
     * @return void
     */
    public function waitFor(string $selector, int $timeout = 30): void;

    /**
     * Obtém a URL atual carregada no navegador.
     *
     * @return string URL atual.
     */
    public function getCurrentURL(): string;
}