# Documentação do Help Desk Pro

> **Atualizada em 27/10/2026.** Base de código analisada: branch `main`, último commit `bdb7667`. Idioma: português do Brasil.

Documentação técnica e funcional do Help Desk Pro, um sistema de gestão de chamados de suporte (Laravel 12, Inertia 2, Vue 3 e TypeScript). Foi produzida por leitura do código, execução da suíte de testes e do build, e conferência com a referência funcional de casos de uso. Não houve teste em ambiente de produção nem acesso ao banco implantado.

## Convenção de confiança

Cada afirmação relevante indica de onde vem. Nos textos em que a diferença importa, aparecem estas marcas:

| Marca | Significado |
|---|---|
| **Comprovado** | Lido no código, no fonte do framework ou obtido por execução nesta análise. |
| **Inferido** | Consequência lógica do código, não executada. |
| **Declarado** | Afirmado em documento do projeto (README, `TESTES.md`, `docs/`) e não reproduzido aqui. |
| **Pendente** | Não foi possível confirmar, ou depende de decisão ou de informação do responsável. |

## Guia de leitura

| Se você quer... | Leia |
|---|---|
| Entender o que o sistema faz e para quem | [01-visao-geral.md](01-visao-geral.md) |
| Saber as regras de negócio e quem pode o quê | [02-requisitos-funcionais.md](02-requisitos-funcionais.md) |
| Ver como o código está organizado | [03-arquitetura.md](03-arquitetura.md), [04-estrutura-do-projeto.md](04-estrutura-do-projeto.md) |
| Consultar rotas e permissões | [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md) |
| Entender o banco de dados | [06-banco-de-dados.md](06-banco-de-dados.md) |
| Avaliar segurança e qualidade | [07-seguranca.md](07-seguranca.md), [08-testes-e-qualidade.md](08-testes-e-qualidade.md) |
| Instalar e rodar | [09-instalacao-e-execucao.md](09-instalacao-e-execucao.md) |
| Conhecer limitações e decisões | [10-debitos-tecnicos.md](10-debitos-tecnicos.md), [11-decisoes-arquiteturais.md](11-decisoes-arquiteturais.md) |
| Apresentar o projeto em entrevista | [12-portfolio-e-entrevistas.md](12-portfolio-e-entrevistas.md) |

**Trilhas sugeridas.** Desenvolvedor novo: 01, 03, 04, 09, 05. Revisor de segurança: 07, 05, 10. Entrevista: 12, depois 11 e 10.

## Documento complementar

[casos-de-uso.md](casos-de-uso.md) liga cada um dos 30 casos de uso da referência funcional (`docs/Casos-de-Uso-Help-Desk-Pro.html` e `docs/diagrama-casos-de-uso.puml`) aos arquivos do código, traz a matriz padrão de permissões e registra 9 divergências entre referência e implementação. Os documentos 02 e 05 apontam para ele.

## Documentos anteriores

A primeira versão desta documentação, organizada em outros 12 arquivos, está preservada em [anteriores/](anteriores/). O conteúdo verificado foi reaproveitado nos documentos 01 a 12. Os arquivos antigos ficam como histórico e não são mais atualizados.

## Fontes e seus limites

- **Código-fonte** do repositório (`app/`, `routes/`, `database/`, `config/`, `resources/js`, `tests/`, `docker/`).
- **Execução em 27/10/2026:** `php artisan test --compact` (1.486 aprovados, 0 falhas, 9.596 asserções, 122,78 s), `npm run build` (sem erros, 11,52 s) e `php artisan route:list` (71 rotas).
- **Documentos do projeto:** `README.md`, `TESTES.md` e, de `docs/`, consultados por trechos: `prd-rn.md` (v1.8), `pendencias.md`, `relatorio-qa-helpdesk-pro.md`, `Casos-de-Uso-Help-Desk-Pro.html` e `diagrama-casos-de-uso.puml`. `configuracao-email.md` e `prompt-qa-cowork.md` são citados pelo nome, sem leitura. Os demais arquivos de `docs/` não foram comparados com o código (lista abaixo). A pasta `docs/` está no `.gitignore` e **não foi alterada**.
- **Não consultados:** `.env`, `.env.docker`, `.claude/settings.local.json` e `helpdesk.sql`. Nenhum segredo ou dado real aparece nesta documentação.
- **Não executado:** migrations, seeders, instalação de dependências, Docker, `composer audit`, `npm audit`. A suíte rodou apenas em SQLite em memória; nada foi testado em MariaDB.
- **Esquema × banco implantado:** o capítulo 06 descreve o esquema definido nas migrations. O banco de uma instalação específica pode diferir (por exemplo, permissões editadas na matriz) e não foi examinado.

## Arquivos de `docs/` não consultados (pendentes)

Nenhum destes foi lido ou comparado com o código. Até que sejam, esta documentação **não** afirma que o projeto os cumpre. Registrado como T30 em [10-debitos-tecnicos.md](10-debitos-tecnicos.md).

| Arquivo em `docs/` | O que a comparação confirmaria | Capítulo afetado |
|---|---|---|
| `TRD-Help-Desk-Pro.html` | Decisões e arquitetura técnicas previstas | 03, 11 |
| `Mapeamento-de-Rotas-Inertia-Help-Desk-Pro.html` | Rotas, props e telas previstas | 03, 05 |
| `Mapeamento-de-Dados-Help-Desk-Pro.html` | Tabelas, campos e relacionamentos previstos | 06 |
| `diagramas-sequencia.md` | Fluxos de ponta a ponta previstos | 03 |
| `anexos.md` | Regras de anexos previstas | 02 (seção 3.7), 07 |
| `design-system.md` e `Help_Desk_Pro_UI_UX_Design_Specification.md` | Componentes e convenções de interface previstos | 04 |
| `roteiro-teste-manual-v18.md` | Cenários de validação manual | 08 |
| `PRD-Help Desk Pro.docx` e `Regra de Negócio Help Desk Pro.docx` | Formato `.docx` não lido; a relação com o `prd-rn.md` não foi verificada | 02 |

## Fonte canônica de cada assunto

Para evitar divergência entre documentos, cada assunto tem um lugar de referência; os demais só apontam para ele.

| Assunto | Documento canônico |
|---|---|
| Matriz de permissões e policies | [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md) |
| Regras de negócio | [02-requisitos-funcionais.md](02-requisitos-funcionais.md) |
| Casos de uso e divergências com a referência | [casos-de-uso.md](casos-de-uso.md) |
| Débitos e riscos a tratar (T1 a T30) | [10-debitos-tecnicos.md](10-debitos-tecnicos.md) |
| Esquema do banco | [06-banco-de-dados.md](06-banco-de-dados.md) |

## Histórico

- 27/10/2026: primeira versão (commit `6aa5be2`).
- 27/10/2026: revisão técnica independente e correções (M1, M2, B1 a B8, I1 a I4): ambiente do `db:seed` no Docker, ER completo, contagens de testes e commits, fontes não consultadas, ordem dos débitos e fonte canônica.

## Como manter

Ao mudar uma regra, atualize o capítulo correspondente e a data deste arquivo. Números que dependem de execução (testes, rotas) devem ser refeitos, não ajustados à mão.
