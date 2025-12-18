# Automação CNPJ Sefaz

Projeto de automação para exportar múltiplas certidões de CNPJ no site da Sefaz de forma automatizada.

## Descrição

Este projeto realiza a automação do processo de obtenção de certidões negativas de CNPJ diretamente pelo site da Sefaz, facilitando a exportação em massa dos arquivos. É ideal para empresas, contadores e desenvolvedores que precisam baixar diversas certidões rapidamente e garantir a conformidade fiscal.

## Tecnologias utilizadas

- **TypeScript** – Scripts de automação e integração.
- **PHP (Laravel)** – Backend, comandos e integração da automação.

## Instruções de Uso

### 1. Instale as dependências

Certifique-se de ter o PHP, Composer e Node.js instalados em sua máquina. Instale as dependências do projeto:

```bash
composer install
npm install
```

### 2. Execute as migrações e configurações (se necessário)

Execute os comandos padrão do Laravel para configurar o ambiente:

```bash
php artisan migrate
cp .env.example .env
php artisan key:generate
```

Configure as variáveis de ambiente `.env` conforme necessário (bancos de dados, serviços externos, etc).

### 3. Comando para Baixar Certidão

Utilize o comando artisan para iniciar o robô de download da certidão:

```bash
php artisan certidao:download "28.625.074/0001-32"
```

- Altere o CNPJ conforme necessário.
- O PDF da certidão será salvo automaticamente no diretório `storage` do Laravel.

### 4. Automatize para múltiplos CNPJs (opcional)

Para baixar múltiplas certidões, crie um script ou uma lista de CNPJs e chame o comando para cada um deles.

## Estrutura do Projeto

- `app/Console/Commands`: Comandos artisan, incluindo `certidao:download`
- `storage/`: Onde os arquivos PDF são salvos após o download automatizado
- Demais diretórios padrão do Laravel/TypeScript

## Contribuição

Pull requests são bem-vindos! Fique à vontade para sugerir melhorias ou abrir issues sobre problemas encontrados.

## Licença

Este projeto está sob a licença MIT.

---

**Aviso:** Este projeto é destinado ao uso legítimo e dentro das políticas da Sefaz. O uso inadequado pode causar bloqueio do IP ou outras consequências. Utilize de forma ética.
