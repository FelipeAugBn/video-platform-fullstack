# Plataforma de Conteudo em Video

Desafio tecnico Full Stack: uma plataforma onde produtores criam cursos, organizam
modulos e aulas, enviam videos e disponibilizam o conteudo para usuarios
autorizados.

Backend em Laravel 13, frontend em Nuxt 4, integracao real entre as duas camadas.

## Stack

| Camada | Tecnologias |
| --- | --- |
| Backend | PHP 8.4, Laravel 13, API REST/JSON, DDD e Arquitetura Hexagonal |
| Frontend | Nuxt 4, Vue 3, TypeScript, Composition API |
| Dados | MySQL 8 com migrations e seeds de avaliacao |
| Ambiente | Docker Compose (decisao do projeto, ver abaixo) |

## Estado atual

O projeto esta em desenvolvimento. Este README cresce junto com a implementacao.

- [x] Configuracao inicial do repositorio
- [x] Especificacoes versionadas
- [ ] Ambiente containerizado
- [ ] Backend: dominio, API e processamento de video
- [ ] Frontend: jornadas do produtor e do consumidor
- [ ] Testes das duas camadas e jornada ponta a ponta
- [ ] Pipeline automatizada
- [ ] Documentacao da API e roteiro de demonstracao

## Estrutura pretendida

    backend/                aplicacao Laravel
    frontend/               aplicacao Nuxt
    specs/
        001-video-platform/
            spec.md         jornadas, regras de negocio e criterios de aceitacao
            plan.md         arquitetura, contratos, decisoes e trade-offs
            tasks.md        decomposicao da implementacao
    docker-compose.yml      orquestracao dos servicos de desenvolvimento

## Ambiente reproduzivel

O desafio pede um ambiente local reproduzivel ou um ambiente publico funcional.
Docker Compose e a decisao deste projeto para atender a primeira opcao: nao e
requisito minimo exigido pela empresa, e sim uma escolha tecnica, que o proprio
desafio lista entre os diferenciais.

A Fase 0 planeja a execucao containerizada de:

- backend Laravel
- frontend Nuxt
- MySQL 8
- storage local
- worker da fila
- simulador de processamento e callbacks

A implementacao concreta do storage local e da fila sera avaliada e justificada no
`plan.md`. A orientacao e evitar servicos adicionais sem necessidade demonstrada.

## Como executar

As instrucoes de instalacao e execucao entram aqui quando o ambiente estiver
disponivel. A intencao e que a avaliacao consiga subir a solucao completa com um
comando, com credenciais de demonstracao criadas por seed.

## Desenvolvimento orientado por especificacoes

O comportamento da solucao e descrito em `specs/001-video-platform/` antes da
implementacao, e as especificacoes evoluem junto com o codigo. Decisoes tecnicas
relevantes e seus trade-offs ficam registrados no `plan.md`.
