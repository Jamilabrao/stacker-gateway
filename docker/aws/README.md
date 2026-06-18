# AWS EC2 — preparação de deploy (Getfy Gateway)

Este guia descreve como migrar da VPS única para AWS mantendo a **mesma arquitetura modular** (monólito Laravel + workers Redis).

## Componentes recomendados

| Componente | Serviço AWS |
|------------|-------------|
| Load balancer | Application Load Balancer (ALB) |
| App HTTP | EC2 Auto Scaling Group (AMI com PHP 8.3 + Nginx) |
| Workers | EC2 ASG separado ou ECS Fargate com mesma imagem Docker |
| Banco | RDS PostgreSQL Multi-AZ |
| Fila/Cache | ElastiCache Redis |
| Arquivos | S3 + `league/flysystem-aws-s3-v3` |
| Secrets | SSM Parameter Store / Secrets Manager |
| Logs | CloudWatch Logs |
| Alarmes | CloudWatch — queue depth, 5xx, CPU |

## Variáveis de ambiente (produção API)

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=<elasticache-endpoint>
API_INBOUND_WEBHOOKS_ASYNC=true
WEBHOOKS_OUTBOUND_QUEUE=webhooks-outbound
WEBHOOKS_INBOUND_QUEUE=webhooks-inbound
PAYMENTS_QUEUE=payments
PAYOUTS_QUEUE=payouts
GETFY_REDIS_MAXMEMORY=1gb
API_RATE_STANDARD_PER_MINUTE=600
API_RATE_LEGACY_PER_MINUTE=120
```

## Workers (mesmas filas do docker-compose)

1. `payments,default`
2. `webhooks-outbound,webhooks`
3. `webhooks-inbound`
4. `payouts`
5. `schedule:work` (scheduler dedicado)

## Quando extrair microserviço

Somente se métricas mostrarem saturação independente:

1. Webhook delivery (stateless)
2. Payment worker (gateways)
3. **Nunca** extrair ledger/carteira sem transações ACID compartilhadas

## Health checks

- ALB → `GET /up`
- Worker liveness → `queue_heartbeat` cache key (< 3 min)
- RDS / ElastiCache via CloudWatch

## Referência local

Ver [`docker-compose.yml`](../docker-compose.yml) — serviços `worker-payments`, `worker-webhooks-in`, `worker-webhooks-out`, `worker-payouts`, `scheduler`.
