<?php

namespace App\Support;

/**
 * Dados do comprador enviados aos gateways no checkout (PIX, boleto, cartão).
 */
final class CheckoutPaymentConsumer
{
    /**
     * @param  array<string, mixed>  $validated  Dados validados do checkout (name, email, cpf, phone)
     * @return array{name: string, document: string, email: string, phone: string}
     */
    public static function build(array $validated, int $orderId): array
    {
        $fake = FakeConsumerData::getForGateway($orderId);
        $rawDoc = BrazilianDocuments::digits($validated['cpf'] ?? '');
        $document = $fake['document'];

        if (strlen($rawDoc) === 11 && BrazilianDocuments::isValidCpf($rawDoc)) {
            $document = $rawDoc;
        } elseif (strlen($rawDoc) === 14 && BrazilianDocuments::isValidCnpj($rawDoc)) {
            $document = $rawDoc;
        } elseif ($rawDoc !== '' && strlen($rawDoc) >= 11) {
            // CPF/CNPJ com tamanho ok mas dígitos inválidos — API pode recusar; usa documento estável do pedido
            $document = $fake['document'];
        }

        $name = trim((string) ($validated['name'] ?? ''));

        return [
            'name' => $name !== '' ? $name : $fake['name'],
            'document' => $document,
            'email' => (string) ($validated['email'] ?? ''),
            'phone' => trim((string) ($validated['phone'] ?? '')),
        ];
    }
}
