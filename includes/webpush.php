<?php
/**
 * webpush.php — odosielanie Web Push upozornení bez externej knižnice.
 *
 * Appka pôvodne počítala s knižnicou minishlink/web-push, ktorá sa ale musí
 * inštalovať cez Composer a vo vendor/ chýbala, takže sa žiadne upozornenie
 * neodoslalo. Tento súbor robí to isté len pomocou rozšírení openssl a curl,
 * ktoré sú súčasťou PHP na bežnom hostingu:
 *
 *  - obsah správy šifruje podľa RFC 8291 (schéma „aes128gcm"),
 *  - odosielateľa preukazuje VAPID tokenom podľa RFC 8292 (podpis ES256).
 *
 * Kľúče VAPID_PUBLIC_KEY a VAPID_PRIVATE_KEY sú tie isté ako doteraz (base64url
 * z includes/.env), nič sa nemení ani v prehliadačoch používateľov.
 */

function webpush_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpush_b64url_decode(string $data): string
{
    $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    return $decoded === false ? '' : $decoded;
}

/** Verejný kľúč P-256 (65 bajtov, nekomprimovaný bod) → kľúč pre openssl. */
function webpush_public_key(string $rawPoint)
{
    if (strlen($rawPoint) !== 65 || $rawPoint[0] !== "\x04") {
        throw new RuntimeException('Neplatný verejný kľúč (očakáva sa 65 bajtov).');
    }
    // Hlavička SubjectPublicKeyInfo pre krivku prime256v1 + samotný bod.
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPoint;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        throw new RuntimeException('Verejný kľúč sa nepodarilo načítať.');
    }
    return $key;
}

/** Súkromný kľúč P-256 (32 bajtov) + jeho verejný bod → kľúč pre openssl. */
function webpush_private_key(string $rawPrivate, string $rawPublicPoint)
{
    if (strlen($rawPrivate) !== 32 || strlen($rawPublicPoint) !== 65) {
        throw new RuntimeException('Neplatný VAPID kľúč (skontroluj VAPID_PUBLIC_KEY a VAPID_PRIVATE_KEY v .env).');
    }
    // Štruktúra ECPrivateKey (SEC 1) pre krivku prime256v1.
    $der = hex2bin('30770201010420') . $rawPrivate
        . hex2bin('a00a06082a8648ce3d030107a144034200') . $rawPublicPoint;
    $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        throw new RuntimeException('Súkromný VAPID kľúč sa nepodarilo načítať.');
    }
    return $key;
}

/** Podpis ECDSA z openssl (formát DER) → 64 bajtov R||S, ako vyžaduje JWT ES256. */
function webpush_signature_der_to_raw(string $der): string
{
    $offset = 2;
    if (ord($der[1]) & 0x80) {
        $offset += ord($der[1]) & 0x7f;
    }
    $raw = '';
    for ($i = 0; $i < 2; $i++) {
        $length = ord($der[$offset + 1]);
        $int = ltrim(substr($der, $offset + 2, $length), "\x00");
        $raw .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        $offset += 2 + $length;
    }
    return $raw;
}

/**
 * Hlavička Authorization pre push službu: VAPID token podpísaný naším súkromným
 * kľúčom. „aud" musí byť adresa push služby (schéma + host z endpointu).
 */
function webpush_vapid_authorization(string $endpoint, string $subject, string $publicKeyB64, string $privateKeyB64): string
{
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('Neplatná adresa push služby.');
    }
    $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $header = webpush_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = webpush_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,   // push služby dovolia najviac 24 hodín
        'sub' => $subject,
    ], JSON_UNESCAPED_SLASHES));

    $key = webpush_private_key(webpush_b64url_decode($privateKeyB64), webpush_b64url_decode($publicKeyB64));
    if (!openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID token sa nepodarilo podpísať.');
    }

    $jwt = $header . '.' . $claims . '.' . webpush_b64url_encode(webpush_signature_der_to_raw($signature));
    return 'vapid t=' . $jwt . ', k=' . $publicKeyB64;
}

/**
 * Zašifruje správu pre jedno zariadenie (RFC 8291, aes128gcm).
 * $p256dh a $auth sú kľúče zariadenia z push_subscriptions (base64url).
 * $ephemeralKey a $salt slúžia len na testovanie — bežne sa generujú náhodne.
 */
function webpush_encrypt(string $payload, string $p256dh, string $auth, $ephemeralKey = null, ?string $salt = null): string
{
    $clientPublic = webpush_b64url_decode($p256dh);
    $authSecret = webpush_b64url_decode($auth);
    if (strlen($authSecret) < 16) {
        throw new RuntimeException('Neplatný auth kľúč zariadenia.');
    }

    // Jednorazový pár kľúčov len pre túto správu.
    $ephemeralKey = $ephemeralKey ?: openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$ephemeralKey) {
        throw new RuntimeException('Nepodarilo sa vygenerovať kľúč pre šifrovanie.');
    }
    $details = openssl_pkey_get_details($ephemeralKey);
    $serverPublic = "\x04"
        . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $sharedSecret = openssl_pkey_derive(webpush_public_key($clientPublic), $ephemeralKey, 32);
    if ($sharedSecret === false) {
        throw new RuntimeException('Nepodarilo sa odvodiť spoločný kľúč.');
    }
    $sharedSecret = str_pad($sharedSecret, 32, "\x00", STR_PAD_LEFT);

    $salt = $salt ?? random_bytes(16);
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\x00" . $clientPublic . $serverPublic, $authSecret);
    $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // \x02 = oddeľovač výplne v poslednom (jedinom) zázname.
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('Šifrovanie správy zlyhalo.');
    }

    // Hlavička: soľ, veľkosť záznamu (4096), dĺžka a hodnota nášho verejného kľúča.
    return $salt . pack('N', 4096) . chr(strlen($serverPublic)) . $serverPublic . $ciphertext . $tag;
}

/**
 * Odošle jedno upozornenie na jedno zariadenie.
 * Návrat: ['status' => HTTP kód (0 = spojenie zlyhalo), 'error' => text chyby alebo null]
 * Kódy 404 a 410 znamenajú, že zariadenie odber zrušilo a záznam treba zmazať.
 */
function webpush_send(string $endpoint, string $p256dh, string $auth, string $payload, string $subject, string $vapidPublic, string $vapidPrivate): array
{
    try {
        if (strlen($payload) > 3900) {
            throw new RuntimeException('Správa je príliš dlhá.');
        }
        $body = webpush_encrypt($payload, $p256dh, $auth);
        $authorization = webpush_vapid_authorization($endpoint, $subject, $vapidPublic, $vapidPrivate);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Content-Length: ' . strlen($body),
                'TTL: 86400',            // push služba drží správu najviac deň, kým je zariadenie offline
                'Urgency: normal',
                'Authorization: ' . $authorization,
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 0, 'error' => 'spojenie zlyhalo: ' . $curlError];
        }
        if ($status < 200 || $status >= 300) {
            return ['status' => $status, 'error' => 'HTTP ' . $status . ' ' . mb_substr(trim((string) $response), 0, 200)];
        }
        return ['status' => $status, 'error' => null];
    } catch (Throwable $e) {
        return ['status' => 0, 'error' => $e->getMessage()];
    }
}
