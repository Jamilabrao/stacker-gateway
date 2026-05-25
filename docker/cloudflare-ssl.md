# Cloudflare + Getfy (Docker/Caddy)

## Por que Flexible “abre” e Full dá 522?

| Modo Cloudflare | Cloudflare → sua VPS | O que precisa na VPS |
|-----------------|----------------------|----------------------|
| **Flexible** | HTTP na porta **80** | Caddy `:80` (já configurado) |
| **Full / Full strict** | HTTPS na porta **443** | Caddy com **TLS** no domínio |

Erro **522** em Full = nada responde HTTPS na 443 (ou TLS inválido no strict).

## Funcionar Flexible **e** Full (strict) ao mesmo tempo

| Modo Cloudflare | Porta na VPS | Config Caddy |
|-----------------|--------------|--------------|
| **Flexible** | **80** HTTP | bloco `:80 { reverse_proxy app:80 }` |
| **Full** | **443** HTTPS | domínio + `tls internal` |
| **Full (strict)** | **443** HTTPS | domínio + cert **Origin** Cloudflare |

Os dois modos podem coexistir: Flexible usa só a 80; strict usa a 443 com certificado Origin.

1. Rode `sh docker/fix-caddy-domain.sh` na raiz do projeto.
2. Para **strict**, instale cert Origin em `.docker/certs/origin.pem` e `origin-key.pem` e rode o script de novo.
3. Cloudflare: **Full (strict)** após o cert na origem.

## Recomendado em produção

1. Corrija o container `app` se estiver em `Restarting` (`sh docker/diagnose-stack.sh`).
2. Cloudflare **SSL/TLS → Full (strict)** quando houver cert Origin; senão **Full** com `tls internal`.
3. Recriar Caddy: `docker compose -f docker-compose.caddy.yml --env-file .docker/stack.env up -d --force-recreate caddy`

## Full (strict) — certificado Origin Cloudflare

1. Cloudflare → SSL/TLS → **Origin Server** → Create Certificate.
2. Salve em `/opt/getfy/.docker/certs/` (volume `getfy_env`):
   - `origin.pem`
   - `origin-key.pem`
3. Bloco Caddy:

```caddy
seu-dominio.com {
    tls /etc/getfy/certs/origin.pem /etc/getfy/certs/origin-key.pem
    reverse_proxy app:80
}
```

4. Cloudflare → **Full (strict)**.

## PHP

A imagem Docker oficial do Getfy usa **PHP 8.2** (`Dockerfile`). Requer `^8.2` no `composer.json`. Laragon local pode ser 8.3; produção Docker não muda sozinha para 8.3.
