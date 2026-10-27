# Portfólio e entrevistas

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

Este roteiro usa só fatos comprovados na análise. Os trechos entre `[colchetes]` são informações que apenas o autor pode dar; preencha antes de usar. Não afirme em entrevista nada que você não consiga explicar lendo o código.

## 1. Apresentação em dois minutos (modelo)

> O Help Desk Pro é um sistema de gestão de chamados de suporte, em Laravel 12 com Inertia 2 e Vue 3 em TypeScript. O ciclo completo do chamado está implementado: abertura, atribuição, pendências, resolução, reabertura e encerramento, com controle de SLA, auditoria e anexos. O acesso é por papéis e permissões editáveis em tela, e o servidor decide o que cada perfil enxerga, inclusive nos dados enviados ao navegador. Cada mudança de estado é uma transação com bloqueio da linha e histórico imutável. O projeto tem 1.486 testes automatizados passando e o build de produção com verificação de tipos. [Seu papel e o contexto: projeto pessoal, estudo, para qual objetivo.]

Números que você pode citar (verificados em 27/10/2026): 18 migrations, 21 tabelas, 20 permissões, 4 papéis, 71 rotas, 1.486 testes e 9.596 asserções, build sem erros.

## 2. Resumo para o portfólio

| Campo | Conteúdo |
|---|---|
| Nome | Help Desk Pro |
| Tipo | Aplicação web de gestão de chamados (monólito) |
| Stack | PHP 8.2+, Laravel 12, Inertia 2, Vue 3 + TypeScript, Tailwind 3, MariaDB, Pest, Docker |
| Destaques técnicos | Máquina de estados única; Actions transacionais com `lockForUpdate`; SLA com pausas e violação persistida; RBAC por dados; visão restrita no servidor; anexos validados por conteúdo; histórico imutável |
| Evidência de qualidade | 1.486 testes passando e `vue-tsc` no build (27/10/2026) |
| Limites declarados | Sem CI, sem testes de frontend, testes só em SQLite, sem notificações de chamado, sem antivírus |
| Repositório e demo | [link do repositório] · [link da demo, se houver] |
| Capturas de tela | [a preencher] |

## 3. Decisões que você deve saber defender

| Tema | O que dizer | Onde mostrar |
|---|---|---|
| Padrão Action | Cada mudança de estado é uma classe com transação, `lockForUpdate`, checagem do estado esperado, reautorização e evento de histórico. Evita regra de negócio em controller e dá um ponto único para testar. | `app/Actions/Tickets/ResolveTicket.php` |
| Máquina de estados em um só lugar | As transições estão num único método do enum; policies, ações e interface consultam essa fonte. | `TicketStatus::transitions()` |
| Autorização em camadas | Policy no controller e no Form Request, reautorização na Action sobre a linha bloqueada, escopo no SQL e props filtradas. | `TicketPolicy`, `Ticket::scopeVisibleTo` |
| Visão restrita no servidor | O solicitante nem recebe prioridade, SLA ou solicitante; filtros proibidos retornam erro. A interface só reflete. | `TicketController::index`, `TicketIndexRequest` |
| SLA com pausas | O prazo de resolução embute as pausas: ao retomar, soma-se a duração. O consumo é a meta menos o tempo restante, sem dupla contagem. Metas são copiadas para o chamado. | `SlaClock`, `ChangeTicketStatus` |
| Violação histórica separada do estado atual | `*_breached_at` nunca é apagado; o estado exibido é derivado do prazo vigente. | `Ticket::slaState`, `SlaClock::recordBreaches` |
| Unicidade no banco | Colunas geradas garantem uma política ativa por prioridade e uma pausa aberta por chamado. | Migrations de `sla_policies` e `ticket_sla_pauses` |
| Concorrência otimista | `editVersion()` (hash dos campos) detecta edição concorrente sem depender de `updated_at`. | `User::editVersion`, `UpdateUser` |
| Anexos | Validação por conteúdo, disco privado, nome UUID, download autorizado a cada requisição. | `AttachmentInspector`, `TicketAttachmentController` |
| CSV seguro | Neutraliza fórmulas e lê os chamados em blocos. | `TicketCsvExport` |

## 4. Desafios técnicos documentados

Candidatos a "maior problema técnico". Escolha um que você realmente enfrentou e consiga explicar:

- **SLA com pausas e troca de prioridade:** recalcular o prazo sem perder o tempo já consumido nem contar duas vezes o tempo parado (`SlaClock`, [02-requisitos-funcionais.md](02-requisitos-funcionais.md), seção 3.5).
- **Visibilidade de anexos herdada da mensagem:** um anexo de nota interna não pode ser baixado por quem não vê a nota (404).
- **Concorrência:** duas pessoas assumindo o mesmo chamado; resolvido por transação e `lockForUpdate`, com a ressalva de que a prova real é em MariaDB.
- **Teste de exportação instável corrigido em 24/10/2026**, registrado em `docs/pendencias.md`.

[Qual deles foi o seu, e o que você aprendeu.]

## 5. Perguntas prováveis

**Por que Inertia e não uma API com SPA?**
Evita duplicar autorização e contratos entre duas aplicações, mantém roteamento e validação no Laravel e entrega a experiência de SPA. O custo é que não há API para terceiros; por isso o `laravel/sanctum` ficou sem uso e pode ser removido.

**Como você garante que dois técnicos não assumem o mesmo chamado?**
`SelfAssignTicket` abre uma transação, relê o chamado com `lockForUpdate` e confere se ainda está Aberto e sem responsável; quem perde recebe uma mensagem neutra. Observação honesta: a prova real do bloqueio é em MariaDB; a suíte roda em SQLite, onde o lock não tem efeito.

**Como funciona a autorização?**
Papéis e permissões são dados. As policies checam permissões, não nomes de papel. `scopeVisibleTo` filtra no SQL. Há uma exceção que eu reconheço: a exportação CSV usa o slug do papel (decisão posterior ao PRD) e deveria virar uma permissão.

**Quem pode fazer o quê no sistema?**
Mostre [casos-de-uso.md](casos-de-uso.md): 30 casos de uso em 4 módulos, 9 atores (inclusive o Servidor de e-mail e o Agendador) e a matriz padrão, cada um ligado ao arquivo que o implementa. Diga com clareza que a matriz é a padrão do seeder e que o Administrador pode alterá-la em execução. Pontos em que o diagrama e o código diferem: o Solicitante não tem visão geral; a auditoria global exige também ver todos os departamentos; "equipe de atendimento" é um ator no diagrama e uma permissão no código.

**Como você evita que um administrador crie um superusuário?**
`Role::isGrantableBy`: só se concede um papel cujas permissões administrativas o operador já tem. Além disso, ninguém altera papel ou situação da própria conta, e ninguém retira `roles.manage` do próprio papel.

**Como calcula o SLA quando o chamado fica pendente ou é reaberto?**
Em pendência, o relógio para; na retomada o prazo é estendido pela duração; na reabertura é estendido pelo tempo que ficou Resolvido; na troca de prioridade o consumo é preservado e a nova meta é aplicada sobre ele. Ver [02-requisitos-funcionais.md](02-requisitos-funcionais.md).

**Como evita cliques duplos ao criar registros?**
Token UUID gerado ao abrir o formulário e `Cache::add` atômico: só o primeiro envio cria o registro; reenvios recebem o mesmo resultado.

**Como trata arquivos enviados?**
Lista de extensões, MIME detectado pelo conteúdo, verificações por tipo (PDF sem JavaScript, Office sem macros, texto sem marcação), nome UUID, disco privado e CSP `sandbox` no download. Não há antivírus nem exclusão de anexos, e eu declaro isso.

**Como você testou?**
Pest com seeder real e usuários por papel; as Actions montam o cenário e a rota HTTP é exercitada. Verifiquei contratos do Inertia (`assertInertia`). 1.486 casos passam em cerca de 2 minutos. Não há testes de frontend nem CI.

**Está pronto para produção?**
Não afirme isso. A análise não cobriu implantação, dependências vulneráveis, HTTPS nem MariaDB. Aponte os débitos prioritários em [10-debitos-tecnicos.md](10-debitos-tecnicos.md).

## 6. Pontos fracos para assumir com clareza

Responda antes que perguntem. Fontes em [10-debitos-tecnicos.md](10-debitos-tecnicos.md).

1. **Edição de comentário** existe só como Action e permissão; falta a rota e a tela.
2. **Exportação CSV por papel**, fora do padrão de permissões.
3. **Verificação de e-mail** presente, mas desabilitada: o `User` não implementa `MustVerifyEmail`.
4. **Login de usuário inativo** não é barrado na autenticação e não há teste.
5. **Exclusão de conta própria** com `delete` real, em conflito com as chaves `restrictOnDelete`.
6. **Testes só em SQLite**: os locks e o SQL específico de MariaDB não têm prova automatizada.
7. **Sem CI, sem frontend testado, sem notificações de chamado, sem antivírus.**
8. **`DatabaseSeeder`** cria um usuário de teste com senha conhecida e não é idempotente.
9. **README com trechos desatualizados** (permissões do Administrador, seeders "idempotentes").

Para cada um, tenha a resposta de "o que eu faria": a coluna "Sugestão" do capítulo 10.

## 7. Como falar do processo de desenvolvimento

O repositório tem 34 commits de um único autor entre 21 e 27/10/2026. **32 deles terminam com a linha `Co-Authored-By: Claude ...`** (por exemplo, `c717ad5`), e há arquivos de um fluxo guiado por prompts e relatórios de etapas (`docs/prompt-qa-cowork.md`, `docs/pendencias.md`). O uso de IA está registrado no histórico e é fácil de verificar por quem ler o repositório. Em entrevista, diga com precisão o que você fez: [o que você definiu (requisitos, regras, decisões)], [o que você revisou e validou], [onde usou assistência de IA]. A postura mais defensável é demonstrar que você entende o código por inteiro e sabe apontar suas limitações, como na seção 6.

## 8. Demonstração sugerida (5 a 8 minutos)

1. Entrar como Solicitante: abrir um chamado com anexo; mostrar que a listagem não tem prioridade nem SLA.
2. Entrar como Técnico: assumir o chamado, registrar pendência e retomar; abrir o histórico de SLA.
3. Resolver; entrar como Solicitante e reabrir.
4. Entrar como Gestor: atribuir, encerrar e abrir o painel de desempenho.
5. Entrar como Administrador: abrir a matriz de permissões e a auditoria.
6. Mostrar `ResolveTicket.php` e `TicketPolicy.php` lado a lado.
7. Rodar `php artisan test` (cerca de 2 minutos) ou mostrar o resultado já capturado.

Antes de demonstrar, prepare dados e contas de teste em ambiente local ([a preencher]), com papéis diferentes, e remova a conta `test@example.com` se o ambiente for público.

## 9. Checklist do que ainda falta de você

- [ ] Objetivo do projeto e seu papel.
- [ ] Decisão sobre T1 a T8 (corrigir antes ou assumir como pendência).
- [ ] Licença do repositório.
- [ ] O que será publicado de `docs/` e o que fica privado; nunca publicar `helpdesk.sql`.
- [ ] Há deploy ou demo hospedada? [sim/não]
- [ ] Prints ou vídeo da interface.
- [ ] Remover `storage/framework/lsp-*.php` do Git.
- [ ] Rodar `composer audit` e `npm audit` e registrar o resultado.
- [ ] Corrigir o `README.md` (T27, T28).
