import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';
import { collectMetrics, readInstalledVersion } from './metrics.js';
import { applyUpdate, StackerClient } from './stacker-client.js';

const AGENT_VERSION = '1.0.0';
const HEARTBEAT_MS = Number(process.env.STACKER_HEARTBEAT_INTERVAL_MS || 30_000);
const METRICS_MS = Number(process.env.STACKER_METRICS_INTERVAL_MS || 10_000);

function env(name: string, fallback = ''): string {
  return (process.env[name] || fallback).trim();
}

function resolveGatewayRoot(): string {
  return env('STACKER_GATEWAY_ROOT', '/gateway');
}

function resolveAppUrl(): string {
  return env('APP_URL', env('GETFY_APP_URL', 'http://localhost'));
}

async function resolvePublicIp(): Promise<string | undefined> {
  try {
    const res = await fetch('https://api.ipify.org?format=json', { signal: AbortSignal.timeout(5000) });
    const data = (await res.json()) as { ip?: string };
    return data.ip;
  } catch {
    return undefined;
  }
}

async function main() {
  const apiUrl = env('STACKER_API_URL', 'https://api.stacker.builders').replace(/\/$/, '') + '/api';
  const token = env('STACKER_AGENT_TOKEN');
  const gatewayRoot = resolveGatewayRoot();
  const licensePath = path.join(gatewayRoot, 'storage', 'stacker', 'license.json');
  const signingKey = env('STACKER_RELEASE_SIGNING_KEY');

  if (!token) {
    console.error('STACKER_AGENT_TOKEN não configurado');
    process.exit(1);
  }

  const client = new StackerClient(apiUrl, token, licensePath);
  let publicIp: string | undefined;
  let updateInProgress = false;

  const runHeartbeat = async () => {
    try {
      const ip = publicIp ?? (publicIp = await resolvePublicIp());
      const result = await client.heartbeat({
        appUrl: resolveAppUrl(),
        version: readInstalledVersion(gatewayRoot),
        agentVersion: AGENT_VERSION,
        hostname: os.hostname(),
        ip,
      });
      const prev = client.readLicenseCache();
      client.writeLicenseCache(result.license);
      if (!prev || prev.blocked !== result.license.blocked || prev.valid !== result.license.valid) {
        console.log(
          `Licença atualizada: blocked=${result.license.blocked} valid=${result.license.valid}`,
        );
      }

      for (const cmd of result.commands) {
        if (cmd.type === 'apply_update' && !updateInProgress) {
          updateInProgress = true;
          void applyUpdate(client, cmd, gatewayRoot, signingKey)
            .catch(async (err) => {
              const message = err instanceof Error ? err.message : String(err);
              await client.reportUpdateStatus({
                jobId: cmd.jobId,
                status: 'failed',
                logs: message,
              });
              console.error('Falha no update:', message);
            })
            .finally(() => {
              updateInProgress = false;
            });
        }
      }
    } catch (err) {
      const cached = client.readLicenseCache();
      if (cached) {
        console.warn('Heartbeat falhou — usando cache de licença:', err instanceof Error ? err.message : err);
      } else {
        console.error('Heartbeat falhou:', err);
      }
    }
  };

  const runMetrics = async () => {
    try {
      await client.sendMetrics(await collectMetrics(gatewayRoot));
    } catch (err) {
      console.warn('Envio de métricas falhou:', err instanceof Error ? err.message : err);
    }
  };

  fs.mkdirSync(path.dirname(licensePath), { recursive: true });
  console.log(`Stacker Agent ${AGENT_VERSION} — API ${apiUrl}`);

  await runHeartbeat();
  await runMetrics();

  setInterval(runHeartbeat, HEARTBEAT_MS);
  setInterval(runMetrics, METRICS_MS);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
