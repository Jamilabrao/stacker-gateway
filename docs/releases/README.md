# Changelog das releases (Gateway)

Versão atual: **2.x** (a partir de `2.0.0`). Releases anteriores à 2.0.0 foram removidas — use **Admin → Gateway → Limpar releases < v2.0.0** se ainda aparecerem no portal.

Cada versão publicada deve ter um arquivo markdown aqui antes do bump em `VERSION`.

## Checklist de release

1. Crie `docs/releases/X.Y.Z.md` com as novidades (veja formato abaixo).
2. Atualize `VERSION` na raiz do repo.
3. Push na `main` → GitHub Actions publica zip + changelog na API Stacker.
4. Clientes veem **Novidades na vX.Y.Z** no portal antes de atualizar.

## Formato sugerido

```markdown
## Título curto da release (vira título no admin/portal)

### Correções
- [fix] Descrição da correção
- [fix] Outra correção

### Melhorias
- [improvement] Descrição
- [feature] Nova funcionalidade
```

Categorias suportadas no portal: `feature`, `fix`, `improvement`, `security`.

Bullets sem tag (`- Item`) aparecem como **Melhoria**.

Se o arquivo não existir, a release é publicada sem changelog (fallback genérico no portal).
