<?php

declare(strict_types=1);

namespace App\Shared\Domain\Failure;

/**
 * Catalogo fechado de falhas publicas.
 *
 * Cada caso reune tres coisas que precisam andar juntas: o codigo funcional
 * estavel que o frontend usa para decidir o que mostrar, um titulo curto e uma
 * mensagem segura para exibir a quem fez a requisicao.
 *
 * Ser uma enumeracao e a escolha central. Nao existe construtor, entao nao ha
 * caminho que aceite texto livre vindo de um controller, de um request ou da
 * resposta de um servico externo — e e assim que RN-AUT-005 e RF-WHK-011 passam
 * a valer por construcao, e nao por disciplina de quem escreve o proximo
 * endpoint. Um codigo que nao esteja aqui simplesmente nao pode ser produzido:
 * `Failure::from()` lanca em vez de inventar um caso.
 *
 * O que este arquivo deliberadamente nao sabe: status HTTP. Ele vive no dominio,
 * e o dominio nao conhece o protocolo pelo qual a falha sera entregue. Essa
 * traducao acontece na camada de interface (plan §5.4).
 *
 * Nesta etapa existem apenas os casos genericos que o contrato exige. Falhas
 * especificas de curso, aula, envio e callback entram junto das tarefas que
 * implementam esses comportamentos.
 */
enum Failure: string
{
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case CSRF_TOKEN_MISMATCH = 'CSRF_TOKEN_MISMATCH';
    case CONFLICT = 'CONFLICT';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * Codigo funcional. E o mesmo valor do caso, exposto por um nome que diz o
     * papel dele no contrato: identificador estavel, nao mensagem.
     */
    public function code(): string
    {
        return $this->value;
    }

    /**
     * Titulo curto do problema, no formato do RFC 9457.
     */
    public function title(): string
    {
        return match ($this) {
            self::VALIDATION_FAILED => 'Dados invalidos',
            self::UNAUTHENTICATED => 'Nao autenticado',
            self::FORBIDDEN => 'Acesso negado',
            self::NOT_FOUND => 'Recurso nao encontrado',
            self::METHOD_NOT_ALLOWED => 'Metodo nao permitido',
            self::CSRF_TOKEN_MISMATCH => 'Token de seguranca invalido',
            self::CONFLICT => 'Conflito com o estado atual',
            self::SERVICE_UNAVAILABLE => 'Servico temporariamente indisponivel',
            self::INTERNAL_ERROR => 'Erro interno',
        };
    }

    /**
     * Mensagem publica.
     *
     * Escrita para ser lida por quem usa a aplicacao, e nao por quem a mantem:
     * diz o que aconteceu e, quando cabe, o que fazer. Nunca cita tabela,
     * consulta, arquivo, classe ou qualquer detalhe de execucao.
     */
    public function detail(): string
    {
        return match ($this) {
            self::VALIDATION_FAILED => 'Revise os campos informados.',
            self::UNAUTHENTICATED => 'Entre na aplicacao para continuar.',
            self::FORBIDDEN => 'Esta conta nao tem permissao para esta operacao.',
            self::NOT_FOUND => 'O recurso solicitado nao existe ou nao esta disponivel.',
            self::METHOD_NOT_ALLOWED => 'Este endereco existe, mas nao aceita este metodo. '
                .'O cabecalho Allow da resposta lista os metodos aceitos.',
            self::CSRF_TOKEN_MISMATCH => 'O token de seguranca da requisicao esta ausente ou expirou. '
                .'Obtenha um novo token e repita a operacao.',
            self::CONFLICT => 'A operacao nao e compativel com o estado atual do recurso.',
            self::SERVICE_UNAVAILABLE => 'Tente novamente em alguns instantes.',
            self::INTERNAL_ERROR => 'Nao foi possivel concluir a operacao.',
        };
    }
}
