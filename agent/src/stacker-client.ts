import * as crypto from 'node:crypto';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { execSync, spawn } from 'node:child_process';

export interface LicenseCache {
  valid: boolean;
  blocked: boolean;
  bound?: boolean;
  domain: string | null;
  expiresAt: string;
  supportWhatsapp: string | null;
  signature?: string;
  cachedAt: string;
}

export interface ApplyUpdateCommand {
  type: 'apply_update';
  jobId: string;
  version: string;
  sha256: string;
  signature: string;
  size?: number;
}

export class StackerClient {
  constructor(
    private apiUrl: string,
    private token: string,
    private licensePath: string,
  ) {}

  private headers(): Record<string, string> {
    return {
      'Content-Type': 'application/json',
      'X-Stacker-Agent-Token': this.token,
    };
  }

  private async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const res = await fetch(`${this.apiUrl}${path}`, {
      method,
      headers: this.headers(),
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) {
      const text = await res.text();
      throw new Error(`API ${method} ${path}: ${res.status} ${text}`);
    }
    if (res.status === 204) return undefined as T;
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) return undefined as T;
    return res.json() as Promise<T>;
  }

  writeLicenseCache(license: Omit<LicenseCache, 'cachedAt'>) {
    const dir = path.dirname(this.licensePath);
    fs.mkdirSync(dir, { recursive: true });
    const payload: LicenseCache = { ...license, cachedAt: new Date().toISOString() };
    fs.writeFileSync(this.licensePath, JSON.stringify(payload, null, 2));
  }

  readLicenseCache(): LicenseCache | null {
    try {
      return JSON.parse(fs.readFileSync(this.licensePath, 'utf8')) as LicenseCache;
    } catch {
      return null;
    }
  }

  async heartbeat(payload: {
    appUrl: string;
    version?: string;
    agentVersion?: string;
    hostname?: string;
    ip?: string;
  }) {
    return this.request<{
      license: Omit<LicenseCache, 'cachedAt'>;
      commands: ApplyUpdateCommand[];
    }>('POST', '/gateway/agent/heartbeat', payload);
  }

  async sendMetrics(metrics: Record<string, unknown> | object) {
    return this.request('POST', '/gateway/agent/metrics', metrics);
  }

  verifySignature(sha256: string, signature: string, signingKey: string): boolean {
    const expected = crypto.createHmac('sha256', signingKey).update(sha256).digest('hex');
    try {
      return crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(signature, 'hex'));
    } catch {
      return false;
    }
  }

  async downloadRelease(version: string, destPath: string): Promise<{ sha256: string; signature: string }> {
    const res = await fetch(`${this.apiUrl}/gateway/agent/release/${encodeURIComponent(version)}`, {
      headers: this.headers(),
    });
    if (!res.ok) {
      throw new Error(`Download release ${version}: ${res.status}`);
    }
    const sha256 = res.headers.get('x-artifact-sha256') || '';
    const signature = res.headers.get('x-artifact-signature') || '';
    const buf = Buffer.from(await res.arrayBuffer());
    fs.writeFileSync(destPath, buf);
    const computed = crypto.createHash('sha256').update(buf).digest('hex');
    if (sha256 && computed !== sha256) {
      throw new Error('SHA256 do artefato não confere');
    }
    return { sha256: sha256 || computed, signature };
  }

  async reportUpdateStatus(data: {
    jobId: string;
    status: 'downloading' | 'applying' | 'success' | 'failed';
    logs?: string;
    installedVersion?: string;
  }) {
    return this.request('POST', '/gateway/agent/update-status', data);
  }
}

export async function applyUpdate(
  client: StackerClient,
  cmd: ApplyUpdateCommand,
  gatewayRoot: string,
  signingKey: string,
): Promise<void> {
  const stagingDir = path.join(gatewayRoot, '.stacker-update-staging');
  const zipPath = path.join(stagingDir, `release-${cmd.version}.zip`);

  fs.mkdirSync(stagingDir, { recursive: true });

  await client.reportUpdateStatus({ jobId: cmd.jobId, status: 'downloading' });
  const { sha256, signature } = await client.downloadRelease(cmd.version, zipPath);

  if (signingKey && signature && !client.verifySignature(sha256, signature, signingKey)) {
    throw new Error('Assinatura do artefato inválida');
  }

  await client.reportUpdateStatus({ jobId: cmd.jobId, status: 'applying', logs: 'Extraindo artefato...' });

  const extractDir = path.join(stagingDir, 'extracted');
  fs.rmSync(extractDir, { recursive: true, force: true });
  fs.mkdirSync(extractDir, { recursive: true });

  if (process.platform === 'win32') {
    execSync(`tar -xf "${zipPath}" -C "${extractDir}"`, { stdio: 'inherit' });
  } else {
    execSync(`unzip -oq "${zipPath}" -d "${extractDir}"`, { stdio: 'inherit' });
  }

  const backupDir = path.join(stagingDir, `backup-${Date.now()}`);
  fs.mkdirSync(backupDir, { recursive: true });

  const preserveOnHost = new Set(['.env', '.docker', 'storage', '.git', '.stacker-update-staging']);
  const copyDirs = [
    'app',
    'bootstrap',
    'config',
    'database',
    'public',
    'routes',
    'vendor',
    'docker',
    'agent',
  ];
  const copyFiles = [
    'artisan',
    'VERSION',
    'composer.json',
    'composer.lock',
    'Dockerfile',
    'docker-compose.yml',
    'docker-compose.caddy.yml',
    'docker-compose.no-redis.yml',
    'install.sh',
    'update.sh',
  ];

  for (const dir of copyDirs) {
    const src = path.join(gatewayRoot, dir);
    if (fs.existsSync(src)) {
      fs.cpSync(src, path.join(backupDir, dir), { recursive: true });
    }
  }
  for (const file of copyFiles) {
    const src = path.join(gatewayRoot, file);
    if (fs.existsSync(src)) {
      fs.cpSync(src, path.join(backupDir, file));
    }
  }

  for (const entry of fs.readdirSync(extractDir)) {
    if (preserveOnHost.has(entry)) continue;
    const src = path.join(extractDir, entry);
    const dest = path.join(gatewayRoot, entry);
    if (fs.existsSync(dest)) {
      fs.rmSync(dest, { recursive: true, force: true });
    }
    fs.cpSync(src, dest, { recursive: true });
  }

  ensurePhpUploadsIni(gatewayRoot);
  ensureComposeProjectName(gatewayRoot);

  const applyScript = path.join(gatewayRoot, 'docker', 'stacker-apply-update.sh');
  if (!fs.existsSync(applyScript)) {
    throw new Error('docker/stacker-apply-update.sh ausente no release');
  }
  fs.chmodSync(applyScript, 0o755);

  let applyLogs = '';
  try {
    applyLogs = execSync(`bash "${applyScript}"`, {
      cwd: gatewayRoot,
      encoding: 'utf8',
      stdio: ['inherit', 'pipe', 'pipe'],
      env: { ...process.env, DOCKER_HOST: process.env.DOCKER_HOST || 'unix:///var/run/docker.sock' },
    });
  } catch (err) {
    const e = err as { stdout?: string; stderr?: string; message?: string };
    const logs = [e.stdout, e.stderr, e.message].filter(Boolean).join('\n');
    await client.reportUpdateStatus({
      jobId: cmd.jobId,
      status: 'failed',
      logs: logs || 'Falha ao aplicar update',
    });
    throw err;
  }

  await client.reportUpdateStatus({
    jobId: cmd.jobId,
    status: 'success',
    installedVersion: cmd.version,
    logs: applyLogs.trim() || `Update ${cmd.version} aplicado`,
  });

  fs.rmSync(stagingDir, { recursive: true, force: true });
  scheduleStackerAgentRestart(gatewayRoot);
}

const UPLOADS_INI = `upload_max_filesize = 512M
post_max_size = 512M
memory_limit = 512M
max_execution_time = 300
`;

function ensurePhpUploadsIni(gatewayRoot: string): void {
  const iniPath = path.join(gatewayRoot, 'docker', 'php', 'uploads.ini');
  if (fs.existsSync(iniPath)) {
    fs.rmSync(iniPath, { recursive: true, force: true });
  }
  fs.mkdirSync(path.dirname(iniPath), { recursive: true });
  fs.writeFileSync(iniPath, UPLOADS_INI, { encoding: 'utf8', mode: 0o644 });
  const stat = fs.statSync(iniPath);
  if (!stat.isFile()) {
    throw new Error('docker/php/uploads.ini não pôde ser criado como arquivo');
  }
}

function ensureComposeProjectName(gatewayRoot: string): void {
  const envPath = path.join(gatewayRoot, '.docker', 'stack.env');
  if (!fs.existsSync(envPath)) return;
  const content = fs.readFileSync(envPath, 'utf8');
  if (/^\s*GETFY_COMPOSE_PROJECT_NAME\s*=/m.test(content)) return;
  fs.appendFileSync(envPath, '\nGETFY_COMPOSE_PROJECT_NAME=getfy\n', 'utf8');
}

/** Reinicia o stacker-agent em background após reportar sucesso (evita matar o apply). */
function scheduleStackerAgentRestart(gatewayRoot: string): void {
  const cmd = [
    `cd "${gatewayRoot}"`,
    'set -a && . .docker/stack.env && set +a',
    'PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"',
    'FILES="$(sh docker/detect-compose-files.sh)"',
    'ARGS=""',
    'for f in $FILES; do ARGS="$ARGS -f $f"; done',
    'docker compose -p "$PROJECT" $ARGS --env-file .docker/stack.env build stacker-agent',
    'docker compose -p "$PROJECT" $ARGS --env-file .docker/stack.env up -d stacker-agent',
  ].join(' && ');
  spawn('bash', ['-c', cmd], { detached: true, stdio: 'ignore' }).unref();
}
