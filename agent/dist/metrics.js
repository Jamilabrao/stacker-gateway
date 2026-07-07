import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';
import { execSync } from 'node:child_process';
let lastNetSample = null;
function readLinuxNetBytes() {
    try {
        const data = fs.readFileSync('/proc/net/dev', 'utf8');
        let rx = 0;
        let tx = 0;
        for (const line of data.split('\n').slice(2)) {
            const parts = line.trim().split(/\s+/);
            if (!parts[0] || parts[0].startsWith('lo:'))
                continue;
            rx += Number(parts[1] || 0);
            tx += Number(parts[9] || 0);
        }
        return { rx, tx };
    }
    catch {
        return null;
    }
}
function readDiskUsage(rootPath) {
    try {
        if (process.platform === 'win32') {
            return null;
        }
        const out = execSync(`df -k ${rootPath}`, { encoding: 'utf8' });
        const line = out.trim().split('\n')[1];
        if (!line)
            return null;
        const cols = line.split(/\s+/);
        const totalKb = Number(cols[1]);
        const usedKb = Number(cols[2]);
        const totalGb = totalKb / 1024 / 1024;
        const usedGb = usedKb / 1024 / 1024;
        const percent = totalKb > 0 ? (usedKb / totalKb) * 100 : 0;
        return { usedGb, totalGb, percent };
    }
    catch {
        return null;
    }
}
function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}
async function sampleCpuPercent() {
    if (process.platform !== 'linux')
        return undefined;
    try {
        const readSample = () => {
            const stat = fs.readFileSync('/proc/stat', 'utf8').split('\n')[0];
            const p = stat.split(/\s+/).slice(1).map(Number);
            const idle = p[3] + (p[4] || 0);
            const total = p.reduce((a, b) => a + b, 0);
            return { idle, total };
        };
        const s1 = readSample();
        await sleep(200);
        const s2 = readSample();
        const idleDelta = s2.idle - s1.idle;
        const totalDelta = s2.total - s1.total;
        if (totalDelta <= 0)
            return undefined;
        return Math.max(0, Math.min(100, ((totalDelta - idleDelta) / totalDelta) * 100));
    }
    catch {
        return undefined;
    }
}
export async function collectMetrics(gatewayRoot) {
    const totalMem = os.totalmem();
    const freeMem = os.freemem();
    const usedMem = totalMem - freeMem;
    const disk = readDiskUsage(gatewayRoot) ?? readDiskUsage('/');
    const net = readLinuxNetBytes();
    let networkInMbps;
    let networkOutMbps;
    if (net) {
        const now = Date.now();
        if (lastNetSample) {
            const dt = (now - lastNetSample.at) / 1000;
            if (dt > 0) {
                networkInMbps = ((net.rx - lastNetSample.rx) * 8) / dt / 1_000_000;
                networkOutMbps = ((net.tx - lastNetSample.tx) * 8) / dt / 1_000_000;
            }
        }
        lastNetSample = { ...net, at: now };
    }
    const cpuPercent = await sampleCpuPercent();
    return {
        cpuPercent,
        cpuCores: os.cpus().length,
        memoryUsedGb: usedMem / 1024 ** 3,
        memoryTotalGb: totalMem / 1024 ** 3,
        memoryPercent: totalMem > 0 ? (usedMem / totalMem) * 100 : undefined,
        diskUsedGb: disk?.usedGb,
        diskTotalGb: disk?.totalGb,
        diskPercent: disk?.percent,
        uptimeSeconds: Math.floor(os.uptime()),
        networkInMbps,
        networkOutMbps,
    };
}
export function readInstalledVersion(gatewayRoot) {
    const versionFile = path.join(gatewayRoot, 'VERSION');
    try {
        const v = fs.readFileSync(versionFile, 'utf8').trim();
        return v || undefined;
    }
    catch {
        return undefined;
    }
}
/** Versão reportada pelo container Laravel em execução (config getfy.version). */
let runtimeVersionCache = { at: 0 };
const RUNTIME_VERSION_CACHE_MS = 60_000;
export function readRuntimeVersion(gatewayRoot) {
    if (process.platform === 'win32')
        return undefined;
    if (Date.now() - runtimeVersionCache.at < RUNTIME_VERSION_CACHE_MS) {
        return runtimeVersionCache.value;
    }
    const value = readRuntimeVersionUncached(gatewayRoot);
    runtimeVersionCache = { value, at: Date.now() };
    return value;
}
function readRuntimeVersionUncached(gatewayRoot) {
    try {
        const container = execSync(`docker ps --format '{{.Names}}' 2>/dev/null | grep -E '-app-1$' | grep -v '^gateway-' | head -1`, { encoding: 'utf8', shell: '/bin/bash', timeout: 15_000 }).trim();
        if (!container)
            return undefined;
        const v = execSync(`docker exec ${container} php artisan tinker --execute="echo config('getfy.version');"`, { encoding: 'utf8', shell: '/bin/bash', timeout: 30_000 }).trim();
        return v || undefined;
    }
    catch {
        return undefined;
    }
}
