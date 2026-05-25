# Cloudflare + Getfy (Docker/Caddy)

## Por que Flexible “abre” e Full dá 522?

| Modo Cloudflare | Cloudflare → sua VPS | O que precisa na VPS |
|-----------------|----------------------|----------------------|
| **Flexible** | HTTP na porta **80** | Caddy `:80` (já configurado) |
| **Full / Full strict** | HTTPS na porta **443** | Caddy com **TLS** no domínio |

Erro **522** em Full = nada responde HTTPS na 443 (ou TLS inválido no strict).

## Recomendado em produção

1. Cloudflare **SSL/TLS → Overview → Full** (não Flexible).
2. No servidor, `Caddyfile.domains` deve ter `tls internal` ou certificado Origin:

```caddy
seu-dominio.com {
    tls internal
    reverse_proxy app:80
}
```

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
