// Local-only integration check; uses generated one-day test credentials.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const https = require('node:https');
const crypto = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');

async function main() {
    const php = process.argv[2];
    const openssl = process.argv[3];
    if (!php || !openssl) throw new Error('Usage: node tools/check-finance-relay-tls.cjs <php.exe> <openssl.exe>');
    const root = path.resolve(__dirname, '..');
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'finance-relay-tls-'));
    const files = ['openssl.cnf', 'key.pem', 'cert.pem', 'check.php'];
    let server;
    try {
        fs.writeFileSync(path.join(temp, 'openssl.cnf'), '[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=finance-relay-local-test\n');
        const generated = spawnSync(openssl, ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-keyout', path.join(temp, 'key.pem'), '-out', path.join(temp, 'cert.pem'), '-config', path.join(temp, 'openssl.cnf')], {windowsHide: true});
        if (generated.status !== 0) throw new Error('Test certificate generation failed');
        const certificate = fs.readFileSync(path.join(temp, 'cert.pem'));
        const pin = new crypto.X509Certificate(certificate).fingerprint.replaceAll(':', '');
        let requests = 0;
        server = https.createServer({key: fs.readFileSync(path.join(temp, 'key.pem')), cert: certificate, minVersion:'TLSv1.2', maxVersion:'TLSv1.2'}, (req, res) => {
            requests++;
            let requestBody = '';
            req.on('data', chunk => requestBody += chunk);
            req.on('end', () => {
                const requestMessage = ['POST', '/relay/v1/paypal/read', req.headers['x-relay-timestamp'], req.headers['x-relay-nonce'], crypto.createHash('sha256').update(requestBody).digest('hex')].join('\n');
                if (crypto.createHmac('sha256', 'local-test-secret-for-tls-check-32').update(requestMessage).digest('hex') !== req.headers['x-relay-signature']) {
                    res.writeHead(401); res.end(); return;
                }
                const body = '{"balances":[]}';
                const timestamp = Math.floor(Date.now() / 1000).toString();
                if (req.socket.getProtocol() !== 'TLSv1.2') { res.writeHead(400); res.end(); return; }
                const message = ['200', timestamp, req.headers['x-relay-nonce'], crypto.createHash('sha256').update(body).digest('hex')].join('\n');
                res.writeHead(200, {'Content-Type': 'application/json', 'X-Relay-Response-Timestamp': timestamp, 'X-Relay-Response-Signature': crypto.createHmac('sha256', 'local-test-secret-for-tls-check-32').update(message).digest('hex')});
                res.end(body);
            });
        });
        server.on('tlsClientError', () => {});
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const port = server.address().port;
        const phpRoot = root.replaceAll('\\', '/').replaceAll("'", "\\'");
        fs.writeFileSync(path.join(temp, 'check.php'), `<?php
require '${phpRoot}/vendor/autoload.php';
$app=require '${phpRoot}/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
config(['finance.relay.ca_bundle'=>$argv[2]]);
$account=new App\\Models\\FinancePaymentAccount();
$account->forceFill(['account_type'=>'paypal','status'=>'enabled','relay_url'=>'https://127.0.0.1:${port}/relay/v1/paypal/read','relay_certificate_sha1'=>$argv[1],'relay_hmac_key_id'=>'local-test','relay_hmac_secret'=>base64_encode('local-test-secret-for-tls-check-32')]);
try { app(App\\Portals\\Finance\\FinanceRelayClient::class)->read($account,'balances'); echo 'VERIFIED'; }
catch (App\\Portals\\Finance\\FinanceRelayException $e) { echo 'REJECTED:'.$e->reason; }
`);
        function run(fingerprint) {
            return new Promise((resolve, reject) => {
                const child = spawn(php, [path.join(temp, 'check.php'), fingerprint, path.join(temp, 'cert.pem')], {cwd: root, windowsHide: true});
                let stdout = '';
                child.stdout.on('data', chunk => stdout += chunk);
                child.stderr.on('data', () => {});
                child.on('error', reject);
                child.on('close', code => code === 0 ? resolve(stdout.trim()) : reject(new Error('PHP local TLS check failed')));
            });
        }
        const valid = await run(pin);
        if (valid !== 'VERIFIED') throw new Error('Valid pinned TLS connection did not verify: ' + valid);
        if (await run('0'.repeat(40)) !== 'REJECTED:transport') throw new Error('Invalid certificate pin was not rejected');
        if (requests !== 1) throw new Error('An HTTP request was sent before certificate validation');
        process.stdout.write('TLS check passed: valid certificate verified; wrong pin rejected before HTTP.\n');
    } finally {
        if (server) await new Promise(resolve => server.close(resolve));
        for (const file of files) if (fs.existsSync(path.join(temp, file))) fs.unlinkSync(path.join(temp, file));
        fs.rmdirSync(temp);
    }
}
main().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
