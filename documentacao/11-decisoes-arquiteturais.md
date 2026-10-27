# Decisões arquiteturais

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

Registro no formato ADR resumido. **Nenhuma decisão foi confirmada com o autor.** A coluna "Base" diz de onde vem o motivo: **Registrado** (documento ou comentário do código o declara), **Inferido** (deduzido do código) ou **A confirmar** (a motivação depende do responsável). Trade-offs e consequências são avaliação desta análise.

## Decisões

| # | Decisão | Base | Motivo | Consequência e trade-off |
|---|---|---|---|---|
| D1 | Monólito Laravel + Inertia + Vue, sem API separada. | Registrado: PRD 3.2 (monólito) | Evita duplicar autorização e contratos entre duas aplicações. | Ganho: um só lugar para regras e rotas. Custo: sem consumidores externos; `laravel/sanctum` ficou declarado e sem uso. |
| D2 | Padrão **Action** para regras transacionais; controllers finos. | Inferido do código | Concentra transação, lock e reautorização num ponto testável. | Consistência alta; muita repetição de estrutura entre as Actions (T9 a T11). |
| D3 | RBAC por permissões em tabelas, não por papel no código. | Registrado: PRD 7 (matriz editável pela administração) | A administração ajusta o acesso sem alterar o código. | Flexibilidade; o que vale numa instalação depende do banco. A exportação CSV é a exceção (D9). |
| D4 | Histórico e auditoria somente-inserção; chaves `restrictOnDelete`; inativar em vez de excluir. | Registrado: RN-18 e RN-19 | Rastreabilidade. | Nada some pelo fluxo normal; a exclusão de conta própria entra em conflito (T3). |
| D5 | Concorrência otimista por `editVersion` (hash dos campos) em vez de `updated_at`. | Registrado: comentário do código (`updated_at` tem precisão de segundos e muda a cada comentário) | Detecta edição concorrente de forma confiável. | Formulários carregam e devolvem uma versão; conflito vira mensagem ao usuário. |
| D6 | Metas de SLA **copiadas** para o chamado. | Registrado: migration `add_sla_columns` (a política pode ser editada depois) | Editar uma política não reescreve o passado. | Chamados antigos mantêm metas antigas; mais colunas em `tickets`. |
| D7 | Prazo de resolução **embute** as pausas (estendido na retomada). | Registrado: RNF-10 (sem dupla contagem) | Evita contar tempo parado. | O consumo sai de `meta − (prazo − instante do relógio)`; o prazo exibido muda conforme as pausas. |
| D8 | Violação de SLA persistida (`*_breached_at`) e estado atual derivado. | Registrado: RN-15 (violação histórica não se apaga) | Auditar o histórico sem depender do estado atual. | Um chamado pode estar "No prazo" e ter violação registrada. |
| D9 | Exportação CSV restrita a `admin` e `superadmin` por slug de papel. | Registrado: commit `c717ad5` (26/10/2026: botão só para esses papéis, demais recebem 403), README e diagrama de casos de uso. **A confirmar** a motivação | Restringir o volume de dados exportado. | Foge do padrão (D3) e do PRD RF-21 ("filtros e permissões"); Gestor e papéis personalizados ficam de fora. |
| D10 | Prioridade sempre Média na abertura. | Registrado: V-D4 em `docs/pendencias.md` (24/10/2026) | A prioridade é definida pela equipe, não pelo solicitante. | Segundo `docs/pendencias.md`, no ambiente do autor só a prioridade Média tinha política de SLA ativa (**não verificado no banco**). |
| D11 | Visão restrita decidida no servidor (props ausentes, filtros `prohibited`). | Registrado: RF-24 | A interface não pode vazar o que o solicitante não deve ver. | Tipos opcionais no frontend. |
| D12 | SQLite em memória nos testes. | Inferido (velocidade: 1.486 casos em cerca de 2 min) | Suíte rápida sem serviços externos. | Lacunas de paridade com MariaDB (T22). |
| D13 | Anexos em disco privado, validados por conteúdo e entregues por rota autorizada. | Registrado: RF-23 | Impedir execução ou acesso direto a arquivos enviados. | Sem antivírus e sem exclusão de anexos. |
| D14 | Cálculo de SLA em minutos corridos. | Registrado: README ("Limitações conhecidas") | Simplicidade. | Sem calendário comercial nem feriados. |
| D15 | Sessão, cache e fila no banco (nos exemplos de ambiente). | Inferido de `.env.example` e `.env.docker.example` | Menos dependências de infraestrutura. | Um serviço só; limites de desempenho não avaliados. |
| D16 | Identificador público aleatório (`HD-` + 8 caracteres) no lugar do `id`. | Registrado: RF-06 (comentário em `TicketNumberGenerator`) | Não expor sequência nem permitir enumeração. | Unicidade garantida por índice e nova tentativa em colisão (até 5). |

## Alternativas não adotadas (observadas no código)

| Tema | O que o código mostra |
|---|---|
| Filas e notificações de chamado | Não há jobs; só o e-mail de convite e os de senha. O serviço `queue` do Docker fica sem trabalho. |
| Calendário comercial no SLA | Fora do escopo declarado. |
| Verificação de e-mail obrigatória | Rotas existem, `MustVerifyEmail` não foi implementada; o PRD diz "quando habilitada". |
| Autorização da exportação por permissão | Foi substituída por slug de papel no commit `c717ad5`. |

## Decisões que dependem do responsável

Perguntas em aberto (também em [10-debitos-tecnicos.md](10-debitos-tecnicos.md) e na seção 10 de [casos-de-uso.md](casos-de-uso.md)):

1. A exportação deve virar permissão ou continuar por papel?
2. O Administrador deve contar como "equipe de atendimento"?
3. A edição de comentário será implementada ou a permissão será removida?
4. A verificação de e-mail será ativada?
5. Excluir a própria conta deve existir como requisito?
6. O diagrama de casos de uso deve ser corrigido nas divergências D1 a D3 e D5 a D9?
