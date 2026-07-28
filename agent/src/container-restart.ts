import * as fs from 'node:fs';
import * as path from 'node:path';
import { spawn } from 'node:child_process';

export type ContainerRestartPayload = {
  status: 'pending' | 'running' | 'completed' | 'failed' | string;
  requested_at?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  message?: string | null;
  reason?: string | null;
  requested_by?: number | null;
  logs?: string;
};

const RELATIVE = path.join('storage', 'app', 'stacker', 'container-restart.json');

function restartFile(gatewayRoot: string): string {
  return path.join(gatewayRoot, RELATIVE);
}

function readPayload(file: string): ContainerRestartPayload | null {
  try {
    if (!fs.existsSync(file)) {
      return null;
    }
    const raw = fs.readFileSync(file, 'utf8');
    const parsed = JSON.parse(raw) as ContainerRestartPayload;
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch {
    return null;
  }
}

function writePayload(file: string, payload: ContainerRestartPayload): void {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(payload, null, 2), 'utf8');
}

function runRestartScript(gatewayRoot: string): Promise<{ code: number; logs: string }> {
  const script = path.join(gatewayRoot, 'docker', 'restart-after-url-change.sh');
  return new Promise((resolve) => {
    if (!fs.existsSync(script)) {
      resolve({ code: 1, logs: `Script ausente: ${script}` });
      return;
    }

    const child = spawn('bash', [script], {
      cwd: gatewayRoot,
      env: {
        ...process.env,
        DOCKER_HOST: process.env.DOCKER_HOST || 'unix:///var/run/docker.sock',
      },
    });

    let logs = '';
    child.stdout.on('data', (chunk: Buffer) => {
      logs += chunk.toString();
    });
    child.stderr.on('data', (chunk: Buffer) => {
      logs += chunk.toString();
    });
    child.on('error', (err) => {
      resolve({ code: 1, logs: logs + String(err) });
    });
    child.on('close', (code) => {
      resolve({ code: code ?? 1, logs });
    });
  });
}

/**
 * Se houver pedido pending, executa o script de reinício (uma vez).
 * Retorna true se processou algo.
 */
export async function processPendingContainerRestart(gatewayRoot: string): Promise<boolean> {
  const file = restartFile(gatewayRoot);
  const payload = readPayload(file);
  if (!payload || payload.status !== 'pending') {
    return false;
  }

  const running: ContainerRestartPayload = {
    ...payload,
    status: 'running',
    started_at: new Date().toISOString(),
    message: 'Reiniciando containers…',
  };
  writePayload(file, running);
  console.log('container-restart: iniciando docker/restart-after-url-change.sh');

  const result = await runRestartScript(gatewayRoot);
  const ok = result.code === 0;
  writePayload(file, {
    ...running,
    status: ok ? 'completed' : 'failed',
    finished_at: new Date().toISOString(),
    message: ok
      ? 'Containers reiniciados. O painel pode ficar indisponível por alguns segundos.'
      : 'Falha ao reiniciar containers. Veja os logs do agente.',
    logs: (result.logs || '').slice(-8000),
  });
  console.log(`container-restart: ${ok ? 'ok' : 'falhou'} (code=${result.code})`);
  return true;
}
