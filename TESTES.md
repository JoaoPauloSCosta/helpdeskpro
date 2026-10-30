# Documentação de testes — Help Desk Pro

Este documento descreve como o Help Desk Pro foi validado: o roteiro de testes manuais executado na aplicação em funcionamento e a suíte de testes automatizados que acompanha o código.

---

## Sumário

- [Ambiente de teste](#ambiente-de-teste)
- [Resumo da validação](#resumo-da-validação)
- [Testes manuais](#testes-manuais)
- [Revisão técnica do código](#revisão-técnica-do-código)
- [Testes automatizados](#testes-automatizados)
- [Como reproduzir](#como-reproduzir)
- [Fora do escopo](#fora-do-escopo)

---

## Ambiente de teste

| Item | Valor |
|---|---|
| Aplicação | Laravel 12, Inertia.js 2, Vue 3 com TypeScript |
| PHP | 8.2 (XAMPP) |
| Banco de dados | MariaDB (testes manuais) e SQLite em memória (testes automatizados) |
| Framework de testes | Pest 3 sobre PHPUnit |
| Dados | Banco populado pelos seeders (`php artisan migrate --seed`): papéis, permissões e usuário de teste |
| Perfis usados | Solicitante, Técnico, Gestor e Administrador |

Os anexos usados nos testes são arquivos fictícios (PDF e imagem pequenos). Nenhum dado real foi usado.

---

## Resumo da validação

| # | Cenário | Tipo | Resultado |
|---|---|---|---|
| 1 | Entrar e sair da conta de demonstração | Manual | Aprovado |
| 2 | Criar um chamado e verificar a persistência | Manual | Aprovado |
| 3 | Adicionar comentário e anexo fictício | Manual | Aprovado |
| 4 | Alterar status e conferir o histórico | Manual | Aprovado |
| 5 | Validar regras de acesso entre perfis | Manual | Aprovado |
| 6 | Conferir indicadores do dashboard | Manual | Aprovado |
| 7 | Inspecionar controllers, policies e migrations | Revisão de código | Aprovado |
| 8 | Revisar testes automatizados e documentação | Revisão de código | Aprovado |
| — | Suíte automatizada completa (`php artisan test`) | Automatizado | 1.486 aprovados, 0 falhas |

---

## Testes manuais

Cada cenário lista os passos executados e o resultado esperado. Os cenários foram executados no navegador, com a aplicação rodando localmente.

### CT-01 — Entrar e sair da conta de demonstração

**Objetivo:** confirmar que a autenticação abre e encerra a sessão corretamente.

| Passo | Ação | Resultado esperado |
|---|---|---|
| 1 | Acessar a aplicação sem estar autenticado | Redirecionamento para a tela de login |
| 2 | Informar e-mail e senha da conta de demonstração | Acesso liberado. Gestor e administrador caem na Visão geral; o solicitante cai na listagem dos próprios chamados |
| 3 | Informar uma senha errada | Login recusado com mensagem de erro, sem revelar se o e-mail existe |
| 4 | Clicar em **Sair** | Sessão encerrada e retorno à tela de login |
| 5 | Voltar no navegador ou abrir `/tickets` após sair | Redirecionamento para o login; nenhuma tela protegida é exibida |

**Resultado:** aprovado.

### CT-02 — Criar um chamado e verificar a persistência

**Objetivo:** confirmar que o chamado aberto é gravado e continua disponível depois de recarregar a página.

| Passo | Ação | Resultado esperado |
|---|---|---|
| 1 | Como solicitante, abrir **Novo chamado** | Formulário com título, descrição, departamento e categoria (sem campo de prioridade para o solicitante) |
| 2 | Enviar o formulário vazio | Erros de validação por campo; nenhum chamado criado |
| 3 | Preencher os campos e enviar | Chamado criado com identificador `HD-XXXXXXXX` e status **Aberto** |
| 4 | Recarregar a página e voltar à listagem | O chamado aparece na listagem com os mesmos dados |
| 5 | Sair e entrar de novo | O chamado continua lá (persistido no banco, não apenas na sessão) |

**Resultado:** aprovado.

### CT-03 — Adicionar comentário e anexo fictício

**Objetivo:** confirmar o registro de comentários e o envio seguro de anexos.

| Passo | Ação | Resultado esperado |
|---|---|---|
| 1 | No detalhe do chamado, escrever um comentário público e anexar um PDF fictício | Comentário exibido na conversa, com o anexo listado |
| 2 | Baixar o anexo | Download como arquivo, com o nome original |
| 3 | Como técnico, adicionar uma **nota interna** com anexo | Nota exibida em destaque (fundo âmbar) apenas para a equipe de suporte |
| 4 | Como solicitante, abrir o mesmo chamado | Vê o comentário público e o anexo público; não vê a nota interna nem o nome do arquivo dela |
| 5 | Tentar anexar um arquivo não permitido (ex.: `.svg`, `.html`, `.exe`) ou acima de 10 MB | Envio recusado com mensagem de erro; nada é gravado |

**Resultado:** aprovado.

### CT-04 — Alterar status e conferir o histórico

**Objetivo:** confirmar que as transições de status seguem o fluxo definido e ficam registradas.

| Passo | Ação | Resultado esperado |
|---|---|---|
| 1 | Como técnico, assumir um chamado **Aberto** | Chamado atribuído ao técnico |
| 2 | Alterar o status para **Em atendimento** | Status atualizado no detalhe e na listagem |
| 3 | Colocar o chamado em uma pendência (ex.: **Aguardando solicitante**) | Status alterado e relógio de resolução do SLA pausado |
| 4 | Resolver o chamado, informando a resolução | Status **Resolvido** |
| 5 | Abrir o histórico de auditoria do chamado | Cada mudança aparece com status anterior, status novo, autor e data/hora |
| 6 | Conferir as opções de status oferecidas | A tela só oferece transições válidas; transições fora do fluxo são recusadas pelo backend |

**Resultado:** aprovado.

### CT-05 — Validar regras de acesso entre perfis

**Objetivo:** confirmar que cada perfil acessa apenas o que suas permissões permitem, inclusive por acesso direto à URL.

| Passo | Perfil | Ação | Resultado esperado |
|---|---|---|---|
| 1 | Solicitante | Abrir a listagem | Vê apenas os próprios chamados, sem as colunas de prioridade, SLA e solicitante |
| 2 | Solicitante | Digitar `/audit`, `/performance` e `/admin/users` na barra de endereço | Acesso negado (403) |
| 3 | Solicitante | Abrir o endereço direto de um anexo de nota interna | Resposta 404 |
| 4 | Solicitante | Trocar o identificador na URL para um chamado de outra pessoa | O chamado não é exibido (acesso negado) |
| 5 | Técnico | Abrir o menu e o detalhe de um chamado que atende | Vê o histórico de auditoria do chamado; não vê Desempenho nem a administração |
| 6 | Gestor | Abrir o menu | Vê Auditoria e Desempenho; pode atribuir e encerrar chamados |
| 7 | Administrador | Abrir a administração | Gerencia usuários, departamentos, categorias, papéis, permissões e políticas de SLA |

**Resultado:** aprovado.

### CT-06 — Conferir indicadores do dashboard

**Objetivo:** confirmar que os indicadores da Visão geral correspondem aos chamados cadastrados e respeitam o escopo do usuário.

| Passo | Ação | Resultado esperado |
|---|---|---|
| 1 | Como gestor, abrir a **Visão geral** | Total de chamados e distribuição por status, prioridade, departamento e responsável |
| 2 | Comparar os totais com a listagem de chamados | Os números batem com os chamados da listagem |
| 3 | Criar ou alterar um chamado e recarregar o dashboard | Os indicadores refletem a mudança |
| 4 | Trocar o período | Os indicadores consideram apenas os chamados abertos no período |
| 5 | Entrar como técnico | Os indicadores consideram apenas os chamados que o técnico enxerga |
| 6 | Entrar como solicitante e abrir `/dashboard` | Redirecionamento para a listagem de chamados (o solicitante não tem Visão geral) |

**Resultado:** aprovado.

---

## Revisão técnica do código

### CT-07 — Inspecionar controllers, policies e migrations

| Área | O que foi conferido |
|---|---|
| Controllers (`app/Http/Controllers`) | Um controller por recurso ou ação de chamado; cada ação chama a autorização (`Gate::authorize` / policies) antes de ler ou alterar dados |
| Form requests (`app/Http/Requests`) | Validação de entrada e autorização no servidor, independentes da interface |
| Policies (`app/Policies`) | Regras de acesso por recurso (`TicketPolicy`, `UserPolicy`, `RolePolicy`, `AuditLogPolicy`, `SlaPolicyPolicy` etc.), baseadas nas permissões do papel |
| Migrations (`database/migrations`) | Esquema de usuários, papéis e permissões, departamentos, categorias, chamados, comentários e versões, histórico, pausas de SLA, auditoria, políticas de SLA e anexos, com chaves estrangeiras e índices |
| Seeders (`database/seeders`) | Papéis e permissões idempotentes; papel de superadministrador criado apenas em ambiente local |

**Resultado:** aprovado. A autorização está no backend e não depende de ocultar botões na tela.

### CT-08 — Revisar testes automatizados e documentação

| Item | O que foi conferido |
|---|---|
| Testes automatizados | Suíte executada do zero, sem falhas (ver abaixo) |
| README | Instalação local (XAMPP) e com Docker, configuração, comandos, perfis, ciclo de vida do chamado e limitações conhecidas |
| Este documento | Roteiro manual e mapa da suíte automatizada |

**Resultado:** aprovado.

---

## Testes automatizados

A suíte roda em SQLite em memória, configurado em `phpunit.xml`, e não depende do banco local.

**Última execução (26/10/2026):** **1.486 testes aprovados, nenhuma falha** (9.596 asserções, cerca de 2 minutos).

A exportação CSV é restrita a administradores e superadministradores. Os testes da exportação cobrem essa regra: administrador e superadministrador exportam; solicitante, técnico e gestor recebem 403 sem nenhum dado; o botão de exportar só aparece para os dois papéis autorizados; e, mesmo para quem pode exportar, o arquivo traz apenas os chamados que a pessoa enxerga na listagem.

### Cobertura por módulo

Contagem de casos declarados (`it(...)`/`test(...)`) por arquivo. Vários casos usam datasets do Pest, então o número de execuções relatado pela suíte é maior.

| Módulo | Arquivos | Casos | O que cobre |
|---|---|---|---|
| Chamados | 18 | 377 | Abertura, listagem, detalhe, edição, atribuição e autoatribuição, status, pendências, resolução, reabertura, encerramento, encerramento automático após 3 dias (`tickets:close-resolved`), comentários e versões, anexos, exportação CSV (restrita a administradores), visão restrita do solicitante e auditoria por chamado |
| SLA | 13 | 201 | Primeira resposta, prazo de resolução, pausa e retomada, mudança de prioridade, chamados em risco, histórico de SLA, registro de violações (`sla:record-breaches`) e políticas de SLA |
| Administração | 15 | 327 | Usuários, departamentos, categorias, papéis, matriz de permissões, catálogo de permissões e migration de concessão de permissão |
| Auditoria e indicadores | 3 | 76 | Auditoria global, Visão geral (dashboard) e painel de desempenho |
| Autenticação e perfil | 7 | 45 | Login, cadastro, verificação de e-mail, recuperação e troca de senha, confirmação de senha e perfil |
| Regras puras (`tests/Unit`) | 2 | 16 | Cálculo do relógio de SLA e transições de status permitidas |
| **Total** | **59** | **1.039** | Inclui os 2 arquivos de exemplo do Laravel (3 casos) |

### Exemplos de casos automatizados

Relacionados aos cenários manuais acima:

- **Persistência e validação (CT-02):** um arquivo inválido na abertura não cria o chamado; o formulário do solicitante não oferece prioridade.
- **Anexos (CT-03):** arquivos não permitidos são recusados e nada é gravado; a extensão e o tipo informados pelo navegador não são confiáveis; o anexo de nota interna nunca é acessível ao solicitante, mesmo com o endereço direto; trocar identificadores na URL não alcança anexos de outro chamado; o disco de anexos é privado.
- **Status e histórico (CT-04):** transições fora do fluxo são recusadas; o histórico registra status anterior, novo, autor e hora do servidor; envios repetidos ou concorrentes da mesma transição são aplicados uma única vez; uma falha ao gravar a auditoria não altera o status.
- **Acesso entre perfis (CT-05):** o solicitante não usa filtros ou ordenações restritos, mesmo com parâmetros forjados; parâmetros manipulados nunca listam chamados de outras pessoas; usuários inativos são recusados; só administradores e superadministradores exportam CSV, e os demais papéis recebem 403 sem nenhum dado.
- **Dashboard (CT-06):** os indicadores são calculados a partir dos chamados gravados; o período não revela chamados fora do escopo; carregar o dashboard não altera chamados nem o histórico.

---

## Como reproduzir

Pré-requisitos e instalação estão no [README](README.md).

```bash
# Testes automatizados
composer test

# Um módulo específico
php artisan test --filter=TicketAttachment

# Testes manuais: banco populado e front-end compilado
php artisan migrate --seed
npm run build
```

---

## Fora do escopo

- Testes de carga e desempenho.
- Testes automatizados de interface no navegador (end-to-end). A interface foi validada manualmente.
- Varredura antivírus de anexos, que a aplicação não implementa (ver *Limitações conhecidas* no README).
