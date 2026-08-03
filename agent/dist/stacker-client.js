import * as crypto from 'node:crypto';
import { readInstalledVersion, readRuntimeVersion, invalidateRuntimeVersionCache } from './metrics.js';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { execSync, spawn } from 'node:child_process';
export class StackerClient {
    apiUrl;
    token;
    licensePath;
    constructor(apiUrl, token, licensePath) {
        this.apiUrl = apiUrl;
        this.token = token;
        this.licensePath = licensePath;
    }
    headers() {
        return {
            'Content-Type': 'application/json',
            'X-Stacker-Agent-Token': this.token,
        };
    }
    async request(method, path, body) {
        const res = await fetch(`${this.apiUrl}${path}`, {
            method,
            headers: this.headers(),
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!res.ok) {
            const text = await res.text();
            throw new Error(`API ${method} ${path}: ${res.status} ${text}`);
        }
        if (res.status === 204)
            return undefined;
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json'))
            return undefined;
        return res.json();
    }
    writeLicenseCache(license) {
        const dir = path.dirname(this.licensePath);
        fs.mkdirSync(dir, { recursive: true });
        const payload = { ...license, cachedAt: new Date().toISOString() };
        fs.writeFileSync(this.licensePath, JSON.stringify(payload, null, 2));
    }
    readLicenseCache() {
        try {
            return JSON.parse(fs.readFileSync(this.licensePath, 'utf8'));
        }
        catch {
            return null;
        }
    }
    async heartbeat(payload) {
        return this.request('POST', '/gateway/agent/heartbeat', payload);
    }
    async sendMetrics(metrics) {
        return this.request('POST', '/gateway/agent/metrics', metrics);
    }
    verifySignature(sha256, signature, signingKey) {
        const expected = crypto.createHmac('sha256', signingKey).update(sha256).digest('hex');
        try {
            return crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(signature, 'hex'));
        }
        catch {
            return false;
        }
    }
    async downloadRelease(version, destPath) {
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
    async reportUpdateStatus(data) {
        return this.request('POST', '/gateway/agent/update-status', {
            ...data,
            logs: data.logs != null ? truncateLogs(data.logs) : undefined,
        });
    }
}
/** Express default era 100kb — docker build estoura fácil. Mantém só o tail. */
const MAX_UPDATE_LOG_CHARS = 12_000;
function truncateLogs(logs) {
    const trimmed = logs.trim();
    if (trimmed.length <= MAX_UPDATE_LOG_CHARS)
        return trimmed;
    return `…(truncado)\n${trimmed.slice(-MAX_UPDATE_LOG_CHARS)}`;
}
export async function applyUpdate(client, cmd, gatewayRoot, signingKey) {
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
        await runShell(`tar -xf "${zipPath}" -C "${extractDir}"`, gatewayRoot);
    }
    else {
        await runShell(`unzip -oq "${zipPath}" -d "${extractDir}"`, gatewayRoot);
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
        if (preserveOnHost.has(entry))
            continue;
        const src = path.join(extractDir, entry);
        const dest = path.join(gatewayRoot, entry);
        if (fs.existsSync(dest)) {
            fs.rmSync(dest, { recursive: true, force: true });
        }
        fs.cpSync(src, dest, { recursive: true });
    }
    ensurePhpUploadsIni(gatewayRoot);
    ensureComposeProjectName(gatewayRoot);
    ensureHostDotEnv(gatewayRoot);
    await executeDockerApply(client, cmd.jobId, cmd.version, gatewayRoot);
    fs.rmSync(stagingDir, { recursive: true, force: true });
    scheduleStackerAgentRestart(gatewayRoot);
}
export async function reapplyUpdate(client, cmd, gatewayRoot) {
    await client.reportUpdateStatus({
        jobId: cmd.jobId,
        status: 'applying',
        logs: 'Reaplicando rebuild Docker (arquivos já na VPS)...',
    });
    await executeDockerApply(client, cmd.jobId, cmd.version, gatewayRoot);
    scheduleStackerAgentRestart(gatewayRoot);
}
async function executeDockerApply(client, jobId, targetVersion, gatewayRoot) {
    const applyScript = path.join(gatewayRoot, 'docker', 'stacker-apply-update.sh');
    if (!fs.existsSync(applyScript)) {
        throw new Error('docker/stacker-apply-update.sh ausente no release');
    }
    fs.chmodSync(applyScript, 0o755);
    await client.reportUpdateStatus({
        jobId,
        status: 'applying',
        logs: 'Executando docker/stacker-apply-update.sh (build pode levar 10–30 min)...',
    });
    let applyLogs = '';
    let keepaliveActive = true;
    const keepalive = setInterval(() => {
        if (!keepaliveActive)
            return;
        void client
            .reportUpdateStatus({
            jobId,
            status: 'applying',
            logs: applyLogs.trim().slice(-2000) || 'Apply em andamento (docker build)...',
        })
            .catch(() => undefined);
    }, 60_000);
    const stopKeepalive = () => {
        keepaliveActive = false;
        clearInterval(keepalive);
    };
    try {
        const result = await runShell(`bash "${applyScript}"`, gatewayRoot, {
            DOCKER_HOST: process.env.DOCKER_HOST || 'unix:///var/run/docker.sock',
        }, (chunk) => {
            applyLogs += chunk;
            if (applyLogs.length > 8000) {
                applyLogs = applyLogs.slice(-6000);
            }
        });
        // Não substituir pelo stdout/stderr completo (docker build pode ter MBs → 413).
        const joined = [result.stdout, result.stderr].filter(Boolean).join('\n').trim();
        if (joined) {
            applyLogs = joined.length > 8000 ? joined.slice(-6000) : joined;
        }
    }
    catch (err) {
        stopKeepalive();
        const e = err;
        const full = [e.stdout, e.stderr, e.message].filter(Boolean).join('\n');
        const logs = (full.length > 8000 ? full.slice(-6000) : full) ||
            applyLogs ||
            'Falha ao aplicar update';
        await client.reportUpdateStatus({
            jobId,
            status: 'failed',
            logs,
        });
        throw err;
    }
    stopKeepalive();
    // Pequena pausa para HTTP de keepalive em voo terminar antes do success.
    await new Promise((r) => setTimeout(r, 500));
    invalidateRuntimeVersionCache();
    const hostVersion = readInstalledVersion(gatewayRoot);
    const runtimeVersion = readRuntimeVersion(gatewayRoot);
    const versionAligned = hostVersion === targetVersion && runtimeVersion === targetVersion;
    if (!versionAligned) {
        const mismatchLog = [
            applyLogs.trim(),
            `Versão não alinhada após apply: host=${hostVersion ?? '?'}, runtime=${runtimeVersion ?? '?'}, alvo=${targetVersion}`,
        ]
            .filter(Boolean)
            .join('\n');
        await client.reportUpdateStatus({
            jobId,
            status: 'failed',
            logs: mismatchLog,
            installedVersion: hostVersion,
            runtimeVersion,
        });
        throw new Error(`Update ${targetVersion} incompleto: host=${hostVersion ?? '?'}, runtime=${runtimeVersion ?? '?'}`);
    }
    await client.reportUpdateStatus({
        jobId,
        status: 'success',
        installedVersion: targetVersion,
        runtimeVersion: runtimeVersion ?? targetVersion,
        logs: applyLogs.trim() || `Update ${targetVersion} aplicado`,
    });
}
const UPLOADS_INI = `upload_max_filesize = 512M
post_max_size = 512M
memory_limit = 512M
max_execution_time = 300
`;
function ensurePhpUploadsIni(gatewayRoot) {
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
function ensureComposeProjectName(gatewayRoot) {
    const envPath = path.join(gatewayRoot, '.docker', 'stack.env');
    if (!fs.existsSync(envPath))
        return;
    let content = fs.readFileSync(envPath, 'utf8');
    let changed = false;
    if (!/^\s*GETFY_COMPOSE_PROJECT_NAME\s*=/m.test(content)) {
        content += '\nGETFY_COMPOSE_PROJECT_NAME=getfy\n';
        changed = true;
    }
    if (!/^\s*GETFY_HOST_DIR\s*=/m.test(content)) {
        try {
            const hostDir = detectHostGatewayDir(gatewayRoot);
            if (hostDir) {
                content += `GETFY_HOST_DIR=${hostDir}\n`;
                changed = true;
            }
        }
        catch {
            // optional — stacker-apply-update.sh detecta via docker inspect
        }
    }
    if (changed)
        fs.writeFileSync(envPath, content, 'utf8');
}
function detectHostGatewayDir(gatewayRoot) {
    if (gatewayRoot !== '/gateway' && path.basename(gatewayRoot) !== 'gateway') {
        return gatewayRoot;
    }
    try {
        const out = execSync(`docker ps -q --filter 'name=stacker-agent' | head -1 | xargs -r docker inspect -f '{{range .Mounts}}{{if eq .Destination "/gateway"}}{{.Source}}{{end}}{{end}}'`, { encoding: 'utf8' }).trim();
        return out || null;
    }
    catch {
        return null;
    }
}
function ensureHostDotEnv(gatewayRoot) {
    const script = path.join(gatewayRoot, 'docker', 'ensure-host-dotenv.sh');
    if (fs.existsSync(script)) {
        execSync(`sh "${script}"`, { cwd: gatewayRoot, stdio: 'inherit' });
        return;
    }
    const stackEnvPath = path.join(gatewayRoot, '.docker', 'stack.env');
    const dotenvPath = path.join(gatewayRoot, '.env');
    if (!fs.existsSync(stackEnvPath) || (fs.existsSync(dotenvPath) && fs.statSync(dotenvPath).size > 0)) {
        return;
    }
    const stack = fs.readFileSync(stackEnvPath, 'utf8');
    const pick = (key, fallback = '') => {
        const m = stack.match(new RegExp(`^\\s*${key}\\s*=\\s*(.+)$`, 'm'));
        return m ? m[1].trim().replace(/^["']|["']$/g, '') : fallback;
    };
    const lines = [
        `GETFY_DB_CONNECTION=${pick('GETFY_DB_CONNECTION', 'pgsql')}`,
        `GETFY_DB_HOST=${pick('GETFY_DB_HOST', 'postgres')}`,
        `GETFY_DB_PORT=${pick('GETFY_DB_PORT', '5432')}`,
        `GETFY_DB_DATABASE=${pick('GETFY_DB_DATABASE', 'getfy')}`,
        `GETFY_DB_USERNAME=${pick('GETFY_DB_USERNAME', 'getfy')}`,
        `GETFY_DB_PASSWORD=${pick('GETFY_DB_PASSWORD', 'getfy')}`,
        `GETFY_APP_URL=${pick('GETFY_APP_URL', 'http://localhost')}`,
    ];
    fs.writeFileSync(dotenvPath, `${lines.join('\n')}\n`, { encoding: 'utf8', mode: 0o600 });
}
function runShell(command, cwd, extraEnv, onChunk) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, {
            cwd,
            shell: true,
            env: { ...process.env, ...extraEnv },
            stdio: ['inherit', 'pipe', 'pipe'],
        });
        let stdout = '';
        let stderr = '';
        child.stdout?.on('data', (chunk) => {
            const text = chunk.toString();
            stdout += text;
            onChunk?.(text);
        });
        child.stderr?.on('data', (chunk) => {
            const text = chunk.toString();
            stderr += text;
            onChunk?.(text);
        });
        child.on('error', reject);
        child.on('close', (code) => {
            if (code === 0) {
                resolve({ stdout, stderr });
                return;
            }
            const err = new Error(`Command failed: ${command}`);
            err.stdout = stdout;
            err.stderr = stderr;
            reject(err);
        });
    });
}
/** Reinicia o stacker-agent em background após reportar sucesso (evita matar o apply). */
function scheduleStackerAgentRestart(gatewayRoot) {
    const cmd = [
        `cd "${gatewayRoot}"`,
        'sh docker/ensure-host-dotenv.sh 2>/dev/null || true',
        'set -a && . .docker/stack.env && set +a',
        'PROJECT="${GETFY_COMPOSE_PROJECT_NAME:-getfy}"',
        'FILES="$(sh docker/detect-compose-files.sh)"',
        'ARGS=""',
        'for f in $FILES; do ARGS="$ARGS -f $f"; done',
        'docker compose -p "$PROJECT" --project-directory /gateway $ARGS --env-file /gateway/.docker/stack.env --env-file /gateway/.env build stacker-agent',
        'docker compose -p "$PROJECT" --project-directory /gateway $ARGS --env-file /gateway/.docker/stack.env --env-file /gateway/.env up -d stacker-agent',
    ].join(' && ');
    spawn('bash', ['-c', cmd], { detached: true, stdio: 'ignore' }).unref();
}
