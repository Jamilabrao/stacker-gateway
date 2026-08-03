import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';
import { collectMetrics, readInstalledVersion, readRuntimeVersion } from './metrics.js';
import { applyUpdate, reapplyUpdate, StackerClient } from './stacker-client.js';
import { processPendingContainerRestart } from './container-restart.js';

const AGENT_VERSION = '1.0.0';
/** Default 60s — evita flicker online/offline; limiar no hub deve ser bem maior. */
const HEARTBEAT_MS = Number(process.env.STACKER_HEARTBEAT_INTERVAL_MS || 60_000);
const METRICS_MS = Number(process.env.STACKER_METRICS_INTERVAL_MS || 30_000);
const CONTAINER_RESTART_MS = Number(process.env.STACKER_CONTAINER_RESTART_POLL_MS || 5_000);

function env(name: string, fallback = ''): string {
  return (process.env[name] || fallback).trim();
}

function resolveGatewayRoot(): string {
  return env('STACKER_GATEWAY_ROOT', '/gateway');
}

function resolveAppUrl(): string {
  const gatewayRoot = resolveGatewayRoot();
  try {
    const appUrlFile = path.join(gatewayRoot, '.docker', 'app.url');
    if (fs.existsSync(appUrlFile)) {
      const fromFile = fs.readFileSync(appUrlFile, 'utf8').trim();
      if (fromFile) {
        return fromFile.replace(/\/$/, '');
      }
    }
  } catch {
    // ignore
  }
  try {
    const envPath = path.join(gatewayRoot, '.env');
    if (fs.existsSync(envPath)) {
      const env = fs.readFileSync(envPath, 'utf8');
      const match = env.match(/^\s*APP_URL\s*=\s*(.+)\s*$/m);
      if (match?.[1]) {
        const v = match[1].trim().replace(/^["']|["']$/g, '');
        if (v) {
          return v.replace(/\/$/, '');
        }
      }
    }
  } catch {
    // ignore
  }
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
  let containerRestartInProgress = false;

  const runContainerRestartWatch = async () => {
    if (containerRestartInProgress || updateInProgress) {
      return;
    }
    containerRestartInProgress = true;
    try {
      await processPendingContainerRestart(gatewayRoot);
    } catch (err) {
      console.warn('container-restart watch falhou:', err instanceof Error ? err.message : err);
    } finally {
      containerRestartInProgress = false;
    }
  };

  const runHeartbeat = async () => {
    try {
      const ip = publicIp ?? (publicIp = await resolvePublicIp());
      const heartbeatPayload: {
        appUrl: string;
        version?: string;
        runtimeVersion?: string;
        agentVersion?: string;
        hostname?: string;
        ip?: string;
      } = {
        appUrl: resolveAppUrl(),
        agentVersion: AGENT_VERSION,
        hostname: os.hostname(),
        ip,
      };
      // Durante apply o VERSION no host já muda; não reportar host prematuro.
      if (!updateInProgress) {
        heartbeatPayload.version = readInstalledVersion(gatewayRoot);
        heartbeatPayload.runtimeVersion = readRuntimeVersion(gatewayRoot);
      } else {
        heartbeatPayload.runtimeVersion = readRuntimeVersion(gatewayRoot);
      }
      const result = await client.heartbeat(heartbeatPayload);
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
        } else if (cmd.type === 'reapply' && !updateInProgress) {
          updateInProgress = true;
          void reapplyUpdate(client, cmd, gatewayRoot)
            .catch(async (err) => {
              const message = err instanceof Error ? err.message : String(err);
              await client.reportUpdateStatus({
                jobId: cmd.jobId,
                status: 'failed',
                logs: message,
              });
              console.error('Falha no reapply:', message);
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
  await runContainerRestartWatch();

  setInterval(runHeartbeat, HEARTBEAT_MS);
  setInterval(runMetrics, METRICS_MS);
  setInterval(runContainerRestartWatch, CONTAINER_RESTART_MS);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
