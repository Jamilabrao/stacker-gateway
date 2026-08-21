<?php

namespace App\Services\Versell;

use App\Gateways\Versell\VersellCredentials;
use Illuminate\Support\Facades\Log;

/**
 * Pix Automático Versell (padrão Bacen) — mesma API/token/certs do Cash In.
 * Fluxo Jornada 3: locrec → cob → rec → GET rec?txid (QR composto).
 */
class VersellPixRecorrenteService
{
    public function __construct(
        private readonly array $credentials,
        private readonly VersellHttpClient $client = new VersellHttpClient()
    ) {}

    /**
     * POST /locrec
     *
     * @return array{id: int, location?: string, criacao?: string}
     */
    public function createLocRec(): array
    {
        $response = $this->request('POST', '/locrec', null);
        $data = $this->jsonOrFail($response, 'criar location de recorrência');
        if (empty($data['id'])) {
            Log::warning('VersellPixRecorrenteService createLocRec invalid', ['keys' => array_keys($data)]);
            throw new \RuntimeException('Versell: não foi possível criar o location de recorrência.');
        }

        return $data;
    }

    /**
     * PUT /cob/{txid} — cobrança imediata da Jornada 3.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{txid: string, copy_paste?: string|null, qrcode?: string|null, loc?: array}
     */
    public function createCobWithTxid(
        string $txid,
        float $amount,
        array $consumer,
        string $pixKey,
        string $solicitacaoPagador = ''
    ): array {
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: '';
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $devedor = ['nome' => mb_substr((string) ($consumer['name'] ?? ''), 0, 200)];
        if (strlen($document) === 14) {
            $devedor['cnpj'] = $document;
        } else {
            $devedor['cpf'] = substr($document, 0, 11);
        }

        $body = [
            'calendario' => ['expiracao' => 3600],
            'devedor' => $devedor,
            'valor' => [
                'original' => number_format(round($amount, 2), 2, '.', ''),
            ],
            'chave' => $pixKey,
            'solicitacaoPagador' => $solicitacaoPagador !== '' ? $solicitacaoPagador : 'Pedido PIX automático',
            'infoAdicionais' => [
                ['nome' => 'order_ref', 'valor' => mb_substr($txid, 0, 50)],
            ],
        ];

        $response = $this->request('PUT', '/cob/'.$txid, $body);
        $data = $this->jsonOrFail($response, 'criar cobrança imediata');
        $returnedTxid = trim((string) ($data['txid'] ?? $txid));
        if ($returnedTxid === '') {
            throw new \RuntimeException('Versell: cobrança imediata sem txid.');
        }

        $copyPaste = $data['pixCopiaECola'] ?? $data['pix_copia_e_cola'] ?? null;
        $copyPaste = is_string($copyPaste) && $copyPaste !== '' ? $copyPaste : null;

        return [
            'txid' => $returnedTxid,
            'copy_paste' => $copyPaste,
            'qrcode' => null,
            'loc' => is_array($data['loc'] ?? null) ? $data['loc'] : [],
        ];
    }

    /**
     * POST /rec — Jornada 3 (vincula loc + txid da cob).
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{idRec: string, status?: string}
     */
    public function createRecurrence(
        int $locId,
        string $txidCob,
        array $consumer,
        float $valorRec,
        string $dataInicial,
        string $dataFinal,
        string $contrato = '',
        string $objeto = 'Assinatura'
    ): array {
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: '';
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $devedor = ['nome' => mb_substr((string) ($consumer['name'] ?? ''), 0, 200)];
        if (strlen($document) === 14) {
            $devedor['cnpj'] = $document;
        } else {
            $devedor['cpf'] = substr($document, 0, 11);
        }

        $contratoVal = $contrato !== '' ? $contrato : str_pad((string) time(), 8, '0', STR_PAD_LEFT);
        $body = [
            'loc' => $locId,
            'vinculo' => [
                'contrato' => preg_replace('/\D/', '', $contratoVal) ?: $contratoVal,
                'devedor' => $devedor,
                'objeto' => mb_substr($objeto, 0, 140),
            ],
            'calendario' => [
                'dataInicial' => $dataInicial,
                'dataFinal' => $dataFinal,
                'periodicidade' => 'MENSAL',
            ],
            'valor' => [
                'valorRec' => number_format(round($valorRec, 2), 2, '.', ''),
            ],
            'politicaRetentativa' => 'NAO_PERMITE',
            'ativacao' => [
                'dadosJornada' => [
                    'txid' => $txidCob,
                ],
            ],
        ];

        $response = $this->request('POST', '/rec', $body);
        $data = $this->jsonOrFail($response, 'criar recorrência');
        if (empty($data['idRec'])) {
            Log::warning('VersellPixRecorrenteService createRecurrence invalid', ['keys' => array_keys($data)]);
            throw new \RuntimeException('Versell: não foi possível criar a recorrência PIX automático.');
        }

        return $data;
    }

    /**
     * GET /rec/{idRec} (?txid= para QR composto).
     *
     * @return array<string, mixed>
     */
    public function getRecurrence(string $idRec, ?string $txid = null): array
    {
        $path = '/rec/'.rawurlencode($idRec);
        if ($txid !== null && $txid !== '') {
            $path .= '?txid='.rawurlencode($txid);
        }

        $response = $this->request('GET', $path, null);

        return $this->jsonOrFail($response, 'consultar recorrência');
    }

    /**
     * PUT /cobr/{txid} ou POST /cobr.
     *
     * @param  array{name?: string, document?: string, email?: string}  $devedor
     * @return array<string, mixed>
     */
    public function createCobrancaRecorrente(
        string $idRec,
        float $valor,
        string $dataDeVencimento,
        ?string $txid = null,
        array $devedor = [],
        string $infoAdicional = ''
    ): array {
        $body = [
            'idRec' => $idRec,
            'valor' => ['original' => number_format(round($valor, 2), 2, '.', '')],
            'calendario' => ['dataDeVencimento' => $dataDeVencimento],
            'ajusteDiaUtil' => true,
            'devedor' => array_filter([
                'nome' => $devedor['name'] ?? $devedor['nome'] ?? null,
                'email' => $devedor['email'] ?? null,
                'logradouro' => $devedor['logradouro'] ?? null,
                'cidade' => $devedor['cidade'] ?? null,
                'uf' => $devedor['uf'] ?? null,
                'cep' => $devedor['cep'] ?? null,
            ]),
        ];
        if ($infoAdicional !== '') {
            $body['infoAdicional'] = $infoAdicional;
        }

        if ($txid !== null && $txid !== '') {
            $response = $this->request('PUT', '/cobr/'.$txid, $body);
        } else {
            $response = $this->request('POST', '/cobr', $body);
        }

        $data = $this->jsonOrFail($response, 'criar cobrança recorrente');
        if (empty($data['idRec']) && empty($data['txid'])) {
            Log::warning('VersellPixRecorrenteService createCobrancaRecorrente invalid', ['keys' => array_keys($data)]);
            throw new \RuntimeException('Versell: não foi possível criar a cobrança recorrente.');
        }

        return $data;
    }

    /**
     * @return \Illuminate\Http\Client\Response
     */
    private function request(string $method, string $path, ?array $json)
    {
        if (! VersellCredentials::isCashInReady($this->credentials)) {
            throw new \RuntimeException('Versell: credenciais Cash In incompletas para Pix Automático.');
        }

        try {
            return $this->client->request(
                VersellCredentials::API_CASH_IN,
                $this->credentials,
                $method,
                $path,
                $json
            );
        } catch (\Throwable $e) {
            Log::warning('VersellPixRecorrenteService request failed', [
                'gateway' => 'versell',
                'endpoint' => $path,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            throw new \RuntimeException('Versell Pix Automático: falha de comunicação.', 0, $e);
        }
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     * @return array<string, mixed>
     */
    private function jsonOrFail($response, string $action): array
    {
        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellPixRecorrenteService rejected', [
                'gateway' => 'versell',
                'action' => $action,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);
            throw new \RuntimeException('Versell: '.$problem['message']);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
