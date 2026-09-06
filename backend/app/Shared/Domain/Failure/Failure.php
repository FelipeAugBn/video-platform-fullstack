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
 * Aos casos genericos do contrato somam-se os do envio de video, da publicacao
 * e do callback de processamento. Dois deles — `VIDEO_OBJECT_MISSING` e
 * `VIDEO_OBJECT_MISMATCH` — sao usados nos dois papeis do catalogo ao mesmo
 * tempo: viram a resposta da conclusao recusada e, com a mesma mensagem, o
 * `failure_code` e o `failure_message` gravados na tentativa. E o retorno
 * pratico de um catalogo unico — o produtor le na consulta do video exatamente
 * o mesmo texto que recebeu na recusa.
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

    /*
     * Envio de video.
     *
     * `VIDEO_ATTEMPT_ACTIVE` recusa um segundo envio enquanto a tentativa atual
     * nao terminou em falha. Cobre os quatro estados de RF-UPL-011 e tambem
     * `ready`, por um motivo diferente: substituir um video ja pronto esta fora
     * do escopo desta entrega (RF-UPL-012).
     *
     * Qual estado impede nao entra no codigo — cinco casos quase iguais no
     * catalogo diriam a mesma coisa cinco vezes. Ele viaja como membro de
     * extensao da resposta, ao lado do problema (RFC 9457), e a interface o le
     * sem precisar interpretar o texto (AC-VID-011).
     */
    case VIDEO_ATTEMPT_ACTIVE = 'VIDEO_ATTEMPT_ACTIVE';
    case VIDEO_UPLOAD_NOT_ACTIVE = 'VIDEO_UPLOAD_NOT_ACTIVE';
    case VIDEO_OBJECT_MISSING = 'VIDEO_OBJECT_MISSING';
    case VIDEO_OBJECT_MISMATCH = 'VIDEO_OBJECT_MISMATCH';

    /*
     * Processamento e callback.
     *
     * `VIDEO_PROCESSING_FAILED` e o unico caso deste bloco que nunca vira corpo
     * de erro: ele nasce de um callback de falha e fica gravado na tentativa,
     * saindo depois no `200` da consulta do video (plan §13.4). O status
     * declarado adiante existe para manter a traducao exaustiva, e nao porque
     * exista uma resposta com ele.
     */
    case VIDEO_PROCESSING_FAILED = 'VIDEO_PROCESSING_FAILED';
    case WEBHOOK_SIGNATURE_INVALID = 'WEBHOOK_SIGNATURE_INVALID';
    case WEBHOOK_EVENT_REJECTED = 'WEBHOOK_EVENT_REJECTED';

    /*
     * Publicacao.
     *
     * Tres casos, e nao um `409` generico: RF-UI-010 pede que a interface
     * mostre a condicao nao satisfeita, e "esta aula nao tem video" e "o video
     * ainda esta processando" levam o produtor a acoes diferentes.
     */
    case LESSON_WITHOUT_VIDEO = 'LESSON_WITHOUT_VIDEO';
    case LESSON_VIDEO_NOT_READY = 'LESSON_VIDEO_NOT_READY';
    case LESSON_PLAYBACK_REFERENCE_MISSING = 'LESSON_PLAYBACK_REFERENCE_MISSING';
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
            self::VIDEO_ATTEMPT_ACTIVE => 'Aula com tentativa de video ativa',
            self::VIDEO_UPLOAD_NOT_ACTIVE => 'Envio de video encerrado',
            self::VIDEO_OBJECT_MISSING => 'Arquivo nao encontrado',
            self::VIDEO_OBJECT_MISMATCH => 'Arquivo diferente do declarado',
            self::VIDEO_PROCESSING_FAILED => 'Falha no processamento do video',
            self::WEBHOOK_SIGNATURE_INVALID => 'Origem nao reconhecida',
            self::WEBHOOK_EVENT_REJECTED => 'Evento incompativel',
            self::LESSON_WITHOUT_VIDEO => 'Aula sem video',
            self::LESSON_VIDEO_NOT_READY => 'Video ainda nao esta pronto',
            self::LESSON_PLAYBACK_REFERENCE_MISSING => 'Video sem referencia de reproducao',
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
            self::VIDEO_ATTEMPT_ACTIVE => 'Esta aula ja tem uma tentativa de video em andamento ou concluida. '
                .'Um novo envio so e aceito depois que a tentativa atual terminar em falha.',
            self::VIDEO_UPLOAD_NOT_ACTIVE => 'Este envio ja foi encerrado e nao aceita mais operacoes.',
            self::VIDEO_OBJECT_MISSING => 'O arquivo enviado nao foi encontrado no armazenamento. '
                .'Envie o video novamente.',
            self::VIDEO_OBJECT_MISMATCH => 'O arquivo armazenado nao corresponde ao que foi declarado '
                .'no inicio do envio. Envie o video novamente.',
            self::VIDEO_PROCESSING_FAILED => 'Nao foi possivel processar o video. Envie o arquivo novamente.',
            self::WEBHOOK_SIGNATURE_INVALID => 'A origem da requisicao nao pode ser confirmada.',
            self::WEBHOOK_EVENT_REJECTED => 'O evento nao e compativel com o estado atual e nao sera aplicado. '
                .'Nao reenvie.',
            self::LESSON_WITHOUT_VIDEO => 'Esta aula ainda nao tem video. Envie um video antes de publicar.',
            self::LESSON_VIDEO_NOT_READY => 'O video desta aula ainda nao esta pronto para reproducao.',
            self::LESSON_PLAYBACK_REFERENCE_MISSING => 'O video desta aula nao tem referencia de reproducao '
                .'registrada.',
            self::SERVICE_UNAVAILABLE => 'Tente novamente em alguns instantes.',
            self::INTERNAL_ERROR => 'Nao foi possivel concluir a operacao.',
        };
    }
}
