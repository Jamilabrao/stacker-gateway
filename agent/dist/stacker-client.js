import * as crypto from 'node:crypto';
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
        return this.request('POST', '/gateway/agent/update-status', data);
    }
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
        execSync(`tar -xf "${zipPath}" -C "${extractDir}"`, { stdio: 'inherit' });
    }
    else {
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
    }
    catch (err) {
        const e = err;
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
    const content = fs.readFileSync(envPath, 'utf8');
    if (/^\s*GETFY_COMPOSE_PROJECT_NAME\s*=/m.test(content))
        return;
    fs.appendFileSync(envPath, '\nGETFY_COMPOSE_PROJECT_NAME=getfy\n', 'utf8');
}
/** Reinicia o stacker-agent em background após reportar sucesso (evita matar o apply). */
function scheduleStackerAgentRestart(gatewayRoot) {
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
